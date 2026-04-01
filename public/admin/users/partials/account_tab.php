<?php
declare(strict_types=1);

$isPendingActivation = strtolower(trim((string)($user['status'] ?? ''))) === 'pending_activation';
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('users'); ?><span>Account Identity and Lifecycle</span></h3>

    <style>
        .ue-form-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }
        .ue-field{
            display:flex;
            flex-direction:column;
            gap:7px;
            min-width:0;
        }
        .ue-field label{
            font-size:12px;
            font-weight:670;
            letter-spacing:.02em;
            color:var(--text-muted);
        }
        .ue-field--full{
            grid-column:1 / -1;
        }
        .ue-input,
        .ue-select{
            width:100%;
            max-width:100%;
            height:44px;
            border-radius:14px;
            box-sizing:border-box;
            padding:0 14px;
        }
        .ue-checkbox{
            display:inline-flex;
            align-items:center;
            gap:10px;
            font-size:14px;
            font-weight:600;
            color:var(--text-strong);
        }
        .ue-checkbox input{
            width:16px;
            height:16px;
            margin:0;
        }
        .ue-actions-row{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:18px;
        }
        .ue-btn{
            min-height:42px;
            padding:0 16px;
            border-radius:12px;
        }
        .ue-btn--warn{
            background:linear-gradient(180deg,#b87410 0%,#9a6208 100%);
            color:#fff;
            border-color:transparent;
            box-shadow:0 10px 22px rgba(154,98,8,0.18);
        }
        .ue-btn svg{
            width:15px;
            height:15px;
            flex:0 0 15px;
        }
        .ue-photo-block{
            display:flex;
            gap:14px;
            align-items:center;
            flex-wrap:wrap;
        }
        .ue-photo-preview{
            width:72px;
            height:72px;
            border-radius:20px;
            overflow:hidden;
            background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);
            border:1px solid rgba(15,23,42,0.07);
            display:flex;
            align-items:center;
            justify-content:center;
            box-shadow:inset 0 1px 0 rgba(255,255,255,0.45);
            flex:0 0 72px;
        }
        .ue-photo-preview img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .ue-photo-preview .fallback{
            width:28px;
            height:28px;
            color:#7b8aa0;
        }
        .ue-help{
            color:var(--text-muted);
            font-size:13px;
            line-height:1.6;
        }
        .ue-info-panel{
            margin-top:18px;
            padding:16px 18px;
            border:1px solid rgba(15,23,42,0.06);
            border-radius:16px;
            background:#fbfcfe;
        }
        .ue-info-grid{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:14px;
        }
        .ue-info-label{
            font-size:11px;
            line-height:1.15;
            text-transform:uppercase;
            letter-spacing:.12em;
            color:#8a97ab;
            font-weight:700;
        }
        .ue-info-value{
            margin-top:6px;
            color:var(--text-strong);
            font-size:14px;
            font-weight:630;
            word-break:break-word;
        }
        .ue-activation-panel{
            margin-top:18px;
            padding:18px;
            border:1px solid rgba(196,118,11,0.14);
            border-radius:18px;
            background:rgba(196,118,11,0.06);
        }
        .ue-activation-title{
            margin:0;
            font-size:16px;
            font-weight:760;
            letter-spacing:-0.02em;
            color:#8f5a07;
        }
        .ue-activation-text{
            margin:10px 0 0 0;
            color:#8f5a07;
            font-size:13px;
            line-height:1.65;
        }
        @media (max-width:900px){
            .ue-form-grid,
            .ue-info-grid{
                grid-template-columns:1fr;
            }
        }
    </style>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="form_section" value="account">
        <input type="hidden" name="tab" value="account">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

        <div class="ue-form-grid">
            <div class="ue-field">
                <label for="first_name">First Name</label>
                <input
                    class="app-input ue-input"
                    id="first_name"
                    type="text"
                    name="first_name"
                    value="<?php echo h((string)($user['first_name'] ?? '')); ?>"
                    required
                >
            </div>

            <div class="ue-field">
                <label for="last_name">Last Name</label>
                <input
                    class="app-input ue-input"
                    id="last_name"
                    type="text"
                    name="last_name"
                    value="<?php echo h((string)($user['last_name'] ?? '')); ?>"
                    required
                >
            </div>

            <div class="ue-field ue-field--full">
                <label for="email">Primary E-mail / Username</label>
                <input
                    class="app-input ue-input"
                    id="email"
                    type="email"
                    name="email"
                    value="<?php echo h((string)($user['email'] ?? '')); ?>"
                    required
                >
            </div>

            <div class="ue-field">
                <label for="role">Role</label>
                <select class="app-select ue-select" id="role" name="role">
                    <option value="admin"<?php echo (string)($user['role'] ?? '') === 'admin' ? ' selected' : ''; ?>>Admin</option>
                    <option value="supervisor"<?php echo (string)($user['role'] ?? '') === 'supervisor' ? ' selected' : ''; ?>>Instructor</option>
                    <option value="student"<?php echo (string)($user['role'] ?? '') === 'student' ? ' selected' : ''; ?>>Student</option>
                </select>
            </div>

            <div class="ue-field">
                <label for="status">Status</label>
                <select class="app-select ue-select" id="status" name="status">
                    <option value="pending_activation"<?php echo (string)($user['status'] ?? '') === 'pending_activation' ? ' selected' : ''; ?>>Pending Activation</option>
                    <option value="active"<?php echo (string)($user['status'] ?? '') === 'active' ? ' selected' : ''; ?>>Active</option>
                    <option value="locked"<?php echo (string)($user['status'] ?? '') === 'locked' ? ' selected' : ''; ?>>Locked</option>
                    <option value="retired"<?php echo (string)($user['status'] ?? '') === 'retired' ? ' selected' : ''; ?>>Retired</option>
                </select>
            </div>

            <div class="ue-field">
                <label for="account_valid_until">Account Valid Until</label>
                <input
                    class="app-input ue-input"
                    id="account_valid_until"
                    type="date"
                    name="account_valid_until"
                    value="<?php echo h((string)($user['account_valid_until'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field ue-field--full">
                <label>Photo</label>
                <div class="ue-photo-block">
                    <div class="ue-photo-preview">
                        <?php if (!empty($user['photo_path'])): ?>
                            <img src="<?php echo h((string)$user['photo_path']); ?>" alt="<?php echo h($displayName); ?>">
                        <?php else: ?>
                            <span class="fallback"><?php echo aue_svg('camera'); ?></span>
                        <?php endif; ?>
                    </div>

                    <div style="min-width:260px;flex:1 1 auto;">
                        <input
                            class="app-input ue-input"
                            style="padding-top:10px;height:auto;min-height:44px;"
                            id="photo"
                            type="file"
                            name="photo"
                            accept="image/jpeg,image/png,image/webp"
                        >
                        <div class="ue-help" style="margin-top:8px;">
                            Use JPG, PNG, or WEBP. This photo is used across admin and instructor operational views.
                        </div>
                    </div>
                </div>
            </div>

            <div class="ue-field ue-field--full">
                <label class="ue-checkbox">
                    <input
                        type="checkbox"
                        name="must_change_password"
                        value="1"<?php echo (int)($user['must_change_password'] ?? 0) === 1 ? ' checked' : ''; ?>
                    >
                    <span>Require password update on next successful sign-in</span>
                </label>
            </div>
        </div>

        <div class="ue-actions-row">
            <button class="app-btn app-btn-primary ue-btn ue-btn--primary" type="submit">
                <?php echo aue_svg('save'); ?>
                <span>Save Account</span>
            </button>

            <a class="app-btn app-btn-secondary ue-btn" href="/admin/users/index.php">
                <?php echo aue_svg('archive'); ?>
                <span>Back to Users</span>
            </a>
        </div>
    </form>

    <?php if ($isPendingActivation): ?>
        <div class="ue-activation-panel">
            <h4 class="ue-activation-title">Activation Pending</h4>
            <p class="ue-activation-text">
                This user is currently in pending activation state. Activating the account will switch the user to Active and send a secure onboarding email with a one-time password setup link.
            </p>

            <form method="post" style="margin-top:14px;">
                <input type="hidden" name="form_section" value="activate_user">
                <input type="hidden" name="tab" value="account">
                <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

                <div class="ue-actions-row" style="margin-top:0;">
                    <button class="app-btn ue-btn ue-btn--warn" type="submit" onclick="return confirm('Activate this user and send onboarding email?');">
                        <?php echo aue_svg('mail'); ?>
                        <span>Activate User &amp; Send Onboarding</span>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="ue-info-panel">
        <div class="ue-info-grid">
            <div>
                <div class="ue-info-label">Display Name</div>
                <div class="ue-info-value"><?php echo h($displayName); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Last Login</div>
                <div class="ue-info-value"><?php echo h(cw_dt_admin((string)($user['last_login_at'] ?? ''), $pdo, $userId)); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Password Changed</div>
                <div class="ue-info-value"><?php echo h(cw_dt_admin((string)($user['password_changed_at'] ?? ''), $pdo, $userId)); ?></div>
            </div>

            <div>
    			<div class="ue-info-label">Created</div>
    			<div class="ue-info-value"><?php echo h(cw_dt_admin((string)($user['created_at'] ?? ''), $pdo, $userId)); ?></div>
			</div>

            <div>
    			<div class="ue-info-label">Updated</div>
    			<div class="ue-info-value"><?php echo h(cw_dt_admin((string)($user['updated_at'] ?? ''), $pdo, $userId)); ?></div>
			</div>

            <div>
                <div class="ue-info-label">User UUID</div>
                <div class="ue-info-value"><?php echo h((string)($user['uuid'] ?? '—')); ?></div>
            </div>
        </div>
    </div>
</section>