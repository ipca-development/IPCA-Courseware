<?php
declare(strict_types=1);

/**
 * Compliance Operating System — single access gate.
 *
 * Every /admin/compliance/* page MUST call compliance_require_access($pdo)
 * before rendering anything. Routing all compliance auth through this one
 * function gives us a single seam to upgrade later without touching the
 * shell pages.
 *
 * ACCESS POLICY (Phase 2.5):
 *   1. User must be logged in.
 *   2. User role must be 'admin' (delegates to cw_require_admin()).
 *   3. The admin's `users.is_compliance_admin` flag must be 1.
 *
 *   `is_compliance_admin` was introduced by:
 *     scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql
 *
 *   That migration defaults the column to 1 for every existing admin
 *   (and for new admins), so the practical effect today is "every admin
 *   has access" — exactly the policy the platform owner asked for.
 *   When a master admin is nominated, they can flip individual rows to 0
 *   from the future Compliance Settings page (Phase 2.5+ UI work).
 *
 * GRACEFUL DEGRADATION:
 *   If the migration has not been applied yet (column absent) the helper
 *   falls back to a role-only check rather than denying access. This makes
 *   it safe to deploy the PHP changes before the DDL is run.
 *
 * Returns:
 *   array — the current user row (id, email, name, role).
 *           Bails out via redirect() if not allowed.
 */
function compliance_require_access(?PDO $pdo = null): array
{
    if ($pdo === null) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }

    // cw_require_admin() redirects (and exits) on failure — on return we
    // are guaranteed to have a logged-in admin.
    cw_require_admin();

    $user = ($pdo instanceof PDO) ? cw_current_user($pdo) : null;
    $user = is_array($user) ? $user : [];

    if ((int)($user['id'] ?? 0) <= 0) {
        // Defensive: cw_require_admin() should already have handled this,
        // but if anything slipped through, force a re-login.
        if (function_exists('redirect')) {
            redirect('/login.php');
        }
        exit(0);
    }

    if (!compliance_user_has_access($user, $pdo)) {
        // Admin role but no compliance privilege — send them back to their
        // admin home instead of bouncing to login (they ARE still an admin).
        if (function_exists('redirect')) {
            redirect('/admin/dashboard.php');
        }
        exit(0);
    }

    return $user;
}

/**
 * Pure predicate variant of compliance_require_access() — useful in views
 * (e.g. the nav `visible` callback) that want to conditionally render
 * compliance-related affordances without triggering a redirect.
 *
 * @param array|null $user The current user row (must contain at least `id`
 *                         and `role`). Passing NULL returns false.
 * @param \PDO|null  $pdo  Optional PDO; defaults to $GLOBALS['pdo'].
 */
function compliance_user_has_access(?array $user, ?PDO $pdo = null): bool
{
    if (!is_array($user)) {
        return false;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role !== 'admin') {
        return false;
    }

    if ($pdo === null) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }

    if (!($pdo instanceof PDO)) {
        // We can't consult the per-admin flag without a DB handle. The
        // user is an admin and we cannot disprove access — allow.
        return true;
    }

    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    // The privilege column was added by phase 2.5. Read it defensively so
    // this helper degrades gracefully if the migration hasn't been
    // applied yet on this environment.
    try {
        $stmt = $pdo->prepare(
            "SELECT is_compliance_admin FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$uid]);
        $flag = $stmt->fetchColumn();
        if ($flag === false) {
            // User row vanished mid-request — deny.
            return false;
        }
        return (int)$flag === 1;
    } catch (Throwable $e) {
        // Most likely: "Unknown column 'is_compliance_admin'" on a DB
        // where the phase-2.5 migration hasn't been applied yet. The
        // role check above already confirms admin, so allow.
        return true;
    }
}
