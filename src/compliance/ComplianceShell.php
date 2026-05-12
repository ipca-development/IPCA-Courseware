<?php
declare(strict_types=1);

/**
 * Compliance Operating System — shell-page rendering helpers.
 *
 * Phase 2 scaffolds 20 admin pages under /admin/compliance/. Each page is
 * a thin shell that:
 *   1. Gates access via compliance_require_access().
 *   2. Calls cw_header() / cw_footer() so it inherits the platform layout.
 *   3. Renders ONE placeholder card describing what phase will fill it in.
 *
 * compliance_render_placeholder() centralises that card so every page is
 * visually consistent and so updating the placeholder design is one-file
 * work.
 */

/**
 * Render the standard Compliance OS "coming soon" placeholder card.
 *
 * @param string  $pageTitle       Human page title (also used as <h2>).
 * @param string  $phaseLabel      e.g. "Phase 5 — Audit Lifecycle".
 * @param string  $description     One- or two-sentence summary of the page.
 * @param array   $opts {
 *     @type string[] $tables_used     Compliance tables this page will read/write.
 *     @type string[] $bridges_used    Existing platform systems this page will reuse.
 *     @type string[] $bullets         Optional planned-capability bullets.
 * }
 */
function compliance_render_placeholder(string $pageTitle, string $phaseLabel, string $description, array $opts = []): void
{
    $tables  = isset($opts['tables_used'])  && is_array($opts['tables_used'])  ? $opts['tables_used']  : [];
    $bridges = isset($opts['bridges_used']) && is_array($opts['bridges_used']) ? $opts['bridges_used'] : [];
    $bullets = isset($opts['bullets'])      && is_array($opts['bullets'])      ? $opts['bullets']      : [];

    $titleEsc       = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $phaseEsc       = htmlspecialchars($phaseLabel, ENT_QUOTES, 'UTF-8');
    $descriptionEsc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="compliance-shell">
      <style>
        .compliance-shell{padding:24px 0;}
        .compliance-shell-card{
          background:#fff;
          border:1px solid #e2e8f0;
          border-radius:18px;
          padding:28px 32px;
          box-shadow:0 4px 18px rgba(15,23,42,.04);
          max-width:880px;
        }
        .compliance-shell-overline{
          font-size:11px;
          letter-spacing:.16em;
          text-transform:uppercase;
          color:#64748b;
          font-weight:800;
          margin-bottom:8px;
        }
        .compliance-shell-title{
          font-size:24px;
          font-weight:800;
          color:#0f172a;
          margin:0 0 6px 0;
        }
        .compliance-shell-phase{
          display:inline-block;
          background:#eef2ff;
          color:#3730a3;
          font-size:12px;
          font-weight:700;
          padding:4px 10px;
          border-radius:999px;
          margin-bottom:18px;
        }
        .compliance-shell-description{
          color:#334155;
          font-size:15px;
          line-height:1.55;
          margin:0 0 22px 0;
        }
        .compliance-shell-section{margin-top:18px;}
        .compliance-shell-section-label{
          font-size:11px;
          letter-spacing:.14em;
          text-transform:uppercase;
          color:#64748b;
          font-weight:800;
          margin-bottom:8px;
        }
        .compliance-shell-list{
          margin:0;
          padding:0;
          list-style:none;
          display:flex;
          flex-wrap:wrap;
          gap:6px 8px;
        }
        .compliance-shell-list li{
          background:#f1f5f9;
          color:#0f172a;
          font-size:12px;
          font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
          padding:4px 10px;
          border-radius:8px;
        }
        .compliance-shell-bullets{
          margin:0;
          padding:0 0 0 18px;
          color:#334155;
          font-size:14px;
          line-height:1.6;
        }
        .compliance-shell-footer{
          margin-top:24px;
          padding-top:16px;
          border-top:1px dashed #cbd5e1;
          color:#64748b;
          font-size:12px;
        }
      </style>

      <div class="compliance-shell-card">
        <div class="compliance-shell-overline">Compliance</div>
        <h2 class="compliance-shell-title"><?= $titleEsc ?></h2>
        <div class="compliance-shell-phase"><?= $phaseEsc ?></div>
        <p class="compliance-shell-description"><?= $descriptionEsc ?></p>

        <?php if ($bullets): ?>
          <div class="compliance-shell-section">
            <div class="compliance-shell-section-label">Planned capabilities</div>
            <ul class="compliance-shell-bullets">
              <?php foreach ($bullets as $bullet): ?>
                <li><?= htmlspecialchars((string)$bullet, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($tables): ?>
          <div class="compliance-shell-section">
            <div class="compliance-shell-section-label">Compliance tables in play</div>
            <ul class="compliance-shell-list">
              <?php foreach ($tables as $table): ?>
                <li><?= htmlspecialchars((string)$table, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($bridges): ?>
          <div class="compliance-shell-section">
            <div class="compliance-shell-section-label">Existing platform systems reused</div>
            <ul class="compliance-shell-list">
              <?php foreach ($bridges as $bridge): ?>
                <li><?= htmlspecialchars((string)$bridge, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="compliance-shell-footer">
          This is a Phase&nbsp;2 scaffold. The functional implementation lands in <?= $phaseEsc ?>.
        </div>
      </div>
    </section>
    <?php
}
