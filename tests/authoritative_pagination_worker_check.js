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
  .cpb-sheet { padding: 48px 56px 64px; min-height: 1056px; }
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

function sourceCoverage(result) {
  return result.pages.flatMap((page) =>
    page.coverage.filter((item) => item.presentation_copy !== true)
  );
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
      unit("toc-2-0", "toc", `<section><h1>Table of Contents</h1><nav class="cpb-toc">${tocRows}</nav></section>`, { block_id: 20 })
    ])
  ]));
  assert.strictEqual(execution.status, 0, execution.stderr || execution.stdout);
  assert.ok(result.pages.length >= 3, "TOC must continue across physical pages");
  assertHeaderFooter(result.pages);
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
