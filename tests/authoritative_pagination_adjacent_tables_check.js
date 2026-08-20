#!/usr/bin/env node
"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const root = path.resolve(__dirname, "..");
const directory = fs.mkdtempSync(path.join(os.tmpdir(), "ipca-adjacent-tables-"));
const inputPath = path.join(directory, "input.json");
const outputPath = path.join(directory, "output.json");

const table = (blockId, key, widths, cells) =>
  `<article class="cpb-block" data-block-id="${blockId}" data-block-type="table">`
    + '<div class="cpb-table-block"><div class="cpb-table-wrap">'
    + `<table class="cpb-table"><colgroup>${widths.map((width) =>
      `<col style="width:${width}%">`).join("")}</colgroup>`
    + `<tbody><tr>${cells.map((cell) => `<td>${cell}</td>`).join("")}</tr></tbody>`
    + `</table></div></div><span data-table-key="${key}"></span></article>`;

const source = {
  layout_profile: "IPCA_READER_CANONICAL_816x1056_v1",
  layout_hash: "adjacent-table-fixture",
  layout: {
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
  },
  header_template_html: '<header class="cpb-page-header">Fixture Manual</header>',
  footer_template_html: '<footer class="cpb-page-footer">Page {{PAGE_NUMBER}}</footer>',
  sections: [{
    id: 1,
    section_id: 1,
    stable_anchor: "adjacent-tables",
    title: "Adjacent tables",
    section_key: "adjacent-tables",
    manual_part: 1,
    part_title: "Part One",
    flags: {},
    header_template: '<header class="cpb-page-header">Fixture Manual</header>',
    footer_template: '<footer class="cpb-page-footer">Page {{PAGE_NUMBER}}</footer>',
    show_header_footer: true,
    units: [{
      unit_key: "three-column-table",
      block_id: 31,
      stable_anchor: "three-column-table",
      block_type: "table",
      html: table(31, "three", [33, 34, 33], ["A", "B", "C"]),
    }, {
      unit_key: "four-column-table",
      block_id: 32,
      stable_anchor: "four-column-table",
      block_type: "table",
      html: table(32, "four", [25, 25, 25, 25], ["Level 1", "Level 2", "Level 3", "Level 4"]),
    }],
  }],
};

try {
  fs.writeFileSync(inputPath, JSON.stringify({
    source,
    book_style_css: `
      * { box-sizing: border-box; }
      body { font: 16px/1.45 Arial, sans-serif; }
      .cpb-table { width: 100%; border-collapse: collapse; }
      .cpb-table th, .cpb-table td { height: 64px; border: 1px solid #333; padding: 8px; }
      .cpb-table-block { margin: 10px 0; }
      .cpb-table-wrap { width: 100%; max-width: 100%; }
    `,
    incremental: null,
  }));
  const execution = spawnSync(process.execPath, [
    path.join(root, "scripts/authoritative_manual_paginator.cjs"),
    "--input", inputPath,
    "--output", outputPath,
  ], { encoding: "utf8", cwd: root, timeout: 120000 });
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);

  const result = JSON.parse(fs.readFileSync(outputPath, "utf8"));
  const pageHTML = result.pages.map((page) => page.page_html).join("");
  assert.ok(pageHTML.includes('data-block-id="31"'), "first table shell was lost");
  assert.ok(pageHTML.includes('data-block-id="32"'), "second table shell was merged into the first");
  assert.ok(pageHTML.includes("Level 4"), "right-most column was lost");
  assert.ok(pageHTML.includes('style="width:25%"'), "four-column geometry was not retained");
  console.log("Authoritative adjacent tables: PASS");
  console.log("Separate authored tables retain independent columns and wrappers");
} finally {
  fs.rmSync(directory, { recursive: true, force: true });
}
