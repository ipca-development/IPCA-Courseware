<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$stats = [
  'courses' => (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
  'lessons' => (int)$pdo->query("SELECT COUNT(*) FROM lessons")->fetchColumn(),
  'slides'  => (int)$pdo->query("SELECT COUNT(*) FROM slides")->fetchColumn(),
];

cw_header('Dashboard');
?>
<style>
  .dash-stack{
    display:flex;
    flex-direction:column;
    gap:22px;
  }
  .hero-card{
    padding:24px 26px;
  }
  .hero-eyebrow{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:#7b8ba3;
    font-weight:700;
    margin-bottom:10px;
  }
  .hero-title{
    margin:0;
    font-size:30px;
    line-height:1.05;
    letter-spacing:-0.03em;
    color:#152235;
  }
  .hero-sub{
    margin-top:10px;
    font-size:15px;
    color:#6f7f95;
    max-width:780px;
  }

  .metric-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(220px,1fr));
    gap:18px;
  }
  .metric-card{
    padding:22px 24px;
  }
  .metric-label{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:#7b8ba3;
    font-weight:700;
    margin-bottom:14px;
  }
  .metric-value{
    font-size:44px;
    line-height:1;
    letter-spacing:-0.04em;
    font-weight:800;
    color:#152235;
  }
  .metric-sub{
    margin-top:10px;
    font-size:14px;
    color:#728198;
  }

  .panel-grid{
    display:grid;
    grid-template-columns:1.2fr .8fr;
    gap:18px;
  }
  .panel-card{
    padding:22px 24px;
  }
  .panel-title{
    margin:0 0 12px 0;
    font-size:20px;
    line-height:1.1;
    letter-spacing:-0.02em;
    color:#152235;
  }
  .panel-text{
    font-size:15px;
    color:#6f7f95;
    line-height:1.55;
  }

  .next-list{
    display:flex;
    flex-direction:column;
    gap:10px;
    margin-top:14px;
  }
  .next-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:12px 14px;
    border-radius:14px;
    background:#f7f9fc;
    border:1px solid rgba(15,23,42,0.05);
  }
  .next-item strong{
    font-size:14px;
    color:#152235;
  }
  .next-item span{
    font-size:13px;
    color:#728198;
  }

  .empty-premium{
    padding:18px 18px;
    border-radius:16px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:14px;
  }

  @media (max-width: 1050px){
    .metric-grid,
    .panel-grid{
      grid-template-columns:1fr;
    }
  }
</style>

<div class="dash-stack">
  <div class="card hero-card">
    <div class="hero-eyebrow">Admin Workspace</div>
    <h2 class="hero-title">Courseware Control Center</h2>
    <div class="hero-sub">
      Manage the theory training structure, content build-up, and platform readiness from one central workspace.
    </div>
  </div>

  <div class="metric-grid">
    <div class="card metric-card">
      <div class="metric-label">Courses</div>
      <div class="metric-value"><?= $stats['courses'] ?></div>
      <div class="metric-sub">Program-level course structures currently in the system.</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Lessons</div>
      <div class="metric-value"><?= $stats['lessons'] ?></div>
      <div class="metric-sub">Instructional lessons available across all configured courseware.</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Slides</div>
      <div class="metric-value"><?= $stats['slides'] ?></div>
      <div class="metric-sub">Rendered visual learning pages currently stored in the platform.</div>
    </div>
  </div>

  <div class="panel-grid">
    <div class="card panel-card">
      <h3 class="panel-title">Recommended Next Steps</h3>
      <div class="panel-text">
        Continue building the training structure in the intended sequence so content generation and downstream cohort delivery remain clean and predictable.
      </div>

      <div class="next-list">
        <div class="next-item">
          <strong>Create or review course structures</strong>
          <span>Foundation</span>
        </div>
        <div class="next-item">
          <strong>Add and organize lesson architecture</strong>
          <span>Instructional layer</span>
        </div>
        <div class="next-item">
          <strong>Generate and refine slide content</strong>
          <span>Delivery layer</span>
        </div>
      </div>
    </div>

    <div class="card panel-card">
      <h3 class="panel-title">System Readiness</h3>
      <div class="empty-premium">
        Advanced admin insights (system health, blocked students, pending summary reviews, intervention activity) will surface here as the dashboard expands.
      </div>
    </div>
  </div>
</div>

<?php cw_footer(); ?>
