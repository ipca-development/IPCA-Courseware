#!/usr/bin/env node
"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const root = path.resolve(__dirname, "..");
const directory = fs.mkdtempSync(path.join(os.tmpdir(), "ipca-media-section-"));
const inputPath = path.join(directory, "input.json");
const outputPath = path.join(directory, "output.json");
const image = Buffer.from(
  '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="700">'
    + '<rect width="500" height="700" fill="white" stroke="navy" stroke-width="8"/>'
    + '<text x="250" y="350" text-anchor="middle" font-size="30">ANNEX 10 FORM</text>'
    + '</svg>',
).toString("base64");

const source = {
  layout_profile: "IPCA_READER_CANONICAL_816x1056_v1",
  layout_hash: "media-section-fixture",
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
  sections: [{
    section_id: 10,
    stable_anchor: "OMM-ANNEX-10",
    title: "Dangerous Goods Occurrence Report",
    section_key: "annex_10",
    flags: { force_page_break_before: true, is_section_start: true },
    header_template: '<header class="cpb-page-header">OMM Annex 10</header>',
    footer_template: '<footer class="cpb-page-footer">Page {{PAGE_NUMBER}}</footer>',
    show_header_footer: true,
    content_mode: "units",
    units: [{
      unit_key: "OMM-ANNEX-10-IMAGE",
      block_id: 1010,
      stable_anchor: "OMM-ANNEX-10-IMAGE",
      block_type: "image",
      html: '<article class="cpb-block cpb-block--image" data-block-id="1010"'
        + ' data-block-type="image" data-stable-anchor="OMM-ANNEX-10-IMAGE">'
        + `<figure><img src="data:image/svg+xml;base64,${image}" alt="Annex 10 form"></figure>`
        + '</article>',
      splittable: false,
      atomic: true,
      force_break_before: true,
    }],
  }],
};

try {
  fs.writeFileSync(inputPath, JSON.stringify({
    source,
    book_style_css: `
      * { box-sizing: border-box; }
      body { font: 11pt/1.4 Arial, sans-serif; }
      figure { margin: 0; text-align: center; }
      figure img { display: block; max-width: 100%; max-height: 720px; margin: 0 auto; }
    `,
  }));
  const execution = spawnSync(process.execPath, [
    path.join(root, "scripts/authoritative_manual_paginator.cjs"),
    "--input", inputPath,
    "--output", outputPath,
  ], { encoding: "utf8", cwd: root, timeout: 120000 });
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);

  const result = JSON.parse(fs.readFileSync(outputPath, "utf8"));
  assert.strictEqual(result.validation && result.validation.is_valid, true);
  assert.strictEqual(result.pages.length, 1, "image-only section did not receive exactly one page");
  assert.strictEqual(result.pages[0].section_id, 10);
  assert.match(result.pages[0].page_html, /ANNEX-10-IMAGE/);
  assert.match(result.pages[0].page_html, /<img\b/);
  assert.ok(
    result.pages[0].coverage.some((entry) =>
      Number(entry.section_id) === 10
      && String(entry.source_fragment_id).includes("OMM-ANNEX-10-IMAGE")
    ),
    "image-only section is absent from authoritative page coverage",
  );
  console.log("Authoritative media-only section: PASS");
} finally {
  fs.rmSync(directory, { recursive: true, force: true });
}
