<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/GarminSyncAuthService.php';

cw_require_admin();
$admin = cw_current_user($pdo);
$adminId = (int)($admin['id'] ?? 0);

$csrf = (string)($_SESSION['garmin_sync_enrollment_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['garmin_sync_enrollment_csrf'] = $csrf;
}

function garmin_sync_admin_organization_id(PDO $pdo): int
{
    if (function_exists('cw_current_organization_id')) {
        try {
            $resolved = (int)cw_current_organization_id($pdo);
            if ($resolved > 0) {
                return $resolved;
            }
        } catch (Throwable) {
            // This repository's established single-organization fallback is organization 1.
        }
    }
    return 1;
}

$organizationId = garmin_sync_admin_organization_id($pdo);
$issued = null;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The form expired. Reload the page and try again.');
        }
        $issued = (new GarminSyncAuthService($pdo))->createEnrollmentCode(
            $organizationId,
            $adminId,
            60
        );
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

cw_header('Garmin Sync enrollment');
?>
<main style="max-width:760px;margin:0 auto;padding:24px;">
  <h1>Garmin Sync device enrollment</h1>
  <p>
    Generate a one-time code for the standalone Garmin Sync uploader.
    It expires after 60 minutes and cannot authenticate any CVR service.
  </p>

  <?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if (is_array($issued)): ?>
    <section style="padding:20px;border:2px solid #0b6;border-radius:8px;margin:20px 0;">
      <h2 style="margin-top:0;">Garmin Sync one-time enrollment code</h2>
      <p><strong>Copy this code now. It will not be shown again.</strong></p>
      <pre style="font-size:1.25rem;white-space:pre-wrap;overflow-wrap:anywhere;"><?= h((string)$issued['enrollment_code']) ?></pre>
      <p>Expires at <?= h((string)$issued['expires_at']) ?> UTC.</p>
    </section>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <button class="btn btn-primary" type="submit">Generate 60-minute Garmin Sync code</button>
  </form>
</main>
<?php cw_footer(); ?>
