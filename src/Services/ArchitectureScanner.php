<?php
declare(strict_types=1);

/**
 * IPCA Courseware - Repository Architecture Scanner
 *
 * Usage:
 *   $config  = require __DIR__ . '/../../config/courseware_architecture_ssot.php';
 *   $scanner = new ArchitectureScanner($repoRoot, $config);
 *   $report  = $scanner->scan();
 */

final class ArchitectureScanner
{
    private string $repoRoot;
    private array $config;
    private array $allFiles = [];
    private array $allDirs  = [];

    public function __construct(string $repoRoot, array $config)
    {
        $this->repoRoot = rtrim(str_replace('\\', '/', realpath($repoRoot) ?: $repoRoot), '/');
        $this->config   = $config;
    }

    public function scan(): array
    {
        $startedAt = gmdate('Y-m-d H:i:s');

        $this->indexRepository();

		$fileIntelligence = $this->extractFileIntelligence();
		
        $issues = [
            'critical' => [],
            'warning'  => [],
            'info'     => [],
        ];

        $componentResults = $this->scanComponents();
        $this->scanRequiredDirectories($issues);
        $this->scanRequiredFiles($issues);
        $this->scanForbiddenFiles($issues);
        $this->scanLargeFiles($issues);
        $this->scanSecrets($issues);
        $this->scanBrokenIncludes($issues);

        foreach ($componentResults as $componentKey => $componentResult) {
            if ($componentResult['status'] === 'missing') {
                $issues['critical'][] = sprintf(
                    'Architecture component missing: [%s] %s',
                    $componentKey,
                    $componentResult['label']
                );
            }
        }

        $status = 'OK';
        if (!empty($issues['critical'])) {
            $status = 'CRITICAL';
        } elseif (!empty($issues['warning'])) {
            $status = 'WARNING';
        }

        return [
            'project' => $this->config['project_name'] ?? 'Unknown Project',
            'status' => $status,
            'scanned_at_utc' => $startedAt,
            'repo_root' => $this->repoRoot,
            'summary' => [
                'file_count' => count($this->allFiles),
                'directory_count' => count($this->allDirs),
                'critical_count' => count($issues['critical']),
                'warning_count' => count($issues['warning']),
                'info_count' => count($issues['info']),
            ],
            'environment' => $this->config['environment'] ?? [],
            'components' => $componentResults,
            'issues' => $issues,
			'file_intelligence' => $fileIntelligence,
        ];
    }

    private function indexRepository(): void
    {
        $ignored = $this->normalizePaths($this->config['ignored_directories'] ?? []);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->repoRoot,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = $this->relativePath($absolutePath);

            if ($relativePath === '') {
                continue;
            }

            if ($this->isIgnored($relativePath, $ignored)) {
                continue;
            }

            if ($item->isDir()) {
                $this->allDirs[$relativePath] = true;
            } elseif ($item->isFile()) {
                $this->allFiles[$relativePath] = [
                    'path' => $relativePath,
                    'size' => $item->getSize(),
                ];
            }
        }
    }

    private function scanComponents(): array
    {
        $results = [];

        foreach (($this->config['components'] ?? []) as $key => $component) {
            $markers = $component['markers'] ?? [];
            $foundAt = [];

            foreach ($markers as $marker) {
                $marker = trim(str_replace('\\', '/', $marker), '/');

                if (isset($this->allFiles[$marker]) || isset($this->allDirs[$marker])) {
                    $foundAt[] = $marker;
                    continue;
                }

                foreach (array_keys($this->allFiles) as $filePath) {
                    if (stripos($filePath, $marker) !== false) {
                        $foundAt[] = $filePath;
                        break;
                    }
                }

                if (!empty($foundAt)) {
                    break;
                }

                foreach (array_keys($this->allDirs) as $dirPath) {
                    if (stripos($dirPath, $marker) !== false) {
                        $foundAt[] = $dirPath;
                        break;
                    }
                }

                if (!empty($foundAt)) {
                    break;
                }
            }

            $results[$key] = [
                'label' => $component['label'] ?? $key,
                'status' => empty($foundAt) ? 'missing' : 'ok',
                'found_at' => array_values(array_unique($foundAt)),
            ];
        }

        return $results;
    }

    private function scanRequiredDirectories(array &$issues): void
    {
        foreach (($this->config['required_directories'] ?? []) as $dir) {
            $dir = trim(str_replace('\\', '/', $dir), '/');
            if (!isset($this->allDirs[$dir])) {
                $issues['critical'][] = 'Missing required directory: ' . $dir;
            }
        }
    }

    private function scanRequiredFiles(array &$issues): void
    {
        foreach (($this->config['required_files'] ?? []) as $file) {
            $file = trim(str_replace('\\', '/', $file), '/');
            if (!isset($this->allFiles[$file])) {
                $issues['critical'][] = 'Missing required file: ' . $file;
            }
        }
    }

    private function scanForbiddenFiles(array &$issues): void
    {
        $patterns = $this->config['forbidden_file_patterns'] ?? [];

        foreach (array_keys($this->allFiles) as $filePath) {
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $filePath)) {
                    if (preg_match($pattern, $filePath)) {
                        $issues['warning'][] = 'Suspicious file committed to repo: ' . $filePath;
                        break;
                    }
                }
            }
        }
    }

    private function scanLargeFiles(array &$issues): void
    {
        $maxBytes = (int)($this->config['max_repo_file_size_bytes'] ?? 0);
        if ($maxBytes <= 0) {
            return;
        }

        foreach ($this->allFiles as $filePath => $meta) {
            if (($meta['size'] ?? 0) > $maxBytes) {
                $issues['warning'][] = sprintf(
                    'Large file in repo: %s (%s MB)',
                    $filePath,
                    number_format(($meta['size'] / 1024 / 1024), 2)
                );
            }
        }
    }

    private function scanSecrets(array &$issues): void
{
    $patterns = $this->config['secret_patterns'] ?? [];
    $extensions = $this->config['scannable_extensions'] ?? [];
    $excluded = $this->normalizePaths($this->config['secret_scan_excluded_files'] ?? []);

    foreach (array_keys($this->allFiles) as $filePath) {

        // 🔹 Skip excluded files
        if (in_array($filePath, $excluded, true)) {
            continue;
        }

        // 🔹 Skip vendor completely (too noisy + not relevant)
        if (strpos($filePath, 'vendor/') === 0) {
            continue;
        }

        if (!$this->hasAllowedExtension($filePath, $extensions)) {
            continue;
        }

        $content = $this->safeRead($this->repoRoot . '/' . $filePath);
        if ($content === null || $content === '') {
            continue;
        }

        foreach ($patterns as $label => $pattern) {
            if (@preg_match($pattern, '') === false) {
                continue;
            }

            if (preg_match($pattern, $content)) {
                $issues['critical'][] = sprintf(
                    'Possible secret detected (%s) in file: %s',
                    $label,
                    $filePath
                );
            }
        }
    }
}

private function scanBrokenIncludes(array &$issues): void
{
    $includeRegex = '/\b(?:require|require_once|include|include_once)\s*\(?\s*[\'"]([^\'"]+)[\'"]\s*\)?\s*;/i';

    foreach (array_keys($this->allFiles) as $filePath) {

        // 🔹 Skip vendor noise
        if (strpos($filePath, 'vendor/') === 0) {
            continue;
        }

        if (!preg_match('/\.php$/i', $filePath)) {
            continue;
        }

        $absolutePath = $this->repoRoot . '/' . $filePath;
        $content = $this->safeRead($absolutePath);

        if ($content === null || $content === '') {
            continue;
        }

        if (!preg_match_all($includeRegex, $content, $matches, PREG_SET_ORDER)) {
            continue;
        }

        $baseDir = dirname($absolutePath);

        foreach ($matches as $match) {
            $includeTarget = trim($match[1]);

            if ($includeTarget === '') {
                continue;
            }

            if (
                str_contains($includeTarget, '$') ||
                str_contains($includeTarget, '__DIR__') ||
                str_contains($includeTarget, 'dirname(')
            ) {
                continue;
            }

            $resolvedPath = realpath($baseDir . '/' . $includeTarget);

            if ($resolvedPath === false) {
                $issues['warning'][] = sprintf(
                    'Possibly broken include in %s -> %s',
                    $filePath,
                    $includeTarget
                );
            }
        }
    }
}

    private function relativePath(string $absolutePath): string
    {
        $absolutePath = str_replace('\\', '/', $absolutePath);
        if (strpos($absolutePath, $this->repoRoot) === 0) {
            return ltrim(substr($absolutePath, strlen($this->repoRoot)), '/');
        }
        return '';
    }

    private function normalizePaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $normalized[] = trim(str_replace('\\', '/', (string)$path), '/');
        }
        return $normalized;
    }

    private function isIgnored(string $relativePath, array $ignored): bool
    {
        foreach ($ignored as $ignore) {
            if ($ignore === '') {
                continue;
            }

            if ($relativePath === $ignore) {
                return true;
            }

            if (strpos($relativePath, $ignore . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function hasAllowedExtension(string $filePath, array $extensions): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, array_map('strtolower', $extensions), true);
    }

    private function safeRead(string $absolutePath): ?string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $size = filesize($absolutePath);
        if ($size !== false && $size > 2 * 1024 * 1024) {
            return null;
        }

        $content = @file_get_contents($absolutePath);
        return is_string($content) ? $content : null;
    }
	
	private function extractFileIntelligence(): array
{
    $results = [];

    foreach ($this->allFiles as $filePath => $meta) {

        // Only analyze PHP files for now (safe + efficient)
        if (!preg_match('/\.php$/i', $filePath)) {
            continue;
        }

        $absolute = $this->repoRoot . '/' . $filePath;
        $content = $this->safeRead($absolute);

        if ($content === null || $content === '') {
            continue;
        }

        $tables = [];
        $includes = [];
        $functions = [];
        $helpers = [];

        // 🔹 TABLE DETECTION (FROM, JOIN, UPDATE, INSERT INTO)
        if (preg_match_all('/\b(FROM|JOIN|UPDATE|INTO)\s+`?([a-zA-Z0-9_]+)`?/i', $content, $m)) {
            foreach ($m[2] as $t) {
                $tables[] = $t;
            }
        }

        // 🔹 INCLUDE / REQUIRE
        if (preg_match_all('/\b(require|include)(_once)?\s*\(?\s*[\'"]([^\'"]+)[\'"]\s*\)?/i', $content, $m)) {
            foreach ($m[3] as $inc) {
                $includes[] = $inc;
            }
        }

        // 🔹 FUNCTION DEFINITIONS
        if (preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\(/i', $content, $m)) {
            foreach ($m[1] as $fn) {
                $functions[] = $fn;
            }
        }

        // 🔹 HELPER USAGE (cw_*)
        if (preg_match_all('/\b(cw_[a-zA-Z0-9_]+)\s*\(/', $content, $m)) {
            foreach ($m[1] as $h) {
                $helpers[] = $h;
            }
        }

        // 🔹 MODULE CLASSIFICATION (safe heuristic)
        $module = 'general';

		if (strpos($filePath, 'student/') !== false) {
			$module = 'student';
		} elseif (strpos($filePath, 'admin/') !== false) {
			$module = 'admin';
		} elseif (strpos($filePath, 'instructor/') !== false) {
			$module = 'instructor';
		} elseif (strpos($filePath, 'api/') !== false) {
			$module = 'api';
		} elseif (strpos($filePath, 'src/') !== false) {
			$module = 'core';
}

        // 🔹 PURPOSE (very light heuristic — safe Phase 1)
        $purpose = 'General file';

        if (stripos($filePath, 'test_finalize') !== false) {
            $purpose = 'Handles progress test finalization';
        } elseif (stripos($filePath, 'summary') !== false) {
            $purpose = 'Handles summary logic';
        } elseif (stripos($filePath, 'course') !== false) {
            $purpose = 'Handles course progression / UI';
        }

        $results[$filePath] = [
            'tables' => array_values(array_unique($tables)),
            'includes' => array_values(array_unique($includes)),
            'functions' => array_values(array_unique($functions)),
            'helpers' => array_values(array_unique($helpers)),
            'module' => $module,
            'purpose' => $purpose,
        ];
    }

    return $results;
}
	
}