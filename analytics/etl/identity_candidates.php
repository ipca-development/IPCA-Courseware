<?php
declare(strict_types=1);

/**
 * Candidate duplicate student identities.
 * Does NOT merge. Status remains CANDIDATE pending explicit approval.
 */

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "Usage: php identity_candidates.php <sqlite_path>\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$students = $pdo->query("
    SELECT student_id, source_user_id, first_name, last_name, email, dob, phone
    FROM dim_student
")->fetchAll(PDO::FETCH_ASSOC);

function norm_name(?string $s): string
{
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s;
}
function norm_email(?string $s): string
{
    return strtolower(trim((string)$s));
}
function norm_phone(?string $s): string
{
    return preg_replace('/\D+/', '', (string)$s) ?? '';
}

$buckets = [];
foreach ($students as $s) {
    $email = norm_email($s['email']);
    $phone = norm_phone($s['phone']);
    $dob = trim((string)$s['dob']);
    if ($dob === '0000-00-00') {
        $dob = '';
    }
    $nameKey = norm_name($s['last_name']) . '|' . norm_name($s['first_name']);

    $keys = [];
    if ($email !== '' && !str_contains($email, 'placeholder') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $keys[] = 'email:' . $email;
    }
    if ($phone !== '' && strlen($phone) >= 8) {
        $keys[] = 'phone:' . $phone;
    }
    if ($dob !== '' && $nameKey !== '|') {
        $keys[] = 'namedob:' . $nameKey . '|' . $dob;
    }
    // Exact same full name alone is a weak signal only — include but score lower later
    if ($nameKey !== '|' && strlen($nameKey) > 4) {
        $keys[] = 'name:' . $nameKey;
    }
    foreach ($keys as $k) {
        $buckets[$k][] = $s;
    }
}

// Union-find style grouping of source_user_ids that co-occur in strong buckets
$parent = [];
$find = function ($x) use (&$parent, &$find) {
    if (!isset($parent[$x])) {
        $parent[$x] = $x;
    }
    if ($parent[$x] !== $x) {
        $parent[$x] = $find($parent[$x]);
    }
    return $parent[$x];
};
$union = function ($a, $b) use (&$parent, $find) {
    $ra = $find($a);
    $rb = $find($b);
    if ($ra !== $rb) {
        $parent[$rb] = $ra;
    }
};

$strongSignals = [];
foreach ($buckets as $key => $list) {
    if (count($list) < 2) {
        continue;
    }
    $prefix = explode(':', $key, 2)[0];
    // name-only matches are weak and only considered if also another signal later
    if ($prefix === 'name') {
        continue;
    }
    $ids = array_values(array_unique(array_map(static fn($s) => (int)$s['source_user_id'], $list)));
    if (count($ids) < 2) {
        continue;
    }
    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i + 1; $j < count($ids); $j++) {
            $union($ids[$i], $ids[$j]);
            $pair = min($ids[$i], $ids[$j]) . '-' . max($ids[$i], $ids[$j]);
            $strongSignals[$pair][] = $key;
        }
    }
}

$groups = [];
foreach ($parent as $id => $_) {
    $root = $find($id);
    $groups[$root][] = $id;
}
$groups = array_values(array_filter($groups, static fn($g) => count($g) >= 2));

$pdo->exec('DELETE FROM bridge_student_identity');
$ins = $pdo->prepare('INSERT INTO bridge_student_identity (candidate_group_id, source_user_id, match_signals_json, match_score, status) VALUES (?,?,?,?,?)');
$groupId = 1;
foreach ($groups as $members) {
    sort($members);
    foreach ($members as $uid) {
        $signals = [];
        foreach ($members as $other) {
            if ($other === $uid) {
                continue;
            }
            $pair = min($uid, $other) . '-' . max($uid, $other);
            foreach ($strongSignals[$pair] ?? [] as $sig) {
                $signals[] = $sig;
            }
        }
        $signals = array_values(array_unique($signals));
        $score = 0.0;
        foreach ($signals as $sig) {
            $p = explode(':', $sig, 2)[0];
            $score += match ($p) {
                'email' => 3.0,
                'phone' => 2.5,
                'namedob' => 2.0,
                default => 0.5,
            };
        }
        $ins->execute([$groupId, $uid, json_encode($signals, JSON_UNESCAPED_SLASHES), $score, 'CANDIDATE']);
    }
    $groupId++;
}

echo "Candidate identity groups: " . ($groupId - 1) . "\n";
