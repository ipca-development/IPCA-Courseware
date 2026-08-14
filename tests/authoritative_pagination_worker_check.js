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
const tocRows = Array.from({ length: 72 }, (_, index) =>
  `<div class="cpb-toc-row" data-stable-anchor="toc-${index + 1}">`
    + `<span>Section ${index + 1} — Controlled Operations</span><span>${index + 3}</span></div>`
).join("");
const listItems = Array.from({ length: 16 }, (_, index) =>
  `<li>Controlled list item ${index + 1} with semantic source coverage.</li>`
).join("");
const tableRows = Array.from({ length: 18 }, (_, index) =>
  `<tr><td>Row ${index + 1}</td><td>Controlled table row content</td></tr>`
).join("");

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
  header_template_html: '<header class="cpb-page-header"><div style="height:96px"><strong>Fixture Manual</strong></div></header>',
  footer_template_html: '<footer class="cpb-page-footer"><span>Controlled copy</span><span class="cpb-page-number">{{PAGE_NUMBER}}</span></footer>',
  sections: [{
    id: 2,
    section_id: 2,
    stable_anchor: "table-of-contents",
    title: "Table of Contents",
    section_key: "toc",
    manual_part: 0,
    part_title: "Front Matter",
    flags: { force_page_break_before: true },
    units: [{
      unit_key: "toc-2-0",
      block_id: 0,
      stable_anchor: "table-of-contents",
      block_type: "toc",
      force_break_before: true,
      html: `<section><h1>Table of Contents</h1><nav class="cpb-toc">${tocRows}</nav></section>`
    }]
  }, {
    id: 1,
    section_id: 1,
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
      },
      {
        unit_key: "block-note",
        block_id: 5,
        stable_anchor: "block-note",
        block_type: "callout",
        html: '<article class="cpb-block" data-block-id="5" data-block-type="callout" data-stable-anchor="block-note"><aside class="cpb-callout cpb-callout--note"><strong class="cpb-callout-title">NOTE</strong><div class="cpb-callout-text">This complete note fits on a fresh physical page and must move there intact when the preceding page has insufficient room.</div></aside></article>'
      },
      {
        unit_key: "block-list",
        block_id: 6,
        stable_anchor: "block-list",
        block_type: "list",
        html: `<article class="cpb-block" data-block-id="6" data-block-type="list" data-stable-anchor="block-list"><ol class="cpb-list">${listItems}</ol></article>`
      },
      {
        unit_key: "block-table",
        block_id: 7,
        stable_anchor: "block-table",
        block_type: "table",
        html: `<article class="cpb-block" data-block-id="7" data-block-type="table" data-stable-anchor="block-table"><table class="cpb-table"><thead><tr><th>Item</th><th>Description</th></tr></thead><tbody>${tableRows}</tbody></table></article>`
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
  .cpb-callout { border: 2px solid #0d9488; padding: 18px; margin: 16px 0; }
  .cpb-callout-title { display: block; margin-bottom: 10px; }
  .cpb-list { margin: 12px 0; }
  .cpb-list > li { min-height: 42px; }
  .cpb-table { width: 100%; border-collapse: collapse; }
  .cpb-table th, .cpb-table td { height: 64px; border: 1px solid #333; padding: 8px; }
  .cpb-toc-row {
    display: flex; justify-content: space-between; gap: 16px;
    min-height: 32px; padding: 6px 0; border-bottom: 1px solid #ddd;
  }
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
  assert.ok(
    result.authoritative_layout.headerFrame.height >= 96,
    "Book Style header content must expand the authoritative header frame"
  );
  assert.strictEqual(
    result.authoritative_layout.contentFrame.y,
    48 + result.authoritative_layout.headerFrame.height + 20,
    "body frame must begin after the measured header and configured margin"
  );
  assert.ok(result.pages.length >= 2, "manual page break must create a later page");
  assert.ok(
    result.pages.filter((page) => page.section_id === 2).length >= 3,
    "generated TOC rows must automatically paginate across physical pages"
  );
  assert.deepStrictEqual(
    result.pages.map((page) => page.page_number),
    result.pages.map((_, index) => index + 1),
    "official page numbers must be sequential"
  );
  assert.ok(
    result.pages.every((page) =>
      page.page_html.includes("reader-page-header-region")
      && page.page_html.includes("reader-page-footer-region")
      && page.page_html.includes("justify-content: flex-end")
      && page.metrics.validation_passed === true
    ),
    "every final page must include fixed regions, bottom-align its footer, and pass geometry validation"
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
  const noteCoverage = result.pages.flatMap((page) => page.coverage)
    .filter((item) => item.source_fragment_id.endsWith("/block-note/root"));
  assert.strictEqual(noteCoverage.length, 1, "a NOTE that fits a fresh page must remain intact");
  assert.strictEqual(noteCoverage[0].range_start, 0, "intact NOTE must begin at source offset zero");
  assert.strictEqual(
    noteCoverage[0].range_end,
    noteCoverage[0].source_length,
    "intact NOTE must cover its complete source range"
  );
  const listCoverage = result.pages.flatMap((page) => page.coverage)
    .filter((item) => item.source_fragment_id.includes("/block-list/li-"));
  assert.strictEqual(listCoverage.length, 16, "each list item must appear exactly once");
  const tableRowsCoverage = result.pages.flatMap((page) => page.coverage)
    .filter((item) => item.source_fragment_id.includes("/block-table/table-row-"));
  assert.strictEqual(tableRowsCoverage.length, 18, "each table row must appear exactly once");
  assert.ok(
    result.pages.flatMap((page) => page.coverage).some((item) =>
      item.source_fragment_id.endsWith("/block-table/table-header")
        && item.presentation_copy === true
    ),
    "continued tables must repeat the header as a presentation-only copy"
  );
  process.stdout.write(`AUTHORITATIVE_PAGINATION_WORKER_PASS pages=${result.pages.length}\n`);
} finally {
  fs.rmSync(directory, { recursive: true, force: true });
}
