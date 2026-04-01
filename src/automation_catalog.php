<?php
declare(strict_types=1);

/**
 * Automation catalog helpers
 *
 * Purpose:
 * - canonical category + event definition lookup
 * - grouped event options for admin UI
 * - stable fallback if DB seed is temporarily missing
 *
 * IMPORTANT:
 * - categories are for organization / UI / reporting only
 * - flow execution still relies on event_key
 */

if (!function_exists('automation_default_categories')) {
    function automation_default_categories(): array
    {
        return array(
            array(
                'category_key' => 'user_management',
                'label' => 'User Management',
                'description' => 'Registration, password resets, account lifecycle, and admin-created users.',
                'sort_order' => 10,
            ),
            array(
                'category_key' => 'theory_training',
                'label' => 'Theory Training',
                'description' => 'Progress tests, lesson summaries, theory deadlines, and theory progression events.',
                'sort_order' => 20,
            ),
            array(
                'category_key' => 'flight_training',
                'label' => 'Flight Training',
                'description' => 'Flight activity, instructor flight actions, practical milestones, and flight progression.',
                'sort_order' => 30,
            ),
            array(
                'category_key' => 'compliance',
                'label' => 'Compliance',
                'description' => 'Approvals, audit-sensitive actions, required reviews, and policy-linked workflow events.',
                'sort_order' => 40,
            ),
            array(
                'category_key' => 'scheduling',
                'label' => 'Scheduling',
                'description' => 'Bookings, planning, slot coordination, timing, and schedule-related automation.',
                'sort_order' => 50,
            ),
            array(
                'category_key' => 'system',
                'label' => 'System',
                'description' => 'Internal technical or system-level automation events.',
                'sort_order' => 60,
            ),
        );
    }
}

if (!function_exists('automation_default_event_definitions')) {
    function automation_default_event_definitions(): array
    {
        return array(
            array(
                'event_key' => 'progress_test_completed',
                'label' => 'Progress Test Completed',
                'description' => 'Triggered when a progress test is completed.',
                'category_key' => 'theory_training',
                'sort_order' => 10,
            ),
            array(
                'event_key' => 'progress_test_failed',
                'label' => 'Progress Test Failed',
                'description' => 'Triggered when a progress test is failed.',
                'category_key' => 'theory_training',
                'sort_order' => 20,
            ),
            array(
                'event_key' => 'progress_test_passed',
                'label' => 'Progress Test Passed',
                'description' => 'Triggered when a progress test is passed.',
                'category_key' => 'theory_training',
                'sort_order' => 30,
            ),
            array(
                'event_key' => 'progress_test_deadline_missed',
                'label' => 'Progress Test Deadline Missed',
                'description' => 'Triggered when the progress test deadline is missed.',
                'category_key' => 'theory_training',
                'sort_order' => 40,
            ),
            array(
                'event_key' => 'final_deadline_missed',
                'label' => 'Final Deadline Missed',
                'description' => 'Triggered when the final allowed deadline is missed.',
                'category_key' => 'theory_training',
                'sort_order' => 50,
            ),
            array(
                'event_key' => 'summary_checked',
                'label' => 'Summary Checked',
                'description' => 'Triggered when a lesson summary is reviewed or checked.',
                'category_key' => 'theory_training',
                'sort_order' => 60,
            ),
            array(
                'event_key' => 'summary_accepted',
                'label' => 'Summary Accepted',
                'description' => 'Triggered when a lesson summary is accepted.',
                'category_key' => 'theory_training',
                'sort_order' => 70,
            ),
            array(
                'event_key' => 'summary_needs_revision',
                'label' => 'Summary Needs Revision',
                'description' => 'Triggered when a lesson summary is returned for revision.',
                'category_key' => 'theory_training',
                'sort_order' => 80,
            ),
            array(
                'event_key' => 'instructor_decision_recorded',
                'label' => 'Instructor Decision Recorded',
                'description' => 'Triggered when an instructor records a progression-related decision.',
                'category_key' => 'theory_training',
                'sort_order' => 90,
            ),
            array(
                'event_key' => 'password_reset_requested',
                'label' => 'Password Reset Requested',
                'description' => 'Triggered when a user requests a password reset.',
                'category_key' => 'user_management',
                'sort_order' => 10,
            ),
            array(
                'event_key' => 'user_registered_public',
                'label' => 'Public Registration Received',
                'description' => 'Triggered when a public registration is submitted.',
                'category_key' => 'user_management',
                'sort_order' => 20,
            ),
            array(
                'event_key' => 'user_created_admin',
                'label' => 'User Created by Admin',
                'description' => 'Triggered when an administrator creates a user.',
                'category_key' => 'user_management',
                'sort_order' => 30,
            ),
        );
    }
}

if (!function_exists('automation_action_options')) {
    function automation_action_options(): array
    {
        return array(
            'send_email' => 'Send Email',
            'create_required_action' => 'Create Required Action',
            'log_event' => 'Log Event',
            'set_deadline_extension' => 'Set Deadline Extension',
            'notify_admin' => 'Notify Admin',
        );
    }
}

if (!function_exists('automation_condition_field_options')) {
    function automation_condition_field_options(): array
    {
        return array(
            'attempt_count' => 'Attempt Count',
            'score_pct' => 'Score (%)',
            'review_score' => 'Review Score',
            'timing_status' => 'Timing Status',
            'review_status' => 'Review Status',
            'decision_code' => 'Decision Code',
            'event_status' => 'Event Status',
            'user_role' => 'User Role',
            'cohort_id' => 'Cohort ID',
            'lesson_id' => 'Lesson ID',
        );
    }
}

if (!function_exists('automation_operator_options')) {
    function automation_operator_options(): array
    {
        return array(
            '=' => '=',
            '!=' => '!=',
            '>' => '>',
            '>=' => '>=',
            '<' => '<',
            '<=' => '<=',
            'contains' => 'contains',
            'not_contains' => 'does not contain',
            'in' => 'in',
            'not_in' => 'not in',
            'is_empty' => 'is empty',
            'is_not_empty' => 'is not empty',
        );
    }
}

if (!function_exists('automation_category_rows')) {
    function automation_category_rows(PDO $pdo, bool $activeOnly = true): array
    {
        try {
            $sql = "
                SELECT
                    id,
                    category_key,
                    label,
                    description,
                    sort_order,
                    is_active
                FROM automation_event_categories
            ";
            if ($activeOnly) {
                $sql .= " WHERE is_active = 1 ";
            }
            $sql .= " ORDER BY sort_order ASC, label ASC ";

            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

            if (is_array($rows) && count($rows) > 0) {
                return $rows;
            }
        } catch (Throwable $e) {
            // fall through to defaults
        }

        $defaults = automation_default_categories();
        $rows = array();
        foreach ($defaults as $idx => $row) {
            $rows[] = array(
                'id' => 0 - ($idx + 1),
                'category_key' => (string)$row['category_key'],
                'label' => (string)$row['label'],
                'description' => (string)$row['description'],
                'sort_order' => (int)$row['sort_order'],
                'is_active' => 1,
            );
        }

        return $rows;
    }
}

if (!function_exists('automation_event_definition_rows')) {
    function automation_event_definition_rows(PDO $pdo, bool $activeOnly = true): array
    {
        try {
            $sql = "
                SELECT
                    d.id,
                    d.event_key,
                    d.label,
                    d.description,
                    d.category_id,
                    d.sort_order,
                    d.is_active,
                    c.category_key,
                    c.label AS category_label,
                    c.sort_order AS category_sort_order
                FROM automation_event_definitions d
                INNER JOIN automation_event_categories c
                    ON c.id = d.category_id
            ";
            $where = array();
            if ($activeOnly) {
                $where[] = "d.is_active = 1";
                $where[] = "c.is_active = 1";
            }
            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }
            $sql .= " ORDER BY c.sort_order ASC, c.label ASC, d.sort_order ASC, d.label ASC ";

            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

            if (is_array($rows) && count($rows) > 0) {
                return $rows;
            }
        } catch (Throwable $e) {
            // fall through to defaults
        }

        $categories = automation_default_categories();
        $categoryMap = array();
        foreach ($categories as $row) {
            $categoryMap[(string)$row['category_key']] = $row;
        }

        $defaults = automation_default_event_definitions();
        $rows = array();
        foreach ($defaults as $idx => $row) {
            $catKey = (string)$row['category_key'];
            $cat = isset($categoryMap[$catKey]) ? $categoryMap[$catKey] : array(
                'category_key' => $catKey,
                'label' => ucfirst(str_replace('_', ' ', $catKey)),
                'sort_order' => 999,
            );

            $rows[] = array(
                'id' => 0 - ($idx + 1),
                'event_key' => (string)$row['event_key'],
                'label' => (string)$row['label'],
                'description' => (string)$row['description'],
                'category_id' => 0,
                'sort_order' => (int)$row['sort_order'],
                'is_active' => 1,
                'category_key' => (string)$cat['category_key'],
                'category_label' => (string)$cat['label'],
                'category_sort_order' => (int)$cat['sort_order'],
            );
        }

        usort($rows, function ($a, $b) {
            $cmp = ((int)$a['category_sort_order'] <=> (int)$b['category_sort_order']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string)$a['category_label'], (string)$b['category_label']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = ((int)$a['sort_order'] <=> (int)$b['sort_order']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)$a['label'], (string)$b['label']);
        });

        return $rows;
    }
}

if (!function_exists('automation_event_grouped_options')) {
    function automation_event_grouped_options(PDO $pdo, bool $activeOnly = true): array
    {
        $rows = automation_event_definition_rows($pdo, $activeOnly);
        $grouped = array();

        foreach ($rows as $row) {
            $categoryKey = (string)($row['category_key'] ?? 'system');
            if (!isset($grouped[$categoryKey])) {
                $grouped[$categoryKey] = array(
                    'category_key' => $categoryKey,
                    'category_label' => (string)($row['category_label'] ?? ucfirst(str_replace('_', ' ', $categoryKey))),
                    'items' => array(),
                );
            }

            $grouped[$categoryKey]['items'][] = array(
                'event_key' => (string)$row['event_key'],
                'label' => (string)$row['label'],
                'description' => (string)($row['description'] ?? ''),
            );
        }

        return array_values($grouped);
    }
}

if (!function_exists('automation_event_label_map')) {
    function automation_event_label_map(PDO $pdo, bool $activeOnly = false): array
    {
        $rows = automation_event_definition_rows($pdo, $activeOnly);
        $map = array();

        foreach ($rows as $row) {
            $map[(string)$row['event_key']] = (string)$row['label'];
        }

        return $map;
    }
}

if (!function_exists('automation_event_category_map')) {
    function automation_event_category_map(PDO $pdo, bool $activeOnly = false): array
    {
        $rows = automation_event_definition_rows($pdo, $activeOnly);
        $map = array();

        foreach ($rows as $row) {
            $map[(string)$row['event_key']] = array(
                'category_key' => (string)$row['category_key'],
                'category_label' => (string)$row['category_label'],
                'category_sort_order' => (int)($row['category_sort_order'] ?? 999),
            );
        }

        return $map;
    }
}

if (!function_exists('automation_flow_rows')) {
    function automation_flow_rows(PDO $pdo): array
    {
        $eventLabelMap = automation_event_label_map($pdo, false);
        $eventCategoryMap = automation_event_category_map($pdo, false);

        $stmt = $pdo->query("
            SELECT
                id,
                name,
                description,
                event_key,
                is_active,
                priority,
                created_at,
                updated_at
            FROM automation_flows
        ");

        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

        foreach ($rows as &$row) {
            $eventKey = (string)$row['event_key'];
            $cat = isset($eventCategoryMap[$eventKey]) ? $eventCategoryMap[$eventKey] : array(
                'category_key' => '',
                'category_label' => 'Uncategorized',
                'category_sort_order' => 999,
            );

            $row['event_label'] = isset($eventLabelMap[$eventKey]) ? $eventLabelMap[$eventKey] : $eventKey;
            $row['category_key'] = (string)$cat['category_key'];
            $row['category_label'] = (string)$cat['category_label'];
            $row['category_sort_order'] = (int)$cat['category_sort_order'];
        }
        unset($row);

        usort($rows, function ($a, $b) {
            $cmp = ((int)$a['category_sort_order'] <=> (int)$b['category_sort_order']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = strcmp((string)$a['category_label'], (string)$b['category_label']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = strcmp((string)$a['event_label'], (string)$b['event_label']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = ((int)$b['is_active'] <=> (int)$a['is_active']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = ((int)$a['priority'] <=> (int)$b['priority']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = strcmp((string)$a['name'], (string)$b['name']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int)$a['id'] <=> (int)$b['id']);
        });

        return $rows;
    }
}

if (!function_exists('automation_flow_rows_grouped')) {
    function automation_flow_rows_grouped(PDO $pdo): array
    {
        $rows = automation_flow_rows($pdo);
        $grouped = array();

        foreach ($rows as $row) {
            $categoryKey = (string)($row['category_key'] ?? '');
            if ($categoryKey === '') {
                $categoryKey = 'uncategorized';
            }

            if (!isset($grouped[$categoryKey])) {
                $grouped[$categoryKey] = array(
                    'category_key' => $categoryKey,
                    'category_label' => (string)($row['category_label'] ?? 'Uncategorized'),
                    'items' => array(),
                );
            }

            $grouped[$categoryKey]['items'][] = $row;
        }

        return array_values($grouped);
    }
}

if (!function_exists('automation_flow_detail')) {
    function automation_flow_detail(PDO $pdo, int $flowId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                id,
                name,
                description,
                event_key,
                is_active,
                priority,
                created_at,
                updated_at
            FROM automation_flows
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute(array($flowId));
        $flow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$flow) {
            return null;
        }

        $conditionsStmt = $pdo->prepare("
            SELECT
                id,
                flow_id,
                field_key,
                operator,
                value_text,
                value_number,
                sort_order
            FROM automation_flow_conditions
            WHERE flow_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $conditionsStmt->execute(array($flowId));
        $flow['conditions'] = $conditionsStmt->fetchAll(PDO::FETCH_ASSOC);

        $actionsStmt = $pdo->prepare("
            SELECT
                id,
                flow_id,
                action_key,
                config_json,
                sort_order
            FROM automation_flow_actions
            WHERE flow_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $actionsStmt->execute(array($flowId));
        $flow['actions'] = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

        return $flow;
    }
}