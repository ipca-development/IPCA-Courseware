<?php
declare(strict_types=1);

function save_architecture_snapshot(PDO $pdo, array $report): int
{
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO ai_architecture_snapshots
        (
            status,
            repo_root,
            scanned_at_utc,
            summary_json,
            environment_json,
            components_json,
            issues_json,
            file_intelligence_json,
            created_at,
            updated_at
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
    ");

    $stmt->execute([
        (string)($report['status'] ?? 'UNKNOWN'),
        (string)($report['repo_root'] ?? ''),
        (string)($report['scanned_at_utc'] ?? gmdate('Y-m-d H:i:s')),

        json_encode($report['summary'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($report['environment'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($report['components'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($report['issues'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($report['file_intelligence'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);

    $snapshotId = (int)$pdo->lastInsertId();

    // 🔥 INSERT FILE INTELLIGENCE
    if (!empty($report['file_intelligence']) && is_array($report['file_intelligence'])) {

        $stmt2 = $pdo->prepare("
            INSERT INTO ai_architecture_file_index
            (
                snapshot_id,
                file_path,
                module,
                purpose,
                tables_json,
                includes_json,
                functions_json,
                helpers_json,
                created_at
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");

        foreach ($report['file_intelligence'] as $path => $info) {

            // Skip vendor (keep DB clean)
            if (strpos($path, 'vendor/') === 0) {
                continue;
            }

            $stmt2->execute([
                $snapshotId,
                $path,
                (string)($info['module'] ?? 'general'),
                (string)($info['purpose'] ?? ''),

                json_encode($info['tables'] ?? []),
                json_encode($info['includes'] ?? []),
                json_encode($info['functions'] ?? []),
                json_encode($info['helpers'] ?? []),
            ]);
        }
    }

    $pdo->commit();

    return $snapshotId;
}

function load_latest_architecture_snapshot(PDO $pdo): ?array
{
    $stmt = $pdo->query("
        SELECT *
        FROM ai_architecture_snapshots
        ORDER BY id DESC
        LIMIT 1
    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}