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
  header_band_px: 84,
  header_margin_bottom_px: 20,
  footer_margin_top_px: 24,
  footer_band_px: 72,
  body_capacity_px: 744
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
  .cpb-page-footer { margin-top: 24px; }
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
    header_template: '<header class="cpb-page-header"><div style="height:96px"><strong>Fixture Manual</strong></div></header>',
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
    header_template_html: '<header class="cpb-page-header"><div style="height:96px"><strong>Fixture Manual</strong></div></header>',
    footer_template_html: '<footer class="cpb-page-footer"><span>Controlled copy</span><span class="cpb-page-number">{{PAGE_NUMBER}}</span></footer>',
    sections
  };
}

function runWorker(source) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "ipca-manual-segments-"));
  const inputPath = path.join(directory, "input.json");
  const outputPath = path.join(directory, "output.json");
  fs.writeFileSync(inputPath, JSON.stringify({ source, book_style_css: bookStyleCSS }));
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
  assert.strictEqual(result.engine_version, "manual-segments-v1");
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
  assert.strictEqual(result.error && result.error.code, "MANUAL_BREAK_REQUIRED");
  assert.ok((result.pages || []).length === 0, "no partial page map may be produced");
  assert.ok(String(result.error.before_block_title || result.error.message).includes("6.1.8"));
  assert.strictEqual(result.error.before_block_anchor, "block-two");
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
  assert.strictEqual(result.error && result.error.code, "MANUAL_BREAK_REQUIRED");
  assert.strictEqual(result.error.before_block_anchor, "block-after");
  assert.ok((result.pages || []).length === 0, "no partial page map may be produced");
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

cases.push(["S. following ordinary content cannot use leftover LEP continuation space", () => {
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
  assert.strictEqual(result.error && result.error.code, "MANUAL_BREAK_REQUIRED");
  assert.strictEqual(result.error.before_block_anchor, "block-after-lep");
  assert.ok((result.pages || []).length === 0, "no partial page map may be produced");
}]);

cases.push(["T. Part 0 content outside LEP still requires Manual Page Breaks", () => {
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
        '<article data-block-id="52" data-stable-anchor="block-two"><p class="tall-block">0.2 Revision system body still requires a Manual Page Break.</p></article>',
        { block_id: 52 }
      )
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.strictEqual(result.error && result.error.code, "MANUAL_BREAK_REQUIRED");
  assert.strictEqual(result.error.before_block_anchor, "block-two");
  assert.ok((result.pages || []).length === 0, "no partial page map may be produced");
  assert.ok(String(result.error.before_block_title || "").includes("0.2 Revision system"));
}]);

cases.push(["U. MANUAL_BREAK_REQUIRED title uses a clean heading, not concatenated labels", () => {
  const { execution, result } = runWorker(sourceWith([
    section(4, "outline", "0. OUTLINE", { is_part0: true }, [
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
  assert.strictEqual(result.error && result.error.code, "MANUAL_BREAK_REQUIRED");
  const title = String(result.error.before_block_title || "");
  assert.ok(!/[A-Za-z]0\./.test(title), `concatenated structural title leaked: ${title}`);
  assert.strictEqual(title, "0.1 List of effective Parts");
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

cases.push(["Z. following author-editable content returns to manual-break authority", () => {
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
  assert.strictEqual(result.error && result.error.code, "MANUAL_BREAK_REQUIRED");
  assert.strictEqual(result.error.before_block_anchor, "author-after-generated");
  assert.ok((result.pages || []).length === 0, "no partial page map may be produced");
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
  process.stderr.write(`manual_segments_v1 worker check: FAIL ${failed}/${cases.length}\n`);
  process.exit(1);
}
process.stdout.write(`AUTHORITATIVE_PAGINATION_WORKER_PASS cases=${cases.length}\n`);
