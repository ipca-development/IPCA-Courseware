#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { execFileSync, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const requireFromGarmin = createRequire(path.join(root, 'scripts/garmin/package.json'));
const { chromium, webkit } = requireFromGarmin('playwright');
const editorCSS = fs.readFileSync(
  path.join(root, 'public/assets/controlled_book_editor.css'),
  'utf8',
);
const readerCSS = fs.readFileSync(
  path.join(root, 'public/assets/manual_reader_content.css'),
  'utf8',
);
const iosDocumentSource = fs.readFileSync(
  path.join(
    root,
    'ipca-manual-reader-ios/IPCAManualReader/Services/ManualReaderAPIClient.swift',
  ),
  'utf8',
);

const phpFixture = String.raw`
require_once getcwd() . '/src/helpers.php';
require_once getcwd() . '/src/publishing/ControlledPublishingBookRenderer.php';
$payload = array(
    'has_title_row' => false,
    'has_header_row' => false,
    'headers' => array(),
    'rows' => array(array('', ''), array('Visible value', '')),
    'row_colspans' => array(array(1, 1), array(1, 1)),
    'col_widths' => array(240, 240),
);
$block = array(
    'id' => 7001,
    'block_type' => 'table',
    'stable_anchor' => 'empty-row-parity',
    'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
);
$renderer = new ControlledPublishingBookRenderer();
echo json_encode(array(
    'edit' => $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_EDIT),
    'read' => $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_READ),
), JSON_THROW_ON_ERROR);
`;
const fixture = JSON.parse(execFileSync(
  'php',
  ['-r', phpFixture],
  { cwd: root, encoding: 'utf8' },
));

assert.ok(!fixture.edit.includes('&nbsp;</td>'));
assert.equal((fixture.read.match(/&nbsp;<\/td>/g) || []).length, 3);
assert.match(iosDocumentSource, /\.mr-ios-frame/);
assert.match(iosDocumentSource, /\\\(pageHtml\)/);

const layout = {
  page_width_px: 816,
  page_height_px: 1056,
  sheet_padding_x_px: 56,
  sheet_padding_top_px: 48,
  sheet_padding_bottom_px: 64,
  header_band_px: 64,
  header_margin_bottom_px: 20,
  footer_margin_top_px: 24,
  footer_band_px: 34,
  body_capacity_px: 802,
};
const source = {
  layout_profile: 'IPCA_READER_CANONICAL_816x1056_v1',
  layout_hash: 'empty-row-parity',
  layout,
  header_template_html: '<header class="cpb-page-header"><div style="height:40px"><strong>Fixture Manual</strong></div></header>',
  footer_template_html: '<footer class="cpb-page-footer"><span>Page {{PAGE_NUMBER}}</span></footer>',
  sections: [{
    id: 1,
    section_id: 1,
    stable_anchor: 'empty-row-section',
    title: 'Empty row parity',
    section_key: 'part_1_chapter_1',
    manual_part: 1,
    part_title: 'Part One',
    flags: {},
    header_template: '<header class="cpb-page-header"><div style="height:40px"><strong>Fixture Manual</strong></div></header>',
    footer_template: '<footer class="cpb-page-footer"><span>Page {{PAGE_NUMBER}}</span></footer>',
    show_header_footer: true,
    units: [{
      unit_key: 'empty-row-table',
      block_id: 7001,
      stable_anchor: 'empty-row-parity',
      block_type: 'table',
      html: fixture.read,
    }],
  }],
};
const bookStyleCSS = `
  * { box-sizing: border-box; }
  html, body { margin: 0; font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.35; }
  .cpb-sheet { width: 816px; min-height: 1056px; padding: 48px 56px 64px; }
  .cpb-page-header, .cpb-page-footer {
    display: flex; justify-content: space-between; align-items: center;
    width: 100%; height: 100%; border: 1px solid #aaa; padding: 8px;
  }
  .cpb-page-header { margin-bottom: 20px; }
  .cpb-page-footer { margin-top: 24px; padding: 4px; }
  .cpb-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .cpb-table td { border: 1px solid #94a3b8; padding: 6px 8px; vertical-align: top; }
`;
const publicationCSS = `${readerCSS}\n${bookStyleCSS}`;

async function stable(page) {
  await page.evaluate(async () => {
    if (document.fonts?.ready) await document.fonts.ready;
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
}

async function rowHeights(page, rootSelector) {
  return page.locator(`${rootSelector} .cpb-table tbody tr`).evaluateAll((rows) =>
    rows.map((row) => Math.round(row.getBoundingClientRect().height * 1000) / 1000)
  );
}

let chromiumBrowser;
let webkitBrowser;
try {
  try {
    chromiumBrowser = await chromium.launch({ headless: true });
  } catch (bundledError) {
    chromiumBrowser = await chromium.launch({ headless: true, channel: 'chrome' }).catch(() => {
      throw bundledError;
    });
  }
  const workerDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'ipca-empty-row-parity-'));
  const inputPath = path.join(workerDirectory, 'input.json');
  const outputPath = path.join(workerDirectory, 'output.json');
  fs.writeFileSync(inputPath, JSON.stringify({
    source,
    book_style_css: publicationCSS,
  }));
  const execution = spawnSync(process.execPath, [
    path.join(root, 'scripts/authoritative_manual_paginator.cjs'),
    '--input', inputPath,
    '--output', outputPath,
  ], { cwd: root, encoding: 'utf8', timeout: 120000 });
  const result = fs.existsSync(outputPath)
    ? JSON.parse(fs.readFileSync(outputPath, 'utf8'))
    : null;
  fs.rmSync(workerDirectory, { recursive: true, force: true });
  assert.equal(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result, 'authoritative paginator did not produce output');
  assert.equal(result.error, undefined, JSON.stringify(result.error));
  assert.equal(result.validation?.is_valid, true, JSON.stringify(result.validation));
  assert.equal(result.pages.length, 1);
  const pageHTML = result.pages[0].page_html;
  assert.ok(pageHTML.includes('&nbsp;'), 'authoritative page_html lost empty-cell sentinel');

  const editorPage = await chromiumBrowser.newPage({
    viewport: { width: 816, height: 1056 },
  });
  await editorPage.setContent(
    '<!doctype html><html><head><meta charset="utf-8">'
      + `<style>${editorCSS}\n${bookStyleCSS}</style></head>`
      + `<body><div id="editor-source" class="cpb-sheet">${fixture.edit}</div></body></html>`,
  );
  await stable(editorPage);
  const editorHeights = await rowHeights(editorPage, '#editor-source');
  await editorPage.close();

  webkitBrowser = await webkit.launch({ headless: true });
  const iosPage = await webkitBrowser.newPage({
    viewport: { width: 816, height: 1056 },
  });
  await iosPage.setContent(
    '<!doctype html><html><head><meta charset="utf-8">'
      + `<style>${publicationCSS}`
      + 'html,body{margin:0;width:816px;height:1056px;overflow:hidden}'
      + '.mr-ios-frame{position:relative;width:816px;height:1056px;overflow:hidden}'
      + '</style></head><body><div class="mr-ios-frame">'
      + `${pageHTML}</div></body></html>`,
  );
  await stable(iosPage);
  const iosHeights = await rowHeights(iosPage, '.mr-ios-frame');
  await iosPage.close();

  assert.equal(editorHeights.length, 2);
  assert.equal(iosHeights.length, 2);
  assert.ok(
    Math.abs(editorHeights[0] - iosHeights[0]) <= 1,
    `empty row differs: editor=${editorHeights[0]}px iOS=${iosHeights[0]}px`,
  );
  assert.ok(
    Math.abs(editorHeights[1] - iosHeights[1]) <= 1,
    `populated row differs: editor=${editorHeights[1]}px iOS=${iosHeights[1]}px`,
  );
  console.log(
    `Empty table row parity: PASS (Editor ${editorHeights[0]}px, iOS/WebKit ${iosHeights[0]}px)`,
  );
} finally {
  if (webkitBrowser) await webkitBrowser.close();
  if (chromiumBrowser) await chromiumBrowser.close();
}
