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
 * CURRENT BEHAVIOUR (Phase 2):
 *   - User must be logged in.
 *   - User role must be 'admin' (delegates to cw_require_admin()).
 *   - Every admin user is treated as compliance-privileged.
 *
 * PLANNED UPGRADE PATH:
 *   The Compliance Manager will always be an admin user, but it must be
 *   possible to grant or revoke Compliance OS access to individual admins.
 *   When that lands, this function is the ONLY place that needs to change.
 *
 *   The likely shape is one of:
 *     (a) a boolean column on `users` (e.g. `is_compliance_admin TINYINT(1)`)
 *         that defaults to 0, with a master-admin / "founder admin" who
 *         can flip it for other admins; or
 *     (b) a dedicated grant table (e.g. `ipca_compliance_admin_grants`)
 *         to support scoped privileges (audit-only, manual-only, monitor-only).
 *
 *   Both options are additive and out of scope for Phase 2 — they require
 *   a separate migration plus a privilege-management UI on the Compliance
 *   Settings page.
 *
 * Returns:
 *   array — the current user row (id, email, name, role).
 *           Bails out via cw_require_admin()'s redirect() if not allowed.
 */
function compliance_require_access(?PDO $pdo = null): array
{
    if ($pdo === null) {
        global $pdo;
    }

    // cw_require_admin() redirects (and exits) on failure — so on return
    // we are guaranteed to have a logged-in admin.
    cw_require_admin();

    $user = cw_current_user($pdo) ?? [];

    if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
        // Defensive: cw_require_admin() should already have handled this,
        // but if anything slipped through, force a re-login.
        if (function_exists('redirect')) {
            redirect('/login.php');
        }
        exit(0);
    }

    // TODO[compliance-privileges]: replace this blanket pass-through with
    //   a real check against users.is_compliance_admin (or an
    //   ipca_compliance_admin_grants table). Until that landscape exists,
    //   every admin gets full Compliance OS access — which is the policy
    //   the platform owner has explicitly chosen for the initial rollout.

    return $user;
}

/**
 * Pure predicate variant of compliance_require_access() — useful in views
 * that want to conditionally render compliance-related affordances without
 * triggering a redirect.
 */
function compliance_user_has_access(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));

    // Mirror compliance_require_access() exactly: admin = full access today.
    return $role === 'admin';
}
