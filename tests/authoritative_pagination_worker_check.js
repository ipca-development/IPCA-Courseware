#!/usr/bin/env node
"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const root = path.resolve(__dirname, "..");
const directory = fs.mkdtempSync(path.join(os.tmpdir(), "ipca-authoritative-pagination-"));
const inputPath = path.join(directory, "input.json");
const outputPath = path.join(directory, "output.json");
const paragraph = "Controlled source content must remain in source order. ".repeat(18);

const source = {
  layout_profile: "IPCA_READER_CANONICAL_816x1056_v1",
  layout_hash: "fixture-layout",
  layout: {
    page_width_px: 816,
    page_height_px: 1056,
    sheet_padding_x_px: 56,
    sheet_padding_top_px: 48,
    sheet_padding_bottom_px: 64,
    header_band_px: 84,
    header_margin_bottom_px: 20,
    footer_margin_top_px: 24,
    footer_band_px: 72,
    body_capacity_px: 744
  },
  header_template_html: '<header class="cpb-page-header"><strong>Fixture Manual</strong></header>',
  footer_template_html: '<footer class="cpb-page-footer"><span>Controlled copy</span><span class="cpb-page-number">{{PAGE_NUMBER}}</span></footer>',
  sections: [{
    id: 1,
    stable_anchor: "section-one",
    title: "Section One",
    section_key: "section-one",
    manual_part: 1,
    part_title: "Part One",
    flags: {},
    units: [
      {
        unit_key: "block-one",
        block_id: 1,
        stable_anchor: "block-one",
        block_type: "heading",
        html: '<article data-block-id="1" data-stable-anchor="block-one"><h2>First controlled heading</h2></article>'
      },
      {
        unit_key: "block-two",
        block_id: 2,
        stable_anchor: "block-two",
        block_type: "paragraph",
        html: `<article data-block-id="2" data-stable-anchor="block-two"><p>${paragraph}</p></article>`
      },
      {
        unit_key: "block-three",
        block_id: 3,
        stable_anchor: "block-three",
        block_type: "heading",
        force_break_before: true,
        manual_page_break_before: true,
        html: '<article data-block-id="3" data-stable-anchor="block-three"><h2>Manual break target</h2></article>'
      },
      {
        unit_key: "block-four",
        block_id: 4,
        stable_anchor: "block-four",
        block_type: "paragraph",
        html: `<article data-block-id="4" data-stable-anchor="block-four"><p>${paragraph}</p></article>`
      }
    ]
  }]
};

const bookStyleCSS = `
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; font-size: 16px; line-height: 1.45; }
  .cpb-page-header, .cpb-page-footer {
    display: flex; justify-content: space-between; align-items: center;
    width: 100%; height: 100%; border: 1px solid #aaa; padding: 8px;
  }
  article, h2, p { margin: 0; }
  h2 { font-size: 24px; line-height: 1.2; margin-bottom: 12px; }
  p { margin-bottom: 14px; }
`;

fs.writeFileSync(inputPath, JSON.stringify({ source, book_style_css: bookStyleCSS }));
const execution = spawnSync(process.execPath, [
  path.join(root, "scripts", "authoritative_manual_paginator.cjs"),
  "--input", inputPath,
  "--output", outputPath
], { encoding: "utf8", cwd: root, timeout: 120000 });

try {
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  const result = JSON.parse(fs.readFileSync(outputPath, "utf8"));
  assert.strictEqual(result.validation.is_valid, true, "final validation must pass");
  assert.ok(result.pages.length >= 2, "manual page break must create a later page");
  assert.deepStrictEqual(
    result.pages.map((page) => page.page_number),
    result.pages.map((_, index) => index + 1),
    "official page numbers must be sequential"
  );
  assert.ok(
    result.pages.every((page) =>
      page.page_html.includes("reader-page-header-region")
      && page.page_html.includes("reader-page-footer-region")
      && page.metrics.validation_passed === true
    ),
    "every final page must include fixed regions and pass geometry validation"
  );
  const breakTargetPage = result.pages.find((page) =>
    page.page_html.includes("Manual break target")
  );
  const openingPage = result.pages.find((page) =>
    page.page_html.includes("First controlled heading")
  );
  assert.ok(
    breakTargetPage && openingPage
      && breakTargetPage.page_number > openingPage.page_number,
    "manual break target must start on a later authoritative page"
  );
  const seen = result.pages.flatMap((page) =>
    page.coverage.filter((item) => item.presentation_copy !== true)
      .map((item) => item.source_fragment_id)
  );
  assert.strictEqual(new Set(seen).size, seen.length, "source fragments must not duplicate");
  process.stdout.write(`AUTHORITATIVE_PAGINATION_WORKER_PASS pages=${result.pages.length}\n`);
} finally {
  fs.rmSync(directory, { recursive: true, force: true });
}
