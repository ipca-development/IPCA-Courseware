import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '../scripts/garmin/node_modules/playwright/index.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const php = `
require ${JSON.stringify(root + '/src/theory_studio/TheoryStructuredEditorUi.php')};
$state = [
  'template_id' => 2,
  'program_id' => 4,
  'name' => 'Text Left – Image Right',
  'template_name' => 'Text Left – Image Right',
  'version_number' => 1,
  'breadcrumbs' => [
    ['label' => 'Templates', 'href' => '#'],
    ['label' => 'Mechanics of Flight', 'href' => '#'],
    ['label' => 'Text Left – Image Right v1.0', 'href' => null],
  ],
  'navigator_items' => [
    ['id' => 1, 'number' => 'v1', 'title' => 'Version 1', 'active' => true, 'href' => '#'],
  ],
  'placeholders' => [
    [
      'placeholder_key' => 'body', 'type' => 'text', 'semantic_role' => 'body',
      'x' => 120, 'y' => 240, 'width' => 640, 'height' => 420, 'reading_order' => 1,
      'font_family' => 'Arial, Helvetica, sans-serif', 'font_size' => 28,
      'font_weight' => 400, 'text_color' => '#162235', 'line_height' => 1.35,
      'alignment' => 'left', 'vertical_alignment' => 'top', 'padding' => 16,
      'z_index' => 1,
    ],
    [
      'placeholder_key' => 'image', 'type' => 'image', 'semantic_role' => 'primary_image',
      'x' => 840, 'y' => 240, 'width' => 640, 'height' => 420, 'reading_order' => 2,
      'media_fit' => 'contain', 'z_index' => 2,
    ],
  ],
  'guides' => [
    ['id' => 1, 'orientation' => 'horizontal', 'position' => 450, 'is_locked' => false],
    ['id' => 2, 'orientation' => 'vertical', 'position' => 800, 'is_locked' => false],
  ],
  'values' => [],
  'readonly' => false,
];
echo TheoryStructuredEditorUi::editor('template', $state);
`;

const markup = execFileSync('php', ['-r', php], { encoding: 'utf8' });
const css = readFileSync(resolve(root, 'public/assets/theory-structured-editor.css'), 'utf8');
const js = readFileSync(resolve(root, 'public/assets/theory-structured-editor.js'), 'utf8');
const artifact = resolve(root, 'tests/artifacts/theory-editor/redesign.png');
mkdirSync(dirname(artifact), { recursive: true });

const browser = await chromium.launch({
  headless: true,
  executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
});

try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.stack || error.message));
  await page.setContent(`<style>${css}</style>${markup}`);
  await page.addScriptTag({ content: js });
  await page.waitForTimeout(100);

  const rootBox = await page.locator('#tseEditorRoot').boundingBox();
  const stageBox = await page.locator('#tseStage').boundingBox();
  const horizontalGuide = await page.locator('.tse-guide--horizontal').boundingBox();
  const toolBackground = await page.locator('[data-tse-add="text"]').evaluate(
    element => getComputedStyle(element).backgroundColor
  );

  const guideBefore = Number(await page.locator('.tse-guide--horizontal').getAttribute('style')
    .then(style => /top:\s*([0-9.]+)/.exec(style || '')?.[1] || 0));
  const guideBox = await page.locator('.tse-guide--horizontal').boundingBox();
  if (guideBox) {
    await page.mouse.move(guideBox.x + Math.min(100, guideBox.width / 2), guideBox.y);
    await page.mouse.down();
    await page.mouse.move(guideBox.x + Math.min(100, guideBox.width / 2), guideBox.y + 45);
    await page.mouse.up();
  }
  const guideAfter = Number(await page.locator('.tse-guide--horizontal').getAttribute('style')
    .then(style => /top:\s*([0-9.]+)/.exec(style || '')?.[1] || 0));

  const rulerBox = await page.locator('#tseRulerX').boundingBox();
  const guidesBeforeRulerDrag = await page.locator('.tse-guide').count();
  if (rulerBox && stageBox) {
    await page.mouse.move(rulerBox.x + rulerBox.width / 2, rulerBox.y + rulerBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(rulerBox.x + rulerBox.width / 2, stageBox.y + stageBox.height * .3);
    await page.mouse.up();
  }
  const guidesAfterRulerDrag = await page.locator('.tse-guide').count();

  await page.locator('[data-key="body"]').click({ position: { x: 40, y: 40 } });
  const contextVisible = await page.locator('#tseContextToolbar').isVisible();
  const inspectorHasGeometry = await page.locator('#tseInspector').getByText('Position & Size').isVisible();
  await page.screenshot({ path: artifact, fullPage: true });

  const result = {
    root_fills_viewport: rootBox?.height === 900,
    stage_ratio: stageBox ? Number((stageBox.width / stageBox.height).toFixed(4)) : 0,
    guide_height: horizontalGuide?.height ?? null,
    ribbon_is_light: toolBackground !== 'rgb(16, 43, 78)',
    existing_guide_moved: guideAfter !== guideBefore,
    ruler_created_guide: guidesAfterRulerDrag === guidesBeforeRulerDrag + 1,
    contextual_toolbar_visible: contextVisible,
    geometry_inspector_visible: inspectorHasGeometry,
    page_errors: errors,
    screenshot: artifact,
  };
  console.log(JSON.stringify(result, null, 2));
  if (
    !result.root_fills_viewport
    || Math.abs(result.stage_ratio - (16 / 9)) > 0.01
    || result.guide_height > 1.1
    || !result.ribbon_is_light
    || !result.existing_guide_moved
    || !result.ruler_created_guide
    || !result.contextual_toolbar_visible
    || !result.geometry_inspector_visible
    || errors.length > 0
  ) {
    process.exitCode = 1;
  }
} finally {
  await browser.close();
}
