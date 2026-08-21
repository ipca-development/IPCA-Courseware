<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

cw_header('Theory Templates');
theory_studio_emit_assets();

compliance_page_open(array(
    'overline' => 'Theory Training',
    'title' => 'Template Editor',
    'description' => 'Placeholder for the structured slide template system. This is not the legacy HTML slot template CRUD.',
));
?>
<div class="ts-page">
  <div class="ts-workspace-copy" style="margin:0 auto;">
    <p>Structured slide authoring will reuse the Form / Controlled Publishing block engine for flow layout (title, body, key points, and similar fields) on a 16:9 canvas.</p>
    <p>Theory templates themselves will be a separate free-positionable box layer — Text, Image, and Video frames with semantic roles such as title, subtitle, body, key points, definition, primary image, secondary image, diagram, caption, and video. Authors will fill content into those roles. That geometry is not the current Controlled Publishing flow layout.</p>
    <p class="ts-meta">The live overlay editor remains the screenshot hotspot tool for existing Private Pilot and Instrument Rating FAA material.</p>
  </div>
</div>
<?php
compliance_page_close();
cw_footer();
