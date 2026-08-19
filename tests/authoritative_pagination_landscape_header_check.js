#!/usr/bin/env node
"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const root = path.resolve(__dirname, "..");
const directory = fs.mkdtempSync(path.join(os.tmpdir(), "ipca-landscape-header-"));
const inputPath = path.join(directory, "input.json");
const outputPath = path.join(directory, "output.json");
const logo = "data:image/svg+xml;base64,"
  + Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="100">'
    + '<rect width="300" height="100" fill="#123456"/></svg>').toString("base64");
const header = '<header class="cpb-page-header">'
  + '<table class="cpb-page-header-table"><tr>'
  + `<td class="cpb-page-header-cell--left"><img class="cpb-page-header-logo" src="${logo}" `
  + 'style="max-height:96px"></td>'
  + '<td class="cpb-page-header-cell--center">Landscape Annex</td>'
  + '<td class="cpb-page-header-cell--right">Page 1</td>'
  + '</tr></table></header>';
const footer = '<footer class="cpb-page-footer">Controlled copy</footer>';
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
  body_capacity_px: 802
};
const source = {
  layout_profile: "LETTER_READER_v1",
  layout_hash: "landscape-header-fixture",
  layout,
  header_template_html: header,
  footer_template_html: footer,
  sections: [{
    section_id: 15,
    section_key: "annexes_annex_15a",
    stable_anchor: "ANNEX-15A",
    title: "Annex 15A",
    orientation: "landscape",
    flags: {},
    header_template: header,
    footer_template: footer,
    show_header_footer: true,
    units: [{
      unit_key: "ANNEX-15A-BLOCK-001",
      block_id: 1,
      stable_anchor: "ANNEX-15A-BLOCK-001",
      block_type: "paragraph",
      html: '<article class="cpb-block cpb-block--paragraph">'
        + "<p>*FT = Full Time</p><p>*PT = Part Time</p></article>"
    }]
  }]
};
const css = `
  * { box-sizing: border-box; }
  body { font: 11pt/1.45 Arial, sans-serif; }
  article, p { margin: 0; }
  .cpb-page-header, .cpb-page-footer { width: 100%; border: 1px solid #94a3b8; }
  .cpb-page-header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .cpb-page-header-cell--left { width: 30%; }
  .cpb-page-header-cell--center { width: 40%; text-align: center; }
  .cpb-page-header-cell--right { width: 30%; }
  .cpb-page-header-logo { display: block; max-width: 100%; height: auto; }
`;

try {
  fs.writeFileSync(inputPath, JSON.stringify({ source, book_style_css: css }));
  const execution = spawnSync(process.execPath, [
    path.join(root, "scripts/authoritative_manual_paginator.cjs"),
    "--input", inputPath,
    "--output", outputPath
  ], { cwd: root, encoding: "utf8", timeout: 120000 });
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  const result = JSON.parse(fs.readFileSync(outputPath, "utf8"));
  assert.strictEqual(result.validation.is_valid, true);
  assert.strictEqual(result.pages.length, 1);
  assert.strictEqual(result.pages[0].orientation, "landscape");
  assert.ok(
    result.pages[0].metrics.header_frame.height > 90,
    "landscape header frame used portrait-width logo geometry"
  );
  assert.strictEqual(
    result.pages[0].metrics.header_frame.height,
    result.authoritative_layout.landscapeHeaderHeight
  );
  process.stdout.write("Authoritative landscape header pagination: PASS\n");
} finally {
  fs.rmSync(directory, { recursive: true, force: true });
}
