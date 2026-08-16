#!/usr/bin/env node
"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const root = path.resolve(__dirname, "..");
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
const bookStyleCSS = `
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; font-size: 16px; line-height: 1.45; }
  .cpb-page-header, .cpb-page-footer {
    display: flex; justify-content: space-between; align-items: center;
    width: 100%; height: 100%; border: 1px solid #aaa; padding: 8px;
  }
  .cpb-sheet { width: 816px; padding: 48px 56px 64px; min-height: 1056px; }
  .cpb-page-header { margin-bottom: 20px; }
  .cpb-page-footer { margin-top: 24px; padding: 4px; }
  .cpb-dropzone {
    margin-top: 24px; border: 2px dashed #94a3b8; padding: 20px;
    position: absolute; right: 62px; bottom: 76px;
  }
  article, h2, h3, p { margin: 0; }
  figure { margin: 0; max-width: 100%; }
  figcaption { margin-top: 6px; font-size: 12px; }
  h2, h3 { font-size: 22px; line-height: 1.2; margin-bottom: 8px; }
  p { margin-bottom: 10px; }
  .tall-block { min-height: 420px; }
  .cpb-list { margin: 8px 0; }
  .cpb-list > li { min-height: 42px; }
  .cpb-table { width: 100%; border-collapse: collapse; }
  .cpb-table th, .cpb-table td { height: 64px; border: 1px solid #333; padding: 8px; }
  .cpb-toc-row {
    display: flex; justify-content: space-between; gap: 16px;
    min-height: 32px; padding: 6px 0; border-bottom: 1px solid #ddd;
  }
  .cpb-lep-heading { font-size: 18px; line-height: 1.2; margin: 4px 0; }
  .cpb-lep-cert-text { margin: 8px 0; }
  .cpb-lep-table { width: 100%; border-collapse: collapse; }
  .cpb-lep-table th, .cpb-lep-table td { height: 42px; border: 1px solid #333; padding: 6px; }
  .cpb-lep-part-row { min-height: 42px; }
  .cpb-part0-heading { font-size: 18px; line-height: 1.2; margin: 4px 0; }
  .cpb-part0-table { width: 100%; border-collapse: collapse; }
  .cpb-part0-table th, .cpb-part0-table td { height: 42px; border: 1px solid #333; padding: 6px; }
  .cpb-part0-amend-row { min-height: 42px; }
  .cpb-page-header-table { width:100%; border-collapse:collapse; table-layout:fixed; }
  .cpb-page-header-table td { border-left:1px solid #94a3b8; padding:4px 8px; vertical-align:middle; }
  .cpb-page-header-table td:first-child { border-left:0; }
  .cpb-page-header-cell--left { width:30%; }
  .cpb-page-header-cell--center { width:40%; text-align:center; }
  .cpb-page-header-cell--right { width:30%; }
  .cpb-table-wrap { width:100%; max-width:100%; }
`;

function section(id, key, title, flags, units, extra) {
  return Object.assign({
    id,
    section_id: id,
    stable_anchor: key,
    title,
    section_key: key,
    manual_part: 1,
    part_title: "Part One",
    flags: flags || {},
    header_template: '<header class="cpb-page-header"><div style="height:40px"><strong>Fixture Manual</strong></div></header>',
    footer_template: '<footer class="cpb-page-footer"><span>Controlled copy</span><span class="cpb-page-number">{{PAGE_NUMBER}}</span></footer>',
    show_header_footer: true,
    units
  }, extra || {});
}

function unit(id, type, html, extra) {
  const numeric = Number(String(id).replace(/\D/g, ""));
  const hashed = Array.from(String(id)).reduce((sum, ch) => sum + ch.charCodeAt(0), 0);
  return Object.assign({
    unit_key: id,
    block_id: extra && extra.block_id ? extra.block_id : (numeric || hashed),
    stable_anchor: id,
    block_type: type,
    html
  }, extra || {});
}

function sourceWith(sections) {
  return {
    layout_profile: "IPCA_READER_CANONICAL_816x1056_v1",
    layout_hash: "fixture-layout",
    layout,
    header_template_html: '<header class="cpb-page-header"><div style="height:40px"><strong>Fixture Manual</strong></div></header>',
    footer_template_html: '<footer class="cpb-page-footer"><span>Controlled copy</span><span class="cpb-page-number">{{PAGE_NUMBER}}</span></footer>',
    sections
  };
}

function runWorker(source, incremental = null) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "ipca-manual-segments-"));
  const inputPath = path.join(directory, "input.json");
  const outputPath = path.join(directory, "output.json");
  fs.writeFileSync(inputPath, JSON.stringify({
    source,
    book_style_css: bookStyleCSS,
    incremental
  }));
  const execution = spawnSync(process.execPath, [
    path.join(root, "scripts/authoritative_manual_paginator.cjs"),
    "--input", inputPath,
    "--output", outputPath
  ], { encoding: "utf8", cwd: root, timeout: 120000 });
  let result = null;
  if (fs.existsSync(outputPath)) {
    try {
      result = JSON.parse(fs.readFileSync(outputPath, "utf8"));
    } catch (_) {
      result = null;
    }
  }
  fs.rmSync(directory, { recursive: true, force: true });
  return { execution, result };
}

function assertHeaderFooter(pages) {
  assert.ok(pages.length > 0, "expected generated pages");
  pages.forEach((page) => {
    assert.ok(page.page_html.includes("reader-page-header-region"), "header region missing");
    assert.ok(page.page_html.includes("reader-page-footer-region"), "footer region missing");
    assert.ok(page.page_html.includes("justify-content: flex-end"), "footer must bottom-align");
    assert.ok(page.metrics && page.metrics.validation_passed === true, "geometry validation must pass");
    assert.ok(page.metrics.header_frame.height > 0, "resolved renderer header height missing");
    assert.ok(page.metrics.footer_frame.height > 0, "resolved renderer footer height missing");
    assert.strictEqual(
      page.metrics.content_frame.height,
      layout.page_height_px
        - layout.sheet_padding_top_px
        - page.metrics.header_frame.height
        - layout.header_margin_bottom_px
        - layout.footer_margin_top_px
        - page.metrics.footer_frame.height
        - layout.sheet_padding_bottom_px,
      "body capacity does not follow resolved furniture geometry"
    );
    assert.strictEqual(page.metrics.header_body_intersect, false, "body must not intersect header");
    assert.strictEqual(page.metrics.body_footer_intersect, false, "body must not intersect footer");
    const contentY = Number(page.metrics.content_frame.y);
    const headerBottom = Number(page.metrics.header_frame.y) + Number(page.metrics.header_frame.height);
    assert.ok(contentY >= headerBottom, "contentFrame.minY must be >= headerFrame.maxY");
    if (page.metrics.first_body_page_y != null) {
      assert.ok(
        page.metrics.first_body_page_y + 0.75 >= contentY,
        "first body element must start at or below contentFrame top"
      );
    }
    assert.ok(!/Drop image here to insert/i.test(page.page_html), "editor drop zone leaked into page_html");
    assert.ok(!page.page_html.includes("cpb-dropzone"), "dropzone class leaked into page_html");
  });
}

function pageBodyHtml(page) {
  const match = String(page.page_html || "").match(
    /<main class="reader-page-body[\s\S]*?<\/main>/
  );
  return match ? match[0] : "";
}

function publicationSheet(inner, sheetClass) {
  return `<div class="cpb-sheet ${sheetClass || ""}" data-section-id="3" `
    + `style="width:816px;min-height:1056px;height:1056px;padding:48px 56px 64px">`
    + '<header class="cpb-page-header"><span>SHEET_HEADER_CHROME</span></header>'
    + inner
    + '<footer class="cpb-page-footer"><span>SHEET_FOOTER_CHROME</span></footer>'
    + "</div>";
}

function assertContentFragmentGeometry(pages, fragmentNeedle) {
  assert.ok(pages.length > 0, "expected generated pages");
  pages.forEach((page) => {
    const content = page.metrics && page.metrics.content_frame;
    assert.ok(content, "content_frame missing");
    const blocks = (page.metrics.block_measurements || []).filter((block) =>
      String(block.source_fragment_id || "").includes(fragmentNeedle)
    );
    assert.ok(blocks.length > 0, `no measured blocks matching ${fragmentNeedle}`);
    blocks.forEach((block) => {
      assert.ok(
        block.frame.width <= content.width + 0.75,
        `${block.source_fragment_id} width ${block.frame.width} exceeds contentFrame ${content.width}`
      );
      assert.ok(
        block.frame.width + 0.75 < layout.page_width_px,
        `${block.source_fragment_id} measured at page width ${block.frame.width}`
      );
      assert.ok(
        block.frame.height <= content.height + 0.75,
        `${block.source_fragment_id} height ${block.frame.height} exceeds contentFrame ${content.height}`
      );
      assert.ok(
        block.frame.height + 0.75 < layout.page_height_px,
        `${block.source_fragment_id} inherited page height ${block.frame.height}`
      );
    });
    const bodyHtml = pageBodyHtml(page);
    assert.ok(!/min-height:\s*1056px/i.test(bodyHtml), "full-page min-height leaked into body");
    assert.ok(!/height:\s*1056px/i.test(bodyHtml), "full-page height leaked into body");
    assert.ok(!bodyHtml.includes("SHEET_HEADER_CHROME"), "page-shell header leaked into body");
    assert.ok(!bodyHtml.includes("SHEET_FOOTER_CHROME"), "page-shell footer leaked into body");
    assert.ok(
      !/<div class="cpb-sheet[\s"]/.test(bodyHtml),
      "page-shell wrapper leaked into body fragments"
    );
  });
}

function sourceCoverage(result) {
  return result.pages.flatMap((page) =>
    page.coverage.filter((item) => item.presentation_copy !== true)
  );
}

function lepHtml(rowCount) {
  const rows = Array.from({ length: rowCount }, (_, index) =>
    `<tr class="cpb-lep-part-row" data-stable-anchor="lep-part-${index + 1}">`
      + `<td>Part ${index + 1}</td><td>${index + 3}</td>`
      + `<td>2026-01-01</td><td>Rev ${index + 1}</td></tr>`
  ).join("");
  return publicationSheet(
    '<div class="cpb-lep">'
    + '<div class="cpb-lep-heading">PART 0 – Manual Administration</div>'
    + '<div class="cpb-lep-heading">0. OUTLINE</div>'
    + '<div class="cpb-lep-heading">0.1 List of effective Parts</div>'
    + '<div class="cpb-lep-heading">0.1.1 Effective Parts</div>'
    + '<div class="cpb-lep-cert-wrap"><div class="cpb-lep-cert-text">Certification statement.</div></div>'
    + '<table class="cpb-lep-table cpb-table" data-lep-parts-table="1">'
    + '<colgroup><col style="width:18%"><col style="width:28%"><col style="width:27%"><col style="width:27%"></colgroup>'
    + '<thead><tr class="cpb-table-header-row"><th>Part</th><th>Pages</th><th>Date</th><th>Revision</th></tr></thead>'
    + `<tbody>${rows}</tbody></table></div>`,
    "cpb-sheet--lep"
  );
}

function lepSection(id, rowCount) {
  return section(id, "lep", "List of Effective Parts", { is_part0: true }, [
    unit("lep-generated", "lep", lepHtml(rowCount), { block_id: 30 })
  ]);
}

function lepRowCoverage(result) {
  return sourceCoverage(result).filter((item) => item.source_fragment_id.includes("/lep-row-"));
}

function amendmentHtml(rowCount) {
  const rows = Array.from({ length: rowCount }, (_, index) =>
    `<tr class="cpb-part0-amend-row" data-part0-row="${index}">`
      + `<td>Amd ${index + 1}</td><td>2026-01-01</td><td>Change ${index + 1}</td></tr>`
  ).join("");
  return publicationSheet(
    '<div class="cpb-part0 cpb-lep">'
    + '<div class="cpb-part0-heading">0.3 Amendment List</div>'
    + '<div class="cpb-part0-body">'
    + '<table class="cpb-table cpb-part0-table" data-part0-table="amendment_list">'
    + '<thead><tr><th>No</th><th>Date</th><th>Change</th></tr></thead>'
    + `<tbody>${rows}</tbody></table></div></div>`,
    "cpb-sheet--part0"
  );
}

function generatedPart0Section(id, key, title, html) {
  return section(id, key, title, {
    is_part0: true,
    pagination_authority: "generated",
    is_generated: true,
    is_system_managed: true,
    allow_author_blocks: false
  }, [
    unit(`${key}-generated`, "generated", html, {
      block_id: id * 11,
      pagination_authority: "generated"
    })
  ], {
    pagination_authority: "generated",
    is_generated: true,
    is_system_managed: true,
    allow_author_blocks: false
  });
}

const cases = [];

cases.push(["A. one ordinary segment fits one page", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-one", "heading", '<article data-block-id="1" data-stable-anchor="block-one"><h2>Fits on one page</h2></article>'),
      unit("block-two", "paragraph", '<article data-block-id="2" data-stable-anchor="block-two"><p>Short ordinary paragraph.</p></article>')
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  assert.strictEqual(result.pages.length, 1);
  assert.strictEqual(result.engine_version, "live-authoritative-flow-v1");
  assertHeaderFooter(result.pages);
}]);

cases.push(["B. multiple ordinary blocks exceed intended page", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-one", "paragraph", '<article data-block-id="1" data-stable-anchor="block-one"><p class="tall-block">6.1.7 First overflowing block</p></article>'),
      unit("block-two", "paragraph", '<article data-block-id="2" data-stable-anchor="block-two"><p class="tall-block">6.1.8 Seats, seat safety belts, restraint systems and child restraint devices</p></article>')
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  assert.ok(result.pages.length >= 2, "ordinary author blocks must flow to a later page");
  const firstPage = result.pages.find((page) => page.page_html.includes("6.1.7"));
  const secondPage = result.pages.find((page) => page.page_html.includes("6.1.8"));
  assert.ok(firstPage && secondPage && secondPage.page_number > firstPage.page_number);
}]);

cases.push(["C. persisted manual break starts the next page", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-one", "heading", '<article data-block-id="1" data-stable-anchor="block-one"><h2>First controlled heading</h2></article>'),
      unit("block-two", "heading", '<article data-block-id="2" data-stable-anchor="block-two"><h2>Manual break target</h2></article>', {
        force_break_before: true,
        manual_page_break_before: true
      })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  const opening = result.pages.find((page) => page.page_html.includes("First controlled heading"));
  const target = result.pages.find((page) => page.page_html.includes("Manual break target"));
  assert.ok(opening && target && target.page_number > opening.page_number);
}]);

cases.push(["D. chapter heading without manual break does not start a page", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "intro", "Introduction", {}, [
      unit("block-one", "paragraph", '<article data-block-id="1" data-stable-anchor="block-one"><p>Lead-in paragraph before a chapter heading.</p></article>')
    ]),
    section(2, "chapter-one", "Chapter One", { is_chapter_start: true, is_major_section_start: true }, [
      unit("block-two", "heading", '<article data-block-id="2" data-stable-anchor="block-two"><h2>Chapter heading stays on the same intended page</h2></article>')
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 1, "chapter boundary must not auto-paginate");
  assert.ok(result.pages[0].page_html.includes("Lead-in paragraph"));
  assert.ok(result.pages[0].page_html.includes("Chapter heading stays"));
}]);

cases.push(["E. part boundary without manual break does not start a page", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "part_1", "Part 1", { is_part_start: true, is_major_section_start: true }, [
      unit("block-one", "paragraph", '<article data-block-id="1" data-stable-anchor="block-one"><p>End of part one copy.</p></article>')
    ]),
    section(2, "part_2", "Part 2", { is_part_start: true, is_major_section_start: true }, [
      unit("block-two", "heading", '<article data-block-id="2" data-stable-anchor="block-two"><h2>Part 2 heading shares the intended page</h2></article>')
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 1, "part boundary must not auto-paginate");
}]);

cases.push(["F. section boundary without manual break does not start a page", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-a", "Section A", { is_section_start: true }, [
      unit("block-one", "paragraph", '<article data-block-id="1" data-stable-anchor="block-one"><p>Section A copy.</p></article>')
    ]),
    section(2, "section-b", "Section B", { is_section_start: true }, [
      unit("block-two", "heading", '<article data-block-id="2" data-stable-anchor="block-two"><h3>Section B heading stays put</h3></article>')
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 1, "section boundary must not auto-paginate");
}]);

cases.push(["G. generated TOC continues automatically", () => {
  const tocRows = Array.from({ length: 72 }, (_, index) =>
    `<div class="cpb-toc-row" data-stable-anchor="toc-${index + 1}">`
      + `<span>Section ${index + 1} — Controlled Operations</span><span>${index + 3}</span></div>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(2, "toc", "Table of Contents", {}, [
      unit("toc-2-0", "toc", publicationSheet(
        `<section><h1>Table of Contents</h1><nav class="cpb-toc">${tocRows}</nav></section>`
      ), { block_id: 20 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 3, "TOC must continue across physical pages");
  assertHeaderFooter(result.pages);
  assertContentFragmentGeometry(result.pages, "/toc-row-");
}]);

cases.push(["H/I. long table row-paginates with presentation-only headers", () => {
  const tableRows = Array.from({ length: 18 }, (_, index) =>
    `<tr><td>Row ${index + 1}</td><td>Controlled table row content</td></tr>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-table", "table",
        `<article class="cpb-block" data-block-id="7" data-block-type="table" data-stable-anchor="block-table"><table class="cpb-table"><thead><tr><th>Item</th><th>Description</th></tr></thead><tbody>${tableRows}</tbody></table></article>`,
        { block_id: 7 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 2, "long table must continue");
  const rows = sourceCoverage(result).filter((item) => item.source_fragment_id.includes("/block-table/table-row-"));
  assert.strictEqual(rows.length, 18, "each table row must appear exactly once");
  assert.strictEqual(new Set(rows.map((item) => item.source_fragment_id)).size, 18);
  assert.ok(result.pages.flatMap((page) => page.coverage).some((item) =>
    item.source_fragment_id.endsWith("/block-table/table-header") && item.presentation_copy === true
  ), "continued tables must repeat the header as a presentation-only copy");
  assertHeaderFooter(result.pages);
  assertContentFragmentGeometry(result.pages, "block-table");
}]);

cases.push(["H1. one-pixel Chromium table rounding does not invalidate a page", () => {
  const tableRows = Array.from({ length: 24 }, (_, index) =>
    `<tr><td>${index + 1}</td><td>Flight-planning value ${index + 1}</td><td>Reference</td></tr>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(1, "rounded-table", "Flight Planning", {}, [
      unit(
        "rounded-table-block",
        "table",
        '<article class="cpb-block" data-block-id="8" data-block-type="table">'
          + '<table class="cpb-table" style="width:calc(100% + 1px)">'
          + '<thead><tr><th>Fuel</th><th>Amount</th><th>Reference</th></tr></thead>'
          + `<tbody>${tableRows}</tbody></table></article>`,
        { block_id: 8 }
      ),
    ]),
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 2, "rounded table must paginate normally");
  assert.ok(!(result.failures || []).some((failure) =>
    failure.code === "CONTENT_WIDTH_OVERFLOW"
    || failure.code === "UNLAYOUTABLE_FRAGMENT"
  ), JSON.stringify(result.failures));
  assertContentFragmentGeometry(result.pages, "rounded-table-block");
}]);

cases.push(["H2. vertically merged table rows stay in one pagination fragment", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "vertical-table", "Vertical table", {}, [
      unit("lead", "paragraph",
        '<article class="cpb-block" data-block-id="6" data-block-type="paragraph" data-stable-anchor="lead"><p style="min-height:620px">Lead content</p></article>',
        { block_id: 6 }),
      unit("vertical-table-block", "table",
        '<article class="cpb-block" data-block-id="7" data-block-type="table" data-stable-anchor="vertical-table-block">'
          + '<table class="cpb-table"><thead><tr><th>Item</th><th>Description</th></tr></thead><tbody>'
          + '<tr style="height:100px"><td rowspan="2">Merged value</td><td>Upper value</td></tr>'
          + '<tr style="height:100px"><td>Lower value</td></tr>'
          + '<tr><td>Following row</td><td>After merge</td></tr>'
          + '</tbody></table></article>',
        { block_id: 7 }),
    ]),
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  const mergedPages = result.pages.filter((page) =>
    page.page_html.includes("Merged value")
      || page.page_html.includes("Lower value")
  );
  assert.strictEqual(mergedPages.length, 1, "a vertical rowspan group must not split between pages");
  assert.ok(
    mergedPages[0].page_html.includes('rowspan="2"')
      && mergedPages[0].page_html.includes("Upper value")
      && mergedPages[0].page_html.includes("Lower value"),
    "the complete vertically merged row group must render together"
  );
  const mergedCoverage = sourceCoverage(result).filter((item) =>
    item.source_fragment_id.includes("/vertical-table-block/table-row-0")
  );
  assert.strictEqual(mergedCoverage.length, 1, "the rowspan group must have one source fragment");
}]);

cases.push(["long table continuation does not absorb following ordinary blocks", () => {
  const tableRows = Array.from({ length: 18 }, (_, index) =>
    `<tr><td>Row ${index + 1}</td><td>Controlled table row content</td></tr>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-table", "table",
        `<article class="cpb-block" data-block-id="7" data-block-type="table" data-stable-anchor="block-table"><table class="cpb-table"><thead><tr><th>Item</th><th>Description</th></tr></thead><tbody>${tableRows}</tbody></table></article>`,
        { block_id: 7 }),
      unit("block-after", "paragraph", '<article data-block-id="8" data-stable-anchor="block-after"><p>Following ordinary paragraph must not ride table continuation.</p></article>', { block_id: 8 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  const finalTablePage = Math.max(...result.pages.filter((page) =>
    page.coverage.some((item) =>
      item.source_fragment_id.includes("/block-table/table-row-")
      && item.presentation_copy === false
    )
  ).map((page) => page.page_number));
  const followingPage = result.pages.find((page) => page.page_html.includes("Following ordinary paragraph"));
  assert.ok(followingPage && followingPage.page_number > finalTablePage);
}]);

cases.push(["J. oversized single block may split", () => {
  const items = Array.from({ length: 28 }, (_, index) =>
    `<li>Oversized list item ${index + 1} with enough height to exceed one page.</li>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-list", "list",
        `<article class="cpb-block" data-block-id="6" data-block-type="list" data-stable-anchor="block-list"><ol class="cpb-list">${items}</ol></article>`,
        { block_id: 6 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 2, "one oversized block may continue");
  const listCoverage = sourceCoverage(result).filter((item) => item.source_fragment_id.includes("/block-list/li-"));
  assert.strictEqual(listCoverage.length, 28, "list items appear exactly once");
}]);

cases.push(["K. every page has header, footer, and sequential numbers", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-one", "heading", '<article data-block-id="1" data-stable-anchor="block-one"><h2>Opening</h2></article>'),
      unit("block-two", "heading", '<article data-block-id="2" data-stable-anchor="block-two"><h2>After break</h2></article>', {
        force_break_before: true,
        manual_page_break_before: true
      })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.deepStrictEqual(
    result.pages.map((page) => page.page_number),
    result.pages.map((_, index) => index + 1)
  );
  assertHeaderFooter(result.pages);
}]);

cases.push(["L. editor drop zone never enters authoritative page_html", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit(
        "block-copy",
        "paragraph",
        '<article data-block-id="11" data-stable-anchor="block-copy"><p>Ordinary paragraph.</p>'
          + '<div class="cpb-dropzone" data-dropzone="image">Drop image here to insert</div></article>'
      )
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 1);
  assertHeaderFooter(result.pages);
  assert.ok(result.pages[0].page_html.includes("Ordinary paragraph."));
  assert.ok(result.pages[0].page_html.includes("Controlled copy"));
  assert.ok(result.pages[0].page_html.includes("cpb-page-number"));
}]);

cases.push(["M. real inserted image and caption remain", () => {
  const image = '<article class="cpb-block cpb-block--image" data-block-id="12" data-block-type="image" data-stable-anchor="block-image">'
    + '<figure class="cpb-image" data-field="image" style="width:100%" data-width-pct="100">'
    + '<div class="cpb-image-frame">'
    + '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="Cabin safety diagram" width="320" height="80">'
    + '</div><figcaption>Cabin safety diagram</figcaption></figure></article>';
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-image", "image", image, { block_id: 12, atomic: true })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 1);
  assertHeaderFooter(result.pages);
  assert.ok(result.pages[0].page_html.includes("cpb-image"));
  assert.ok(result.pages[0].page_html.includes("Cabin safety diagram"));
  assert.ok(result.pages[0].page_html.includes("<img"));
}]);

cases.push(["N. manual page-break page starts at Book Style body top", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-one", "heading", '<article data-block-id="1" data-stable-anchor="block-one"><h2>First controlled heading</h2></article>'),
      unit("block-two", "heading", '<article data-block-id="2" data-stable-anchor="block-two"><h2>Manual break target</h2></article>', {
        force_break_before: true,
        manual_page_break_before: true
      })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 2);
  assertHeaderFooter(result.pages);
  const afterBreak = result.pages.find((page) => page.page_html.includes("Manual break target"));
  assert.ok(afterBreak);
  const contentY = Number(afterBreak.metrics.content_frame.y);
  const headerBottom = Number(afterBreak.metrics.header_frame.y)
    + Number(afterBreak.metrics.header_frame.height);
  assert.ok(contentY >= headerBottom + layout.header_margin_bottom_px - 0.75);
  assert.ok(afterBreak.metrics.first_body_page_y + 0.75 >= contentY);
  assert.ok(/overflow:\s*hidden/i.test(afterBreak.page_html));
}]);

cases.push(["O. generated LEP fits one page", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 3)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.strictEqual(result.pages.length, 1, "short LEP must stay on one page");
  assert.strictEqual(lepRowCoverage(result).length, 3);
  assert.ok(result.pages[0].page_html.includes("0.1 List of effective Parts"));
  assert.ok(result.pages[0].page_html.includes("Part 3"));
  assertHeaderFooter(result.pages);
  assertContentFragmentGeometry(result.pages, "/lep-row-");
}]);

cases.push(["P. generated LEP spans multiple pages automatically", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 48)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.length >= 3, "LEP must continue across physical pages without Manual Page Breaks");
  assert.ok(result.pages.slice(1).every((page) =>
    String(page.metrics && page.metrics.break_reason || "") === "lep_continuation"
  ), "LEP continuation pages must not persist as manual breaks");
  result.pages.forEach((page) => {
    assert.ok(
      page.page_html.includes('<col style="width:18%">')
        && page.page_html.includes('<col style="width:28%">'),
      "every LEP page must preserve the saved colgroup widths"
    );
  });
  assertHeaderFooter(result.pages);
  assertContentFragmentGeometry(result.pages, "/lep-row-");
}]);

cases.push(["Q. every LEP row/item appears exactly once", () => {
  const rowCount = 48;
  const { execution, result } = runWorker(sourceWith([lepSection(3, rowCount)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  const rows = lepRowCoverage(result);
  assert.strictEqual(rows.length, rowCount, "each LEP row must appear exactly once");
  assert.strictEqual(new Set(rows.map((item) => item.source_fragment_id)).size, rowCount);
  const labels = result.pages.map((page) => page.page_html).join("\n");
  for (let index = 1; index <= rowCount; index++) {
    const matches = labels.match(new RegExp(`Part ${index}(?!\\d)`, "g")) || [];
    assert.ok(matches.length >= 1, `missing LEP row Part ${index}`);
    assert.strictEqual(matches.length, 1, `LEP row Part ${index} duplicated`);
  }
}]);

cases.push(["R. header/footer/page number appear on every LEP continuation page", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 48)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 2, "expected LEP continuation pages");
  assert.deepStrictEqual(
    result.pages.map((page) => page.page_number),
    result.pages.map((_, index) => index + 1)
  );
  result.pages.forEach((page) => {
    assert.ok(page.page_html.includes("reader-page-header-region"), "LEP page missing header");
    assert.ok(page.page_html.includes("reader-page-footer-region"), "LEP page missing footer");
    assert.ok(page.page_html.includes("cpb-page-number"), "LEP page missing page number");
    assert.ok(page.page_html.includes("Controlled copy"), "LEP page missing controlled footer");
  });
  assertHeaderFooter(result.pages);
}]);

cases.push(["S. following Part 0 content auto-paginates after LEP continuation", () => {
  const { execution, result } = runWorker(sourceWith([
    lepSection(3, 48),
    section(4, "revision_system", "0.2 Revision System", { is_part0: true }, [
      unit(
        "block-after-lep",
        "paragraph",
        '<article data-block-id="41" data-stable-anchor="block-after-lep"><p>Following ordinary Part 0 copy must not ride LEP continuation space.</p></article>',
        { block_id: 41 }
      )
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  const followingPage = result.pages.find((page) =>
    page.page_html.includes("Following ordinary Part 0 copy")
  );
  assert.ok(followingPage, "following Part 0 content must be emitted");
  assert.ok(
    !followingPage.page_html.includes("Part 48"),
    "following Part 0 content must not absorb LEP continuation space"
  );
  assertHeaderFooter(result.pages);
}]);

cases.push(["T. editable Part 0 content auto-paginates without Manual Page Breaks", () => {
  const { execution, result } = runWorker(sourceWith([
    section(4, "revision_system", "0.2 Revision System", { is_part0: true }, [
      unit(
        "block-one",
        "paragraph",
        '<article data-block-id="51" data-stable-anchor="block-one"><p class="tall-block">Ordinary Part 0 opening copy.</p></article>',
        { block_id: 51 }
      ),
      unit(
        "block-two",
        "paragraph",
        '<article data-block-id="52" data-stable-anchor="block-two"><p class="tall-block">0.2 Revision system body continues automatically.</p></article>',
        { block_id: 52 }
      )
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.length >= 2, "overflowing editable Part 0 content must continue");
  assert.ok(result.pages.some((page) =>
    page.page_html.includes("0.2 Revision system body continues automatically.")
  ));
  assertHeaderFooter(result.pages);
}]);

cases.push(["U. ordinary structural content flows without a manual-break error", () => {
  const { execution, result } = runWorker(sourceWith([
    section(4, "section-one", "Section One", {}, [
      unit(
        "lead-in",
        "paragraph",
        '<article data-block-id="61" data-stable-anchor="lead-in"><p>Short lead-in before overflowing Part 0 headings.</p></article>',
        { block_id: 61 }
      ),
      unit(
        "outline-shell",
        "paragraph",
        '<article data-block-id="62" data-stable-anchor="outline-shell">'
          + '<div class="cpb-lep-heading">PART 0 – Manual Administration</div>'
          + '<div class="cpb-lep-heading">0. OUTLINE</div>'
          + '<div class="cpb-lep-heading">0.1 List of effective Parts</div>'
          + '<p class="tall-block">Ordinary overflowing Part 0 body.</p>'
          + '<p class="tall-block">More overflowing Part 0 body.</p>'
          + "</article>",
        { block_id: 62 }
      )
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  assert.ok(result.pages.length >= 2, "ordinary structural content must auto-flow");
  assert.ok(result.pages.some((page) => page.page_html.includes("0.1 List of effective Parts")));
}]);

cases.push(["V. generated 0.1.1 Effective Parts spans pages automatically", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 48)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.length >= 3, "0.1.1 Effective Parts must continue automatically");
  assert.ok(result.pages[0].page_html.includes("0.1.1 Effective Parts"));
  assert.ok(result.pages.some((page) => page.page_html.includes("Part 48")));
  assertHeaderFooter(result.pages);
}]);

cases.push(["W. no MANUAL_BREAK_REQUIRED for generated Part 0 blocks", () => {
  const tocRows = Array.from({ length: 72 }, (_, index) =>
    `<div class="cpb-toc-row" data-stable-anchor="toc-${index + 1}">`
      + `<span>Section ${index + 1}</span><span>${index + 3}</span></div>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(2, "toc", "Table of Contents", {
      pagination_authority: "generated",
      is_generated: true,
      allow_author_blocks: false
    }, [
      unit("toc-2-0", "toc", publicationSheet(
        `<section><h1>Table of Contents</h1><nav class="cpb-toc">${tocRows}</nav></section>`
      ), {
        block_id: 20,
        pagination_authority: "generated"
      })
    ], { pagination_authority: "generated" }),
    lepSection(3, 24),
    generatedPart0Section(5, "amendment_list", "0.3 Amendment List", amendmentHtml(40))
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.length >= 4, "generated Part 0 sequence must auto-paginate");
  assert.ok(result.pages.some((page) => page.page_html.includes("0.1.1 Effective Parts")));
  assert.ok(result.pages.some((page) => page.page_html.includes("Amd 40")));
}]);

cases.push(["X. generated Part 0 continuation pages have header/footer/page number", () => {
  const { execution, result } = runWorker(sourceWith([
    generatedPart0Section(5, "amendment_list", "0.3 Amendment List", amendmentHtml(40))
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 2, "generated Part 0 must continue");
  assert.deepStrictEqual(
    result.pages.map((page) => page.page_number),
    result.pages.map((_, index) => index + 1)
  );
  result.pages.forEach((page) => {
    assert.ok(page.page_html.includes("reader-page-header-region"));
    assert.ok(page.page_html.includes("reader-page-footer-region"));
    assert.ok(page.page_html.includes("cpb-page-number"));
  });
  assertHeaderFooter(result.pages);
}]);

cases.push(["Y. generated Part 0 items appear exactly once and in order", () => {
  const rowCount = 40;
  const { execution, result } = runWorker(sourceWith([
    generatedPart0Section(5, "amendment_list", "0.3 Amendment List", amendmentHtml(rowCount))
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  const rows = sourceCoverage(result).filter((item) => item.source_fragment_id.includes("/generated-row-"));
  assert.strictEqual(rows.length, rowCount);
  assert.strictEqual(new Set(rows.map((item) => item.source_fragment_id)).size, rowCount);
  const ordered = rows.map((item) => item.source_order);
  const sorted = ordered.slice().sort((a, b) => a - b);
  assert.deepStrictEqual(ordered, sorted);
  const html = result.pages.map((page) => page.page_html).join("\n");
  for (let index = 1; index <= rowCount; index++) {
    const matches = html.match(new RegExp(`Amd ${index}(?!\\d)`, "g")) || [];
    assert.strictEqual(matches.length, 1, `generated item Amd ${index} count=${matches.length}`);
  }
}]);

cases.push(["Z. explicit author authority cannot require a break inside Part 0", () => {
  const { execution, result } = runWorker(sourceWith([
    generatedPart0Section(5, "amendment_list", "0.3 Amendment List", amendmentHtml(40)),
    section(6, "revision_system", "0.2 Revision System", {
      is_part0: true,
      pagination_authority: "author",
      allow_author_blocks: true
    }, [
      unit(
        "author-after-generated",
        "paragraph",
        '<article data-block-id="71" data-stable-anchor="author-after-generated"><p>Author-editable Part 0 copy after generated content.</p></article>',
        { block_id: 71, pagination_authority: "author" }
      )
    ], { pagination_authority: "author", allow_author_blocks: true })
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.some((page) =>
    page.page_html.includes("Author-editable Part 0 copy after generated content.")
  ));
  assertHeaderFooter(result.pages);
}]);

cases.push(["ZA. oversized editable Part 0 paragraphs split automatically", () => {
  const longText = Array.from(
    { length: 900 },
    (_, index) => `controlled revision statement ${index + 1}`
  ).join(" ");
  const { execution, result } = runWorker(sourceWith([
    section(6, "revision_system", "0.2 Revision System", {
      is_part0: true,
      pagination_authority: "author",
      allow_author_blocks: true
    }, [
      unit(
        "long-part0-paragraph",
        "paragraph",
        `<article data-block-id="72" data-stable-anchor="long-part0-paragraph"><p>${longText}</p></article>`,
        { block_id: 72, pagination_authority: "author" }
      )
    ], { pagination_authority: "author", allow_author_blocks: true })
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.length >= 2, "oversized Part 0 paragraph must split across pages");
  assertHeaderFooter(result.pages);
}]);

cases.push(["AA. LEP row measurement uses contentFrame width, not page width", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 3)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  const content = result.pages[0].metrics.content_frame;
  const block = result.pages[0].metrics.block_measurements.find((item) =>
    String(item.source_fragment_id || "").includes("/lep-row-0")
  );
  assert.ok(block, "lep-row-0 must be measured");
  assert.ok(block.frame.width <= content.width + 0.75);
  assert.ok(block.frame.width + 0.75 < layout.page_width_px);
  assert.ok(content.width < layout.page_width_px - 100);
}]);

cases.push(["AB. LEP row height reflects only row content", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 3)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  const content = result.pages[0].metrics.content_frame;
  const block = result.pages[0].metrics.block_measurements.find((item) =>
    String(item.source_fragment_id || "").includes("/lep-row-0")
  );
  assert.ok(block, "lep-row-0 must be measured");
  assert.ok(block.frame.height < layout.page_height_px - 200, "LEP row must not use page height");
  assert.ok(block.frame.height <= content.height + 0.75);
  assert.ok(block.frame.height !== layout.page_height_px);
}]);

cases.push(["AC. a normal LEP row fits inside a fresh body frame", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 1)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.strictEqual(result.pages.length, 1);
  assert.ok(result.pages[0].metrics.validation_passed);
  assertContentFragmentGeometry(result.pages, "/lep-row-");
}]);

cases.push(["AD. no LEP row inherits full-page min-height/height", () => {
  const { execution, result } = runWorker(sourceWith([lepSection(3, 8)]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  result.pages.forEach((page) => {
    const bodyHtml = pageBodyHtml(page);
    assert.ok(!/min-height:\s*1056px/i.test(bodyHtml));
    assert.ok(!/height:\s*1056px/i.test(bodyHtml));
    (page.metrics.block_measurements || []).forEach((block) => {
      if (!String(block.source_fragment_id || "").includes("/lep-row-")) return;
      assert.ok(block.frame.height + 0.75 < layout.page_height_px);
    });
  });
}]);

cases.push(["AE. generated TOC rows use the same content-fragment measurement model", () => {
  const tocRows = Array.from({ length: 8 }, (_, index) =>
    `<div class="cpb-toc-row" data-stable-anchor="toc-${index + 1}">`
      + `<span>Section ${index + 1}</span><span>${index + 3}</span></div>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(2, "toc", "Table of Contents", {
      pagination_authority: "generated",
      is_generated: true,
      allow_author_blocks: false
    }, [
      unit("toc-2-0", "toc", publicationSheet(
        `<section><h1>Table of Contents</h1><nav class="cpb-toc">${tocRows}</nav></section>`
      ), { block_id: 20, pagination_authority: "generated" })
    ], { pagination_authority: "generated" })
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.length >= 1);
  assertContentFragmentGeometry(result.pages, "/toc-row-");
}]);

cases.push(["AF. long-table rows remain correct under the content-fragment model", () => {
  const tableRows = Array.from({ length: 18 }, (_, index) =>
    `<tr><td>Row ${index + 1}</td><td>Controlled table row content</td></tr>`
  ).join("");
  const { execution, result } = runWorker(sourceWith([
    section(1, "section-one", "Section One", {}, [
      unit("block-table", "table",
        publicationSheet(
          `<article class="cpb-block" data-block-id="7" data-block-type="table" data-stable-anchor="block-table"><table class="cpb-table"><thead><tr><th>Item</th><th>Description</th></tr></thead><tbody>${tableRows}</tbody></table></article>`
        ),
        { block_id: 7 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined, result.error && result.error.message);
  assert.ok(result.pages.length >= 2, "long table must continue");
  const rows = sourceCoverage(result).filter((item) => item.source_fragment_id.includes("/block-table/table-row-"));
  assert.strictEqual(rows.length, 18, "each table row must appear exactly once");
  assertContentFragmentGeometry(result.pages, "block-table");
}]);

cases.push(["AG. heading chain stays with meaningful following content", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "heading-flow", "Heading flow", {}, [
      unit("lead", "paragraph",
        '<article data-block-id="71" data-stable-anchor="lead"><p style="min-height:700px">Page-filling lead.</p></article>',
        { block_id: 71 }),
      unit("heading-a", "heading",
        '<article data-block-id="72" data-stable-anchor="heading-a"><h2>Kept heading A</h2></article>',
        { block_id: 72 }),
      unit("heading-b", "heading",
        '<article data-block-id="73" data-stable-anchor="heading-b"><h3>Kept heading B</h3></article>',
        { block_id: 73 }),
      unit("following", "paragraph",
        '<article data-block-id="74" data-stable-anchor="following"><p>Meaningful following paragraph.</p></article>',
        { block_id: 74 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  const headingPage = result.pages.find((page) => page.page_html.includes("Kept heading A"));
  assert.ok(headingPage && headingPage.page_html.includes("Kept heading B"));
  assert.ok(headingPage.page_html.includes("Meaningful following paragraph"));
  assert.ok(!result.pages[0].page_html.includes("Kept heading A"));
}]);

cases.push(["AH. callout moves intact when it fits a fresh page", () => {
  const { execution, result } = runWorker(sourceWith([
    section(1, "callout-flow", "Callout flow", {}, [
      unit("lead", "paragraph",
        '<article data-block-id="81" data-stable-anchor="lead"><p style="min-height:560px">Lead before note.</p></article>',
        { block_id: 81 }),
      unit("note", "note",
        '<article class="cpb-callout" data-block-id="82" data-stable-anchor="note"><aside style="min-height:300px"><strong>NOTE</strong><p>Atomic note body.</p></aside></article>',
        { block_id: 82 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  const entries = sourceCoverage(result).filter((item) =>
    item.source_fragment_id.includes("/note/")
  );
  assert.strictEqual(entries.length, 1, "fresh-page callout must not split");
  assert.strictEqual(entries[0].range_start, 0);
  assert.strictEqual(entries[0].range_end, entries[0].source_length);
  const notePage = result.pages.find((page) => page.page_html.includes("Atomic note body"));
  assert.ok(notePage && notePage.page_number > 1);
}]);

cases.push(["AI. author paragraph splits semantically after earlier blocks", () => {
  const longText = Array.from({ length: 240 }, (_, index) =>
    `Semantic sentence ${index + 1}.`
  ).join(" ");
  const { execution, result } = runWorker(sourceWith([
    section(1, "paragraph-flow", "Paragraph flow", {}, [
      unit("lead", "paragraph",
        '<article data-block-id="91" data-stable-anchor="lead"><p style="min-height:420px">Lead block.</p></article>',
        { block_id: 91 }),
      unit("long-paragraph", "paragraph",
        `<article data-block-id="92" data-stable-anchor="long-paragraph"><p>${longText}</p></article>`,
        { block_id: 92 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  const entries = sourceCoverage(result).filter((item) =>
    item.source_fragment_id.includes("/long-paragraph/")
  ).sort((a, b) => a.range_start - b.range_start);
  assert.ok(entries.length > 1, "long author paragraph must split across pages");
  entries.forEach((entry, index) => {
    if (index > 0) assert.strictEqual(entry.range_start, entries[index - 1].range_end);
  });
  assert.strictEqual(entries[0].range_start, 0);
  assert.strictEqual(entries[entries.length - 1].range_end, entries[0].source_length);
}]);

cases.push(["AJ. list items continue naturally after another block", () => {
  const items = Array.from({ length: 8 }, (_, index) => `<li>Flow item ${index + 1}</li>`).join("");
  const { execution, result } = runWorker(sourceWith([
    section(1, "list-flow", "List flow", {}, [
      unit("lead", "paragraph",
        '<article data-block-id="101" data-stable-anchor="lead"><p style="min-height:560px">Lead before list.</p></article>',
        { block_id: 101 }),
      unit("flow-list", "list",
        `<article data-block-id="102" data-stable-anchor="flow-list"><ol class="cpb-list">${items}</ol></article>`,
        { block_id: 102 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  const entries = sourceCoverage(result).filter((item) =>
    item.source_fragment_id.includes("/flow-list/li-")
  );
  assert.strictEqual(entries.length, 8);
  assert.ok(result.pages.length >= 2);
}]);

cases.push(["AK. deletion backflow reduces pages and pulls following content back", () => {
  const longText = Array.from({ length: 260 }, (_, index) => `Backflow text ${index + 1}.`).join(" ");
  const makeSource = (text) => sourceWith([
    section(1, "backflow", "Backflow", {}, [
      unit("editable", "paragraph",
        `<article data-block-id="111" data-stable-anchor="editable"><p>${text}</p></article>`,
        { block_id: 111 }),
      unit("following", "paragraph",
        '<article data-block-id="112" data-stable-anchor="following"><p>Following backflow marker.</p></article>',
        { block_id: 112 })
    ])
  ]);
  const longRun = runWorker(makeSource(longText));
  const shortRun = runWorker(makeSource("Short replacement."));
  assert.strictEqual(longRun.execution.status, 0, longRun.execution.stderr || longRun.execution.stdout);
  assert.strictEqual(shortRun.execution.status, 0, shortRun.execution.stderr || shortRun.execution.stdout);
  assert.strictEqual(longRun.result.error, undefined);
  assert.strictEqual(shortRun.result.error, undefined);
  assert.ok(longRun.result.pages.length > shortRun.result.pages.length);
  const longMarker = longRun.result.pages.find((page) => page.page_html.includes("Following backflow marker"));
  const shortMarker = shortRun.result.pages.find((page) => page.page_html.includes("Following backflow marker"));
  assert.ok(longMarker && shortMarker && shortMarker.page_number < longMarker.page_number);
}]);

cases.push(["AL. styled paragraph is recognized as a heading", () => {
  const source = sourceWith([
    section(1, "styled-heading", "Styled heading", {}, [
      unit("lead", "paragraph",
        '<article data-block-id="121" data-stable-anchor="lead"><p style="min-height:650px">Lead before styled heading.</p></article>',
        { block_id: 121 }),
      unit("styled-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="122" data-stable-anchor="styled-title"><p class="cpb-paragraph cpb-ps-subtitle_1" data-paragraph-style="subtitle_1">2. Styled Subsection</p></article>',
        { block_id: 122, paragraph_style: "subtitle_1" }),
      unit("styled-body", "paragraph",
        '<article data-block-id="123" data-stable-anchor="styled-body"><p style="min-height:120px">Meaningful chapter content.</p></article>',
        { block_id: 123 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  const headingPage = result.pages.find((page) => page.page_html.includes("2. Styled Subsection"));
  assert.ok(headingPage && headingPage.page_number === 2);
  assert.ok(headingPage.page_html.includes("Meaningful chapter content"));
  assert.strictEqual(headingPage.metrics.break_reason, "heading_keep_with_following");
  assert.strictEqual(result.validation.is_valid, true);
}]);

cases.push(["AM. consecutive styled heading chain moves without a manual break", () => {
  const source = sourceWith([
    section(1, "styled-chain", "Styled chain", {}, [
      unit("lead", "paragraph",
        '<article data-block-id="131" data-stable-anchor="lead"><p style="min-height:720px">Lead before styled chain.</p></article>',
        { block_id: 131 }),
      unit("chapter-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="132" data-stable-anchor="chapter-title"><p class="cpb-paragraph cpb-ps-subtitle_1" data-paragraph-style="subtitle_1">2.1 ORGANIZATION STRUCTURE</p></article>',
        { block_id: 132 }),
      unit("subsection-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="133" data-stable-anchor="subsection-title"><p class="cpb-paragraph cpb-ps-subtitle_2" data-paragraph-style="subtitle_2">2.1.1 Structure of the ATO</p></article>',
        { block_id: 133 }),
      unit("chapter-body", "paragraph",
        '<article data-block-id="134" data-stable-anchor="chapter-body"><p>EuroPilot Center organization content.</p></article>',
        { block_id: 134 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error, undefined);
  assert.strictEqual(result.validation.is_valid, true);
  assert.ok(!(source.manual_page_breaks || []).length, "automatic heading movement must not create a manual break");
  const chainPage = result.pages.find((page) =>
    page.page_html.includes("2.1 ORGANIZATION STRUCTURE")
  );
  assert.ok(chainPage && chainPage.page_number === 2);
  assert.ok(chainPage.page_html.includes("2.1.1 Structure of the ATO"));
  assert.ok(chainPage.page_html.includes("EuroPilot Center organization content"));
  assert.strictEqual(chainPage.metrics.break_reason, "heading_keep_with_following");
  assert.strictEqual(chainPage.metrics.forced_break_before, false);
  const sourceOrder = result.pages.flatMap((page) => page.coverage)
    .filter((entry) => !entry.presentation_copy)
    .map((entry) => entry.source_order);
  assert.deepStrictEqual(sourceOrder, sourceOrder.slice().sort((a, b) => a - b));
}]);

cases.push(["AN. main title starts a new page after a nearly empty prior section", () => {
  const source = sourceWith([
    section(1, "section-two", "Section Two", {}, [
      unit("section-two-end", "paragraph",
        '<article data-block-id="141" data-stable-anchor="section-two-end"><p>2.2.15 Subcontracted Training</p></article>',
        { block_id: 141 })
    ]),
    section(2, "section-three", "Section Three", {}, [
      unit("section-three-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="142" data-stable-anchor="section-three-title"><p class="cpb-paragraph cpb-ps-title" data-paragraph-style="title">3. STUDENT DISCIPLINE</p></article>',
        { block_id: 142, paragraph_style: "title" }),
      unit("section-three-subtitle", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="143" data-stable-anchor="section-three-subtitle"><p class="cpb-paragraph cpb-ps-subtitle_1" data-paragraph-style="subtitle_1">3.1 General</p></article>',
        { block_id: 143, paragraph_style: "subtitle_1" }),
      unit("section-three-body", "paragraph",
        '<article data-block-id="144" data-stable-anchor="section-three-body"><p>Following student discipline content.</p></article>',
        { block_id: 144 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 2);
  assert.ok(result.pages[0].page_html.includes("2.2.15 Subcontracted Training"));
  assert.ok(!result.pages[0].page_html.includes("3. STUDENT DISCIPLINE"));
  assert.ok(pageBodyHtml(result.pages[1]).indexOf("3. STUDENT DISCIPLINE")
    < pageBodyHtml(result.pages[1]).indexOf("3.1 General"));
  assert.strictEqual(result.pages[1].metrics.break_reason, "main_title_section_start");
  assert.strictEqual(result.validation.is_valid, true);
  assertHeaderFooter(result.pages);
}]);

cases.push(["AO. main title starts a new page after a nearly full prior section", () => {
  const source = sourceWith([
    section(1, "prior-full", "Prior full section", {}, [
      unit("prior-full-body", "paragraph",
        '<article data-block-id="151" data-stable-anchor="prior-full-body"><p style="min-height:680px">Nearly full preceding section.</p></article>',
        { block_id: 151 })
    ]),
    section(2, "next-main", "Next main section", {}, [
      unit("next-main-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="152" data-stable-anchor="next-main-title"><p data-paragraph-style="title">3. STUDENT DISCIPLINE</p></article>',
        { block_id: 152, paragraph_style: "title" }),
      unit("next-main-body", "paragraph",
        '<article data-block-id="153" data-stable-anchor="next-main-body"><p>First section content.</p></article>',
        { block_id: 153 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 2);
  assert.strictEqual(result.pages[1].coverage[0].source_fragment_id, "next-main/next-main-title/root");
  assert.strictEqual(result.pages[1].metrics.break_reason, "main_title_section_start");
  assert.strictEqual(result.validation.is_valid, true);
}]);

cases.push(["AP. main title already first on a page creates no blank page", () => {
  const source = sourceWith([
    section(1, "first-main", "First main section", {}, [
      unit("first-main-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="161" data-stable-anchor="first-main-title"><p data-paragraph-style="title">1. INTRODUCTION</p></article>',
        { block_id: 161, paragraph_style: "title" }),
      unit("first-main-body", "paragraph",
        '<article data-block-id="162" data-stable-anchor="first-main-body"><p>Opening content.</p></article>',
        { block_id: 162 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 1);
  assert.strictEqual(result.pages[0].coverage[0].source_fragment_id, "first-main/first-main-title/root");
  assert.strictEqual(result.pages[0].metrics.break_reason, "main_title_section_start");
  assert.strictEqual(result.validation.is_valid, true);
}]);

cases.push(["AQ. subtitle remains in normal automatic flow", () => {
  const source = sourceWith([
    section(1, "subtitle-flow", "Subtitle flow", {}, [
      unit("subtitle-lead", "paragraph",
        '<article data-block-id="171" data-stable-anchor="subtitle-lead"><p>Preceding content on the same page.</p></article>',
        { block_id: 171 }),
      unit("flow-subtitle", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="172" data-stable-anchor="flow-subtitle"><p data-paragraph-style="subtitle_1">3.1 General</p></article>',
        { block_id: 172, paragraph_style: "subtitle_1" }),
      unit("subtitle-body", "paragraph",
        '<article data-block-id="173" data-stable-anchor="subtitle-body"><p>Subtitle content.</p></article>',
        { block_id: 173 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 1);
  assert.ok(result.pages[0].page_html.includes("Preceding content"));
  assert.ok(result.pages[0].page_html.includes("3.1 General"));
  assert.notStrictEqual(result.pages[0].metrics.break_reason, "main_title_section_start");
}]);

cases.push(["AR. heading keep-with-following remains active inside a main-title section", () => {
  const source = sourceWith([
    section(1, "main-with-subtitle", "Main with subtitle", {}, [
      unit("main-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="181" data-stable-anchor="main-title"><p data-paragraph-style="title">3. STUDENT DISCIPLINE</p></article>',
        { block_id: 181, paragraph_style: "title" }),
      unit("main-fill", "paragraph",
        '<article data-block-id="182" data-stable-anchor="main-fill"><p style="min-height:620px">Section content before a subsection.</p></article>',
        { block_id: 182 }),
      unit("kept-subtitle", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="183" data-stable-anchor="kept-subtitle"><p data-paragraph-style="subtitle_1">3.2 Conduct</p></article>',
        { block_id: 183, paragraph_style: "subtitle_1" }),
      unit("kept-subtitle-body", "paragraph",
        '<article data-block-id="184" data-stable-anchor="kept-subtitle-body"><p style="min-height:120px">Meaningful conduct content.</p></article>',
        { block_id: 184 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  const subtitlePage = result.pages.find((page) => page.page_html.includes("3.2 Conduct"));
  assert.ok(subtitlePage && subtitlePage.page_number === 2);
  assert.ok(subtitlePage.page_html.includes("Meaningful conduct content"));
  assert.strictEqual(subtitlePage.metrics.break_reason, "heading_keep_with_following");
  assert.strictEqual(result.validation.is_valid, true);
}]);

cases.push(["AS. manual break before main title creates no duplicate blank page", () => {
  const source = sourceWith([
    section(1, "manual-prior", "Manual prior", {}, [
      unit("manual-prior-body", "paragraph",
        '<article data-block-id="191" data-stable-anchor="manual-prior-body"><p>Previous section.</p></article>',
        { block_id: 191 })
    ]),
    section(2, "manual-main", "Manual main", {}, [
      unit("manual-main-title", "paragraph",
        '<article class="cpb-block cpb-block--paragraph" data-block-id="192" data-stable-anchor="manual-main-title"><p data-paragraph-style="title">3. STUDENT DISCIPLINE</p></article>',
        { block_id: 192, paragraph_style: "title", force_break_before: true }),
      unit("manual-main-body", "paragraph",
        '<article data-block-id="193" data-stable-anchor="manual-main-body"><p>First section content.</p></article>',
        { block_id: 193 })
    ])
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.pages.length, 2);
  assert.strictEqual(result.pages[1].coverage[0].source_fragment_id, "manual-main/manual-main-title/root");
  assert.strictEqual(result.pages[1].metrics.break_reason, "forced_source_break");
  assert.strictEqual(result.validation.is_valid, true);
  assertHeaderFooter(result.pages);
}]);

cases.push(["AT. incremental suffix matches full authoritative page HTML", () => {
  const source = sourceWith([
    section(1, "prefix", "Prefix", {}, [
      unit("prefix-body", "paragraph",
        '<article data-block-id="201" data-stable-anchor="prefix-body"><p style="min-height:700px">Stable prefix page.</p></article>',
        { block_id: 201 })
    ]),
    section(2, "suffix", "Suffix", {}, [
      unit("suffix-start", "paragraph",
        '<article data-block-id="202" data-stable-anchor="suffix-start"><p style="min-height:500px">Suffix start.</p></article>',
        { block_id: 202, force_break_before: true }),
      unit("suffix-following", "paragraph",
        '<article data-block-id="203" data-stable-anchor="suffix-following"><p style="min-height:500px">Suffix following.</p></article>',
        { block_id: 203 })
    ])
  ]);
  const full = runWorker(source);
  assert.strictEqual(full.execution.status, 0, full.execution.stderr || full.execution.stdout);
  assert.ok(full.result.pages.length >= 3);
  const startSourceOrder = Math.min(...full.result.pages[1].coverage
    .filter((entry) => !entry.presentation_copy)
    .map((entry) => Number(entry.source_order)));
  const incrementalResult = runWorker(source, {
    start_source_order: startSourceOrder,
    page_number_offset: 1,
    prefix_source_fingerprints: Object.fromEntries(full.result.pages[0].coverage
      .filter((entry) => !entry.presentation_copy)
      .map((entry) => [String(entry.source_order), entry.source_fingerprint]))
  });
  assert.strictEqual(
    incrementalResult.execution.status,
    0,
    incrementalResult.execution.stderr || incrementalResult.execution.stdout
  );
  assert.strictEqual(incrementalResult.result.incremental.applied, true);
  assert.deepStrictEqual(
    incrementalResult.result.pages.map((page) => page.page_html),
    full.result.pages.slice(1).map((page) => page.page_html)
  );
  assert.deepStrictEqual(
    incrementalResult.result.pages.map((page) => page.coverage),
    full.result.pages.slice(1).map((page) => page.coverage)
  );
  assert.deepStrictEqual(
    incrementalResult.result.section_page_index,
    { "2": 2 }
  );
  const rejected = runWorker(source, {
    start_source_order: startSourceOrder,
    page_number_offset: 1,
    prefix_source_fingerprints: { "0": "0".repeat(64) }
  });
  assert.strictEqual(rejected.execution.status, 0);
  assert.strictEqual(rejected.result.error.code, "INCREMENTAL_PREFIX_MISMATCH");
}]);

cases.push(["AU. complete Part 0 golden publication remains structurally correct", () => {
  const golden = JSON.parse(fs.readFileSync(
    path.join(root, "tests/fixtures/authoritative_part0_golden.json"),
    "utf8"
  ));
  const heading = (title) => `<div class="cpb-part0-heading" data-paragraph-style="subtitle_1">${title}</div>`;
  const generated = (id, key, title, body) => section(id, key, title, {
    is_part0: true,
    is_section_start: true,
    force_page_break_before: true,
    pagination_authority: "generated"
  }, [
    unit(`${key}-root`, "generated", `<div class="cpb-part0">${heading(title)}${body}</div>`, {
      force_break_before: true,
      pagination_authority: "generated"
    })
  ], { pagination_authority: "generated" });
  const lepRows = golden.lep_parts.map((part) =>
    `<tr class="cpb-lep-part-row"><td>${part}</td><td>1-2</td><td>15/06/2026</td><td>Rev 6</td></tr>`
  ).join("");
  const source = sourceWith([
    section(101, "lep", golden.titles[0], {
      is_part0: true, is_section_start: true, force_page_break_before: true
    }, [
      unit("lep-golden", "lep",
        `<div class="cpb-lep">${heading(golden.titles[0])}<div class="cpb-lep-parts-wrap cpb-table-wrap">`
        + `<table class="cpb-table cpb-lep-table" data-lep-parts-table="1">`
        + '<colgroup><col style="width:17%"><col style="width:22%"><col style="width:34%"><col style="width:27%"></colgroup>'
        + `<thead><tr><th>Part</th><th>Pages</th><th>Date</th><th>Revision</th></tr></thead>`
        + `<tbody>${lepRows}</tbody></table></div></div>`,
        { force_break_before: true, pagination_authority: "generated" })
    ], { pagination_authority: "generated" }),
    generated(102, "revision_system", golden.titles[1],
      '<p>The revision system describes controlled amendment handling.</p>'),
    generated(103, "amendment_list", golden.titles[2],
      '<div class="cpb-part0-amendment cpb-table-wrap"><table class="cpb-table cpb-part0-table" data-part0-table="amendment_list">'
      + '<colgroup><col style="width:14%"><col style="width:26%"><col style="width:16%"><col style="width:17%"><col style="width:15%"><col style="width:12%"></colgroup>'
      + '<thead><tr><th>Revision</th><th>Reason</th><th>Revision Date</th><th>Effective Date</th><th>Incorporated</th><th>By</th></tr></thead>'
      + '<tbody><tr class="cpb-part0-amend-row"><td>Rev 6</td><td>CAA updates</td><td>09/01/23</td><td>19/01/23</td><td>19/01/23</td><td>STEF</td></tr></tbody></table></div>'),
    generated(104, "distribution_list", golden.titles[3],
      '<div class="cpb-part0-distribution cpb-table-wrap"><table class="cpb-table cpb-part0-table" data-part0-table="distribution_list">'
      + '<colgroup><col style="width:18%"><col style="width:82%"></colgroup>'
      + '<thead><tr><th>Copy Nr</th><th>Issue To</th></tr></thead>'
      + '<tbody><tr class="cpb-part0-dist-row"><td>0</td><td>Master Copy</td></tr></tbody></table></div>'),
    generated(105, "abbreviations", golden.titles[4],
      '<div class="cpb-part0-abbr-row"><strong>ACAS</strong> — Airborne Collision Avoidance System</div>'),
    generated(106, "definitions", golden.titles[5],
      '<div class="cpb-part0-def-row"><strong>Aircraft</strong> — Any machine deriving support from the air.</div>'),
    generated(107, "highlights", golden.titles[6],
      '<h3>Revision 6 Changes:</h3><p>Part 2 — Technical — §6.1.9 Medication: Updated the medication policy and protective-equipment requirements.</p>')
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.validation.is_valid, true);
  const allHtml = result.pages.map((page) => page.page_html).join("\n");
  let priorPage = 0;
  golden.titles.forEach((title, index) => {
    const matching = result.pages.filter((page) => page.page_html.includes(title));
    assert.strictEqual(matching.length, 1, `${title} must appear exactly once`);
    assert.ok(matching[0].page_number > priorPage, `${title} is out of order`);
    assert.strictEqual(matching[0].section_id, 101 + index, `${title} has wrong section ownership`);
    priorPage = matching[0].page_number;
  });
  golden.lep_parts.forEach((part) => {
    assert.ok(allHtml.includes(`<td>${part}</td>`), `LEP Part ${part} missing`);
  });
  assert.ok(!allHtml.includes("cpb-lep-part-row--empty"), "empty LEP row entered publication");
  assert.ok(!allHtml.includes("Revision 2 Changes"), "content leaked into Distribution List");
  const visibleText = allHtml.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ");
  golden.forbidden_change_tokens.forEach((token) => {
    assert.ok(!visibleText.toLowerCase().includes(token.toLowerCase()), `internal token leaked: ${token}`);
  });
  result.pages.forEach((page) => {
    const ownedSections = new Set(page.coverage
      .filter((entry) => !entry.presentation_copy)
      .map((entry) => Number(entry.section_id)));
    assert.strictEqual(ownedSections.size, 1, `page ${page.page_number} leaks across Part 0 sections`);
    assertHeaderFooter([page]);
  });
  [103, 104].forEach((sectionId) => {
    const page = result.pages.find((candidate) => candidate.section_id === sectionId);
    const table = page.metrics.block_measurements[0];
    assert.ok(table && Math.abs(table.frame.width - page.metrics.content_frame.width) < 0.75);
  });
}]);

cases.push(["AV. every generated definition row renders and paginates", () => {
  const rows = Array.from({ length: 24 }, (_, index) =>
    `<div class="cpb-part0-def-row" data-row="${index}" style="min-height:60px">`
    + `<strong>Defined term ${index + 1}</strong><span>Unique definition ${index + 1}</span></div>`
  ).join("");
  const source = sourceWith([
    section(108, "definitions", "0.6 Definitions and Terms", {
      is_part0: true,
      force_page_break_before: true,
      pagination_authority: "generated"
    }, [
      unit("definitions-all-rows", "generated",
        `<div class="cpb-part0">${rows}</div>`,
        { force_break_before: true, pagination_authority: "generated" })
    ], { pagination_authority: "generated" })
  ]);
  const { execution, result } = runWorker(source);
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.validation.is_valid, true);
  assert.ok(result.pages.length > 1, "definition rows did not continue onto another page");
  const html = result.pages.map((page) => page.page_html).join("");
  assert.strictEqual((html.match(/cpb-part0-def-row/g) || []).length, 24);
  for (let index = 1; index <= 24; index++) {
    assert.strictEqual(
      (html.match(new RegExp(`Unique definition ${index}(?!\\d)`, "g")) || []).length,
      1,
      `definition ${index} was missing or duplicated`
    );
  }
}]);

let failed = 0;
for (const [name, run] of cases) {
  try {
    run();
    process.stdout.write(`PASS ${name}\n`);
  } catch (error) {
    failed += 1;
    process.stderr.write(`FAIL ${name}: ${error && error.message || error}\n`);
  }
}

if (failed) {
  process.stderr.write(`live_authoritative_flow_v1 worker check: FAIL ${failed}/${cases.length}\n`);
  process.exit(1);
}
process.stdout.write(`AUTHORITATIVE_PAGINATION_WORKER_PASS cases=${cases.length}\n`);
