<?php
declare(strict_types=1);

/**
 * Read-only verifier for the legacy Safety Management System SQL dump.
 *
 * The command deliberately emits aggregate JSON only. It never returns SQL
 * values, report text, attachment names, credentials, or source file paths.
 *
 * Usage:
 *   php scripts/safety/legacy_sms_preflight.php \
 *     --dump=/path/to/legacy.sql \
 *     --legacy-root=/path/to/legacy/php/package
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SMS_EXPECTED_COUNTS = array(
    'hazards' => 246,
    'categories' => 9,
    'undesirable_events' => 48,
    'reports' => 123,
    'map_ud_haz' => 139,
    'map_ud_or' => 153,
);

const SMS_TABLE_COLUMNS = array(
    'haz' => array(
        'haz_id', 'haz_name', 'haz_cat', 'haz_desc', 'haz_likely', 'haz_sev',
        'haz_res', 'haz_def', 'haz_d_likely', 'haz_d_sev', 'haz_d_res',
        'haz_ref', 'haz_com',
    ),
    'haz_cat' => array('cat_id', 'cat_name'),
    'map_ud_haz' => array('map_udhaz_id', 'map_udhaz_ud', 'map_udhaz_haz'),
    'map_ud_or' => array('map_udor_id', 'map_udor_ud', 'map_udor_or'),
    'ocr' => array(
        'or_id', 'or_title', 'or_issued', 'or_updated', 'or_description',
        'or_actions', 'or_mitigation', 'or_file',
    ),
    'ud' => array(
        'ud_id', 'ud_name', 'ud_issued', 'ud_cictt', 'ud_updated',
        'ud_state', 'ud_risk',
    ),
);

/**
 * @return list<string>
 */
function smsSplitStatements(string $sql): array
{
    $statements = array();
    $start = 0;
    $length = strlen($sql);
    $quote = null;
    $escaped = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quote === "'" && $char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
        } elseif ($char === ';') {
            $statements[] = substr($sql, $start, $i - $start + 1);
            $start = $i + 1;
        }
    }

    if (trim(substr($sql, $start)) !== '') {
        $statements[] = substr($sql, $start);
    }

    return $statements;
}

/**
 * @return list<list<?string>>
 */
function smsParseTuples(string $payload): array
{
    $rows = array();
    $row = array();
    $value = '';
    $length = strlen($payload);
    $depth = 0;
    $quoted = false;
    $valueWasQuoted = false;
    $escaped = false;

    $finishValue = static function (string $raw, bool $wasQuoted): ?string {
        if ($wasQuoted) {
            return $raw;
        }
        $trimmed = trim($raw);
        return strcasecmp($trimmed, 'NULL') === 0 ? null : $trimmed;
    };

    for ($i = 0; $i < $length; $i++) {
        $char = $payload[$i];
        if ($quoted) {
            if ($escaped) {
                $value .= match ($char) {
                    '0' => "\0",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'Z' => "\x1a",
                    default => $char,
                };
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === "'") {
                if ($i + 1 < $length && $payload[$i + 1] === "'") {
                    $value .= "'";
                    $i++;
                } else {
                    $quoted = false;
                    $valueWasQuoted = true;
                }
            } else {
                $value .= $char;
            }
            continue;
        }

        if ($char === "'") {
            $quoted = true;
            $valueWasQuoted = true;
        } elseif ($char === '(') {
            if ($depth === 0) {
                $row = array();
                $value = '';
                $valueWasQuoted = false;
            } else {
                $value .= $char;
            }
            $depth++;
        } elseif ($char === ')' && $depth > 0) {
            $depth--;
            if ($depth === 0) {
                $row[] = $finishValue($value, $valueWasQuoted);
                $rows[] = $row;
                $row = array();
                $value = '';
                $valueWasQuoted = false;
            } else {
                $value .= $char;
            }
        } elseif ($char === ',' && $depth === 1) {
            $row[] = $finishValue($value, $valueWasQuoted);
            $value = '';
            $valueWasQuoted = false;
        } elseif ($depth > 0) {
            $value .= $char;
        }
    }

    if ($quoted || $depth !== 0) {
        throw new RuntimeException('The SQL dump contains an unterminated INSERT value.');
    }

    return $rows;
}

/**
 * @return array<string,list<array<string,?string>>>
 */
function smsReadLegacyRows(string $sql): array
{
    $tables = array_fill_keys(array_keys(SMS_TABLE_COLUMNS), array());
    foreach (smsSplitStatements($sql) as $statement) {
        $matched = preg_match(
            '/INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s*'
                . '(?:\((.*?)\))?\s*VALUES\b/is',
            $statement,
            $match,
            PREG_OFFSET_CAPTURE
        );
        if ($matched !== 1) {
            continue;
        }

        $table = strtolower($match[1][0]);
        if (!array_key_exists($table, SMS_TABLE_COLUMNS)) {
            continue;
        }

        $columns = SMS_TABLE_COLUMNS[$table];
        if (isset($match[2]) && trim($match[2][0]) !== '') {
            preg_match_all('/`([^`]+)`/', $match[2][0], $columnMatches);
            if ($columnMatches[1] !== array()) {
                $columns = $columnMatches[1];
            }
        }

        $valuesAt = $match[0][1] + strlen($match[0][0]);
        foreach (smsParseTuples(substr($statement, $valuesAt)) as $tuple) {
            if (count($tuple) !== count($columns)) {
                throw new RuntimeException(
                    sprintf('Unexpected %s INSERT arity.', $table)
                );
            }
            /** @var array<string,?string> $row */
            $row = array_combine($columns, $tuple);
            $tables[$table][] = $row;
        }
    }

    return $tables;
}

function smsBlank(?string $value): bool
{
    if ($value === null) {
        return true;
    }
    $normalized = strtolower(trim($value));
    return in_array($normalized, array('', '-', '0', 'n/a', 'na', 'none', 'tbd'), true);
}

/**
 * @param list<array<string,?string>> $rows
 * @return array{rows:int,unique_pairs:int,duplicate_pair_groups:int,duplicate_rows:int}
 */
function smsDuplicatePairs(array $rows, string $left, string $right): array
{
    $pairs = array();
    foreach ($rows as $row) {
        $key = (string)$row[$left] . "\0" . (string)$row[$right];
        $pairs[$key] = ($pairs[$key] ?? 0) + 1;
    }
    $duplicateGroups = 0;
    $duplicateRows = 0;
    foreach ($pairs as $frequency) {
        if ($frequency > 1) {
            $duplicateGroups++;
            $duplicateRows += $frequency - 1;
        }
    }
    return array(
        'rows' => count($rows),
        'unique_pairs' => count($pairs),
        'duplicate_pair_groups' => $duplicateGroups,
        'duplicate_rows' => $duplicateRows,
    );
}

function smsValidCalendarDate(string $value): bool
{
    $value = trim($value);
    foreach (array('!j-n-Y', '!j/n/Y', '!Y-n-j', '!Y/n/j', '!j-n-y', '!j/n/y') as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        ) {
            return true;
        }
    }
    return false;
}

function smsValidUnixDate(?string $value): bool
{
    if ($value === null || preg_match('/^\d{9,11}$/', trim($value)) !== 1) {
        return false;
    }
    $year = (int)gmdate('Y', (int)$value);
    return $year >= 1990 && $year <= 2100;
}

/**
 * @param list<array<string,?string>> $rows
 * @return array{blank:int,malformed:int}
 */
function smsTextDateAudit(array $rows, string $column): array
{
    $blank = 0;
    $malformed = 0;
    foreach ($rows as $row) {
        $value = trim((string)($row[$column] ?? ''));
        if ($value === '') {
            $blank++;
        } elseif (!smsValidCalendarDate($value)) {
            $malformed++;
        }
    }
    return array('blank' => $blank, 'malformed' => $malformed);
}

/**
 * @param array<string,list<array<string,?string>>> $tables
 * @return array<string,array{records:int,fields:int,indicators:int}>
 */
function smsMojibakeAudit(array $tables): array
{
    $result = array();
    foreach ($tables as $table => $rows) {
        $records = 0;
        $fields = 0;
        $indicators = 0;
        foreach ($rows as $row) {
            $recordHit = false;
            foreach ($row as $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $count = preg_match_all(
                    '/\x{FFFD}|Ã.|Â.|â[\x{0080}-\x{00BF}]{1,2}/u',
                    $value
                );
                if (is_int($count) && $count > 0) {
                    $recordHit = true;
                    $fields++;
                    $indicators += $count;
                }
            }
            if ($recordHit) {
                $records++;
            }
        }
        $result[$table] = array(
            'records' => $records,
            'fields' => $fields,
            'indicators' => $indicators,
        );
    }
    return $result;
}

/**
 * @param list<array<string,?string>> $rows
 * @return array{referenced:int,missing:int,unsafe_reference:int}
 */
function smsPdfAudit(array $rows, ?string $legacyRoot): array
{
    $referenced = 0;
    $missing = 0;
    $unsafe = 0;

    foreach ($rows as $row) {
        $reference = trim((string)($row['or_file'] ?? ''));
        if ($reference === '') {
            continue;
        }
        $referenced++;
        if (
            str_contains($reference, "\0")
            || str_contains(str_replace('\\', '/', $reference), '../')
            || str_starts_with($reference, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $reference) === 1
            || filter_var($reference, FILTER_VALIDATE_URL) !== false
        ) {
            $unsafe++;
            $missing++;
            continue;
        }
        if ($legacyRoot === null) {
            $missing++;
            continue;
        }

        $relative = ltrim(str_replace('\\', '/', $reference), '/');
        $candidates = array(
            $legacyRoot . '/' . $relative,
            $legacyRoot . '/pdf/' . $relative,
            $legacyRoot . '/pdfs/' . $relative,
            $legacyRoot . '/uploads/' . $relative,
            $legacyRoot . '/documents/' . $relative,
        );
        $found = false;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missing++;
        }
    }

    return array(
        'referenced' => $referenced,
        'missing' => $missing,
        'unsafe_reference' => $unsafe,
    );
}

/**
 * @param list<array<string,?string>> $hazards
 * @return array<string,int>
 */
function smsHazardCompleteness(array $hazards): array
{
    $result = array(
        'blank_name' => 0,
        'blank_description' => 0,
        'blank_defences' => 0,
        'invalid_category' => 0,
        'invalid_risk_fields' => 0,
        'legacy_default_risk_profile' => 0,
        'default_or_incomplete_records' => 0,
    );
    foreach ($hazards as $hazard) {
        $blankName = smsBlank($hazard['haz_name'] ?? null);
        $blankDescription = smsBlank($hazard['haz_desc'] ?? null);
        $blankDefences = smsBlank($hazard['haz_def'] ?? null);
        $category = (int)($hazard['haz_cat'] ?? 0);
        $invalidCategory = $category <= 0;
        $likely = trim((string)($hazard['haz_likely'] ?? ''));
        $severity = strtoupper(trim((string)($hazard['haz_sev'] ?? '')));
        $defendedLikely = trim((string)($hazard['haz_d_likely'] ?? ''));
        $defendedSeverity = strtoupper(trim((string)($hazard['haz_d_sev'] ?? '')));
        $invalidRisk = preg_match('/^[1-5]$/', $likely) !== 1
            || preg_match('/^[A-E]$/', $severity) !== 1
            || preg_match('/^[1-5]$/', $defendedLikely) !== 1
            || preg_match('/^[A-E]$/', $defendedSeverity) !== 1;
        $defaultRisk = $likely === '1'
            && $severity === 'A'
            && $defendedLikely === '1'
            && $defendedSeverity === 'A';

        $result['blank_name'] += (int)$blankName;
        $result['blank_description'] += (int)$blankDescription;
        $result['blank_defences'] += (int)$blankDefences;
        $result['invalid_category'] += (int)$invalidCategory;
        $result['invalid_risk_fields'] += (int)$invalidRisk;
        $result['legacy_default_risk_profile'] += (int)$defaultRisk;
        $result['default_or_incomplete_records'] += (int)(
            $blankName || $blankDescription || $blankDefences
            || $invalidCategory || $invalidRisk || $defaultRisk
        );
    }
    return $result;
}

/**
 * @param list<array<string,?string>> $rows
 * @return array{exists:bool,flagged_blank:bool,blank_fields:int}
 */
function smsBlankReport96(array $rows): array
{
    foreach ($rows as $row) {
        if ((int)($row['or_id'] ?? 0) !== 96) {
            continue;
        }
        $blankFields = 0;
        foreach (array(
            'or_title', 'or_issued', 'or_updated', 'or_description',
            'or_actions', 'or_mitigation', 'or_file',
        ) as $column) {
            $blankFields += (int)(trim((string)($row[$column] ?? '')) === '');
        }
        return array(
            'exists' => true,
            'flagged_blank' => trim((string)($row['or_title'] ?? '')) === ''
                || trim((string)($row['or_description'] ?? '')) === '',
            'blank_fields' => $blankFields,
        );
    }
    return array('exists' => false, 'flagged_blank' => false, 'blank_fields' => 0);
}

/**
 * @param list<array<string,?string>> $rows
 * @param array<int,true> $validLeft
 * @param array<int,true> $validRight
 * @return array{zero_id:int,missing_nonzero_reference:int}
 */
function smsOrphanAudit(
    array $rows,
    string $left,
    string $right,
    array $validLeft,
    array $validRight
): array {
    $zero = 0;
    $missing = 0;
    foreach ($rows as $row) {
        $leftId = (int)($row[$left] ?? 0);
        $rightId = (int)($row[$right] ?? 0);
        if ($leftId === 0 || $rightId === 0) {
            $zero++;
        } elseif (!isset($validLeft[$leftId]) || !isset($validRight[$rightId])) {
            $missing++;
        }
    }
    return array('zero_id' => $zero, 'missing_nonzero_reference' => $missing);
}

$options = getopt('', array('dump:', 'legacy-root:', 'pretty'));
$dump = isset($options['dump']) ? trim((string)$options['dump']) : '';
$legacyRoot = isset($options['legacy-root'])
    ? rtrim(trim((string)$options['legacy-root']), '/')
    : null;

try {
    if ($dump === '' || !is_file($dump) || !is_readable($dump)) {
        throw new InvalidArgumentException('--dump must name a readable SQL file.');
    }
    if ($legacyRoot !== null && ($legacyRoot === '' || !is_dir($legacyRoot))) {
        throw new InvalidArgumentException('--legacy-root must name a readable directory.');
    }
    $sql = file_get_contents($dump);
    if ($sql === false) {
        throw new RuntimeException('Could not read the SQL dump.');
    }

    $tables = smsReadLegacyRows($sql);
    $actualCounts = array(
        'hazards' => count($tables['haz']),
        'categories' => count($tables['haz_cat']),
        'undesirable_events' => count($tables['ud']),
        'reports' => count($tables['ocr']),
        'map_ud_haz' => count($tables['map_ud_haz']),
        'map_ud_or' => count($tables['map_ud_or']),
    );
    $countChecks = array();
    foreach (SMS_EXPECTED_COUNTS as $name => $expected) {
        $countChecks[$name] = array(
            'expected' => $expected,
            'actual' => $actualCounts[$name],
            'pass' => $actualCounts[$name] === $expected,
        );
    }

    $hazardIds = array();
    foreach ($tables['haz'] as $row) {
        $hazardIds[(int)$row['haz_id']] = true;
    }
    $eventIds = array();
    foreach ($tables['ud'] as $row) {
        $eventIds[(int)$row['ud_id']] = true;
    }
    $reportIds = array();
    foreach ($tables['ocr'] as $row) {
        $reportIds[(int)$row['or_id']] = true;
    }

    $hazOrphans = smsOrphanAudit(
        $tables['map_ud_haz'],
        'map_udhaz_ud',
        'map_udhaz_haz',
        $eventIds,
        $hazardIds
    );
    $reportOrphans = smsOrphanAudit(
        $tables['map_ud_or'],
        'map_udor_ud',
        'map_udor_or',
        $eventIds,
        $reportIds
    );
    $issuedDates = smsTextDateAudit($tables['ocr'], 'or_issued');
    $updatedDates = smsTextDateAudit($tables['ocr'], 'or_updated');
    $udIssuedMalformed = 0;
    $udUpdatedMalformed = 0;
    foreach ($tables['ud'] as $row) {
        $udIssuedMalformed += (int)!smsValidUnixDate($row['ud_issued'] ?? null);
        $udUpdatedMalformed += (int)!smsValidUnixDate($row['ud_updated'] ?? null);
    }

    $report = array(
        'schema_version' => 1,
        'mode' => 'read_only_aggregate_preflight',
        'source' => array(
            'sha256' => hash('sha256', $sql),
            'bytes' => strlen($sql),
            'path_emitted' => false,
            'content_emitted' => false,
        ),
        'count_checks' => $countChecks,
        'orphan_mappings' => array(
            'map_ud_haz' => array(
                'expected_zero_id' => 19,
                'actual_zero_id' => $hazOrphans['zero_id'],
                'zero_id_pass' => $hazOrphans['zero_id'] === 19,
                'missing_nonzero_reference' => $hazOrphans['missing_nonzero_reference'],
            ),
            'map_ud_or' => array(
                'expected_zero_id' => 18,
                'actual_zero_id' => $reportOrphans['zero_id'],
                'zero_id_pass' => $reportOrphans['zero_id'] === 18,
                'missing_nonzero_reference' => $reportOrphans['missing_nonzero_reference'],
            ),
        ),
        'duplicate_relationships' => array(
            'map_ud_haz' => smsDuplicatePairs(
                $tables['map_ud_haz'],
                'map_udhaz_ud',
                'map_udhaz_haz'
            ),
            'map_ud_or' => smsDuplicatePairs(
                $tables['map_ud_or'],
                'map_udor_ud',
                'map_udor_or'
            ),
        ),
        'dates' => array(
            'reports_issued' => $issuedDates,
            'reports_updated' => $updatedDates,
            'undesirable_events_issued' => array('malformed' => $udIssuedMalformed),
            'undesirable_events_updated' => array('malformed' => $udUpdatedMalformed),
            'malformed_total' => $issuedDates['malformed']
                + $updatedDates['malformed']
                + $udIssuedMalformed
                + $udUpdatedMalformed,
        ),
        'mojibake' => smsMojibakeAudit($tables),
        'blank_report_96' => smsBlankReport96($tables['ocr']),
        'pdf_references' => smsPdfAudit($tables['ocr'], $legacyRoot),
        'hazards' => smsHazardCompleteness($tables['haz']),
        'credential_safety' => array(
            'legacy_php_credential_read' => false,
            'credential_emitted' => false,
            'external_rotation_required' => true,
        ),
    );
    $failedCountChecks = count(array_filter(
        $countChecks,
        static fn (array $check): bool => !$check['pass']
    ));
    $report['summary'] = array(
        'count_check_failures' => $failedCountChecks,
        'zero_id_orphan_mappings' => $hazOrphans['zero_id'] + $reportOrphans['zero_id'],
        'duplicate_relationship_rows' =>
            $report['duplicate_relationships']['map_ud_haz']['duplicate_rows']
            + $report['duplicate_relationships']['map_ud_or']['duplicate_rows'],
        'malformed_dates' => $report['dates']['malformed_total'],
        'mojibake_records' => array_sum(array_column($report['mojibake'], 'records')),
        'missing_pdf_references' => $report['pdf_references']['missing'],
        'default_or_incomplete_hazards' =>
            $report['hazards']['default_or_incomplete_records'],
        'migration_ready' => false,
    );

    $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    if (array_key_exists('pretty', $options)) {
        $flags |= JSON_PRETTY_PRINT;
    }
    fwrite(STDOUT, json_encode($report, $flags) . "\n");
} catch (Throwable $exception) {
    $error = array(
        'schema_version' => 1,
        'mode' => 'read_only_aggregate_preflight',
        'error' => get_class($exception),
        'message' => $exception->getMessage(),
        'credential_emitted' => false,
    );
    fwrite(
        STDOUT,
        json_encode($error, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    exit(1);
}
