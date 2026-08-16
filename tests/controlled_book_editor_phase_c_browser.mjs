#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const requireFromGarmin = createRequire(path.join(root, 'scripts/garmin/package.json'));
const { chromium } = requireFromGarmin('playwright');
const editorJs = fs.readFileSync(path.join(root, 'public/assets/controlled_book_editor.js'), 'utf8');
const editorCss = fs.readFileSync(path.join(root, 'public/assets/controlled_book_editor.css'), 'utf8');
const editorShell = fs.readFileSync(
  path.join(root, 'public/admin/compliance/controlled_book_editor.php'),
  'utf8',
);

const sourceBlocks = `
  <div class="cpb-block cpb-block--paragraph" data-block-id="1" data-block-type="paragraph" data-stable-anchor="paragraph-1">
    <div class="cpb-block-chrome" contenteditable="false"><button type="button">Source edit control</button></div>
    <div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">Alpha beta gamma delta</div></div>
  </div>
  <div class="cpb-block cpb-block--paragraph" data-block-id="2" data-block-type="paragraph" data-stable-anchor="paragraph-2">
    <div class="cpb-block-chrome" contenteditable="false"></div>
    <div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">Second editable source block</div></div>
  </div>
  <div class="cpb-block cpb-block--table" data-block-id="3" data-block-type="table" data-stable-anchor="table-3">
    <div class="cpb-block-chrome" contenteditable="false"></div>
    <div class="cpb-table-block cpb-table-block--align-left" data-table-align="left" data-table-style-kind="standard">
      <div class="cpb-table-wrap cpb-table-border-medium" data-border-width="medium" data-border-color="#94a3b8" style="width:280px;max-width:100%;--cpb-table-border-color:#94a3b8">
        <table class="cpb-table" data-field="table" style="width:280px">
          <colgroup><col style="width:140px"><col style="width:140px"></colgroup>
          <thead><tr class="cpb-table-title-row" data-title-row="1"><td colspan="2" contenteditable="true" data-field="cell">Table title</td></tr><tr class="cpb-table-header-row"><th contenteditable="true" data-field="cell"><span class="cpb-th-text">A</span></th><th contenteditable="true" data-field="cell"><span class="cpb-th-text">B</span></th></tr></thead>
          <tbody data-table-part="body"><tr><td contenteditable="true" data-field="cell">A1</td><td contenteditable="true" data-field="cell">B1</td></tr><tr><td contenteditable="true" data-field="cell">A2</td><td contenteditable="true" data-field="cell">B2</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>`;

function storedPage(sectionId, pageNumber, marker, width = 640, height = 900) {
  const fragmentId = `section-${sectionId}/paragraph-1/root`;
  const secondFragmentId = `section-${sectionId}/paragraph-2/root`;
  return {
    section_id: sectionId,
    page_number: pageNumber,
    page_html: `<article class="reader-generated-page proof-book-style" style="width:${width}px;height:${height}px">
      <header class="reader-page-header">Header ${marker}</header>
      <main class="reader-page-body" data-blocks-root="1">
        <div class="reader-semantic-piece" contenteditable="true"
          data-source-fragment-id="${fragmentId}" data-block-id="1" data-block-type="paragraph"
          data-stable-anchor="paragraph-1" data-source-range-start="0" data-source-range-end="22"
          data-source-length="22" data-presentation-copy="0" data-semantic-type="paragraph">
          <p>Body ${marker}</p><button type="button" onclick="window.__projectionClicked=1">Edit ${marker}</button>
        </div>
        <div class="reader-semantic-piece"
          data-source-fragment-id="${secondFragmentId}" data-block-id="2" data-block-type="paragraph"
          data-stable-anchor="paragraph-2" data-source-range-start="0" data-source-range-end="28"
          data-source-length="28" data-presentation-copy="0" data-semantic-type="paragraph">
          <p>Second body ${marker}</p>
        </div>
        <div class="reader-semantic-piece"
          data-source-fragment-id="section-${sectionId}/heading/root" data-block-id="3"
          data-block-type="heading" data-stable-anchor="heading-1"
          data-source-range-start="0" data-source-range-end="7"
          data-source-length="7" data-presentation-copy="0" data-semantic-type="heading">
          <h2>Heading</h2>
        </div>
        <div class="reader-semantic-piece"
          data-source-fragment-id="section-${sectionId}/paragraph-copy/repeat" data-block-id="1"
          data-block-type="paragraph" data-stable-anchor="paragraph-1"
          data-source-range-start="0" data-source-range-end="5"
          data-source-length="22" data-presentation-copy="1" data-semantic-type="paragraph">
          <p>Copy</p>
        </div>
      </main>
      <footer class="reader-page-footer">Footer ${marker}<span class="reader-page-number">${pageNumber}</span></footer>
    </article>`,
    metadata: {
      metrics: {
        content_frame: { x: 32, y: 90, width: width - 64, height: height - 160 },
        block_measurements: [{
          source_fragment_id: fragmentId,
          semantic_type: 'paragraph',
          frame: { x: 0, y: 0, width: width - 64, height: 80 },
        }, {
          source_fragment_id: secondFragmentId,
          semantic_type: 'paragraph',
          frame: { x: 0, y: 340, width: width - 64, height: 80 },
        }, {
          source_fragment_id: `section-${sectionId}/heading/root`,
          semantic_type: 'heading',
          frame: { x: 0, y: 440, width: width - 64, height: 50 },
        }, {
          source_fragment_id: `section-${sectionId}/paragraph-copy/repeat`,
          semantic_type: 'paragraph',
          frame: { x: 0, y: 510, width: width - 64, height: 40 },
        }],
      },
    },
  };
}

function previewResult(sectionId = 11, marker = 'initial', pageNumber = 41) {
  return {
    ok: true,
    result: {
      pages: [storedPage(sectionId, pageNumber, marker)],
      book_style_css: '.proof-book-style{color:rgb(12, 34, 56)} .reader-page-header{font-weight:700}',
      freshness: { is_current: true },
    },
  };
}

function renderDocument(search = '') {
  const start = editorShell.indexOf('<div class="cpb-editor-root" id="cpbEditorRoot"');
  const end = editorShell.indexOf('<script src="/assets/controlled_book_editor.js', start);
  if (start < 0 || end < 0) throw new Error('editor shell extraction boundary missing');
  const shell = editorShell
    .slice(start, end)
    .replace(/<\?[\s\S]*?\?>/g, '')
    .replace(/data-version-id="[^"]*"/, 'data-version-id="7"')
    .replace(/data-section-id="[^"]*"/, 'data-section-id="11"')
    .replace(
      'id="cpbEditorRoot"',
      'id="cpbEditorRoot" data-api-base="/mock/editor" data-document-type="manual"',
    )
    .replace(
      '<div class="cpb-canvas-scroll" id="cpbCanvas">',
      '<button type="button" id="cpbViewPaginated">Exact paginated view</button>'
        + '<div class="cpb-canvas-scroll" id="cpbCanvas">',
    );
  const base = `http://phase-c.test/${search ? `?${search}` : ''}`;
  return {
    html: `<!doctype html><html><head><meta charset="utf-8"><base href="${base}">
      <style>${editorCss}</style>
      <style>
        body{margin:0}.cpb-editor-root{height:700px}
        #cpbCanvas{height:260px;overflow-y:auto;overflow-anchor:none;flex:none}
        .cpb-sheet{min-height:1100px}.cpb-block{box-sizing:border-box;min-height:430px;margin:0}
      </style>
      </head><body>${shell}</body></html>`,
    url: base,
  };
}

async function installMock(page, initialPreview = previewResult()) {
  await page.evaluate(({ sourceBlocks, initialPreview }) => {
    const jsonResponse = (body, status = 200) => new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
    const deferred = () => {
      let resolve;
      const promise = new Promise((done) => { resolve = done; });
      return { promise, resolve };
    };
    window.__phaseC = {
      requests: [],
      previewPlans: [{ payload: initialPreview }],
      pendingPreviews: [],
      loadPlans: [],
      pendingLoads: [],
      pageRulePlans: [],
      pendingPageRules: [],
      rendered: [],
      activated: [],
      queuePreview(plan) { this.previewPlans.push(plan); },
      resolvePreview(index, payload) { this.pendingPreviews[index].deferred.resolve(payload); },
      queueLoad(plan) { this.loadPlans.push(plan); },
      resolveLoad(index) { this.pendingLoads[index].deferred.resolve(); },
      queuePageRules(plan) { this.pageRulePlans.push(plan); },
      resolvePageRules(index, payload) {
        this.pendingPageRules[index].deferred.resolve(payload || { ok: true, breaks: [], candidates: [] });
      },
    };
    document.querySelector('#cpbEditorRoot').addEventListener(
      'cpb:live-projection-rendered',
      (event) => window.__phaseC.rendered.push(structuredClone(event.detail)),
    );
    document.querySelector('#cpbEditorRoot').addEventListener(
      'cpb:projection-source-activated',
      (event) => window.__phaseC.activated.push(structuredClone(event.detail)),
    );
    window.alert = () => {};
    window.confirm = () => true;
    window.prompt = (_message, fallback = '') => fallback;
    window.fetch = async (url, options = {}) => {
      let action = '';
      let payload = {};
      if (options.body) {
        payload = JSON.parse(options.body);
        action = String(payload.action || '');
      } else {
        const parsed = new URL(String(url), 'http://phase-c.test');
        action = String(parsed.searchParams.get('action') || '');
        payload = Object.fromEntries(parsed.searchParams.entries());
      }
      const request = { action, payload: structuredClone(payload), url: String(url) };
      window.__phaseC.requests.push(request);

      if (action === 'get_callout_presets') return jsonResponse({ ok: true, presets: [] });
      if (action === 'load') {
        const sectionId = Number(payload.section_id || 11);
        const plan = window.__phaseC.loadPlans.shift() || {};
        if (plan.defer) {
          const item = deferred();
          window.__phaseC.pendingLoads.push({ request, deferred: item });
          await item.promise;
        }
        return jsonResponse({
          ok: true,
          editable: true,
          section_id: sectionId,
          sections_tree: [
            { id: 11, nav_id: '11', title: 'Section Eleven', children: [] },
            { id: 12, nav_id: '12', title: 'Section Twelve', children: [] },
          ],
          section: {
            id: sectionId,
            section_key: 'main_content',
            title: `Section ${sectionId}`,
          },
          version: { id: 7, book_key: 'phase-c-book', status: 'draft' },
          page_html: `<div class="cpb-sheet"><div class="cpb-sheet-body" data-blocks-root="1">${sourceBlocks}</div></div>`,
          section_number_display: {},
          suggested_regulatory_refs: {},
          manual_code: 'PC',
        });
      }
      if (action === 'stored_preview') {
        const plan = window.__phaseC.previewPlans.shift() || {
          payload: {
            ok: true,
            result: {
              pages: [],
              book_style_css: '',
              freshness: { is_current: true },
            },
          },
        };
        if (plan.defer) {
          const item = deferred();
          window.__phaseC.pendingPreviews.push({ request, deferred: item });
          return jsonResponse(await item.promise);
        }
        if (plan.status) return jsonResponse(plan.payload || {}, plan.status);
        return jsonResponse(plan.payload);
      }
      if (String(url).includes('controlled_book_page_break_api.php')) {
        const plan = window.__phaseC.pageRulePlans.shift() || {};
        if (plan.defer) {
          const item = deferred();
          window.__phaseC.pendingPageRules.push({ request, deferred: item });
          return jsonResponse(await item.promise);
        }
        return jsonResponse(plan.payload || { ok: true, breaks: [], candidates: [] });
      }
      if (action === 'section_index') {
        return jsonResponse({ ok: true, section_page_index: {}, page_count: 1 });
      }
      if (action === 'update_block') return jsonResponse({ ok: true });
      if (action === 'create_block' && payload.block_type === 'table') {
        const holder = document.createElement('div');
        holder.innerHTML = sourceBlocks;
        const tableBlock = holder.querySelector('[data-block-type="table"]');
        tableBlock.setAttribute('data-block-id', '100');
        tableBlock.setAttribute('data-stable-anchor', 'table-100');
        return jsonResponse({ ok: true, block_html: tableBlock.outerHTML });
      }
      return jsonResponse({ ok: true });
    };
  }, { sourceBlocks, initialPreview });
}

async function newEditorPage(browser, search = 'live_projection=1', initialPreview = previewResult()) {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const browserErrors = [];
  page.on('pageerror', (error) => browserErrors.push(error.message));
  const document = renderDocument(search);
  await page.route('http://phase-c.test/**', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: document.html,
  }));
  await page.goto(document.url, { waitUntil: 'domcontentloaded' });
  await installMock(page, initialPreview);
  await page.addScriptTag({ content: editorJs });
  await page.waitForFunction(() =>
    document.querySelector('#cpbCanvas .cpb-block[data-block-id="1"]')
    && document.querySelector('#cpbEditorRoot').__cpbPhaseC
  );
  await page.waitForFunction(() =>
    document.querySelector('#cpbEditorRoot').getAttribute('aria-busy') === 'false'
    && document.querySelector('#cpbSectionAssembly')?.hidden
  );
  if (search.includes('live_projection=1') && !search.includes('continuous_editor=1')) {
    await page.waitForFunction(() =>
      window.__phaseC.rendered.length >= 1
      && document.querySelectorAll('#cpbProjection .cpb-live-projection__page').length >= 1
    );
  }
  return { page, browserErrors };
}

async function dispatchLiveState(page, status) {
  await page.evaluate((nextStatus) => {
    document.querySelector('#cpbEditorRoot').dispatchEvent(new CustomEvent(
      'cpb:live-pagination-state',
      { detail: { status: nextStatus } },
    ));
  }, status);
}

async function waitForPreviewCount(page, count) {
  await page.waitForFunction(
    (expected) => window.__phaseC.requests.filter((request) =>
      request.action === 'stored_preview'
    ).length >= expected,
    count,
  );
}

async function projectionMarker(page) {
  const frame = page.locator('#cpbProjection iframe').contentFrame();
  await frame.locator('body').waitFor();
  return frame.locator('body').innerText();
}

const cases = [];
const phaseDCases = [];
function test(name, run) {
  cases.push({ name, run });
}
function testPhaseD(name, run) {
  const testCase = { name, run };
  cases.push(testCase);
  phaseDCases.push(testCase);
}

test('disabled by default makes no stored_preview call', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    await page.waitForTimeout(100);
    assert.equal(await page.locator('#cpbProjection').count(), 0);
    assert.equal(await page.evaluate(() =>
      window.__phaseC.requests.filter((request) => request.action === 'stored_preview').length
    ), 0);
    assert.equal(await page.evaluate(() =>
      document.querySelector('#cpbEditorRoot').__cpbPhaseC.enabled
    ), false);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('section canvas stays covered until fonts, page rules, and final geometry are ready', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    await page.evaluate(() => {
      window.__phaseC.assemblies = [];
      document.querySelector('#cpbEditorRoot').addEventListener(
        'cpb:section-assembly-complete',
        (event) => window.__phaseC.assemblies.push(structuredClone(event.detail)),
      );
      window.__phaseC.queuePageRules({ defer: true });
      document.querySelector('#cpbCanvas').scrollTop = 480;
    });
    await page.getByTitle('Section Twelve').click();
    await page.waitForFunction(() => window.__phaseC.pendingPageRules.length === 1);
    const loading = await page.evaluate(() => {
      const root = document.querySelector('#cpbEditorRoot');
      const overlay = document.querySelector('#cpbSectionAssembly');
      const canvas = document.querySelector('#cpbCanvas');
      return {
        busy: root.getAttribute('aria-busy'),
        overlayVisible: !overlay.hidden,
        progress: Number(document.querySelector('#cpbSectionAssemblyProgress').textContent.replace('%', '')),
        canvasVisibility: getComputedStyle(canvas).visibility,
        label: document.querySelector('#cpbSectionAssemblyLabel').textContent,
      };
    });
    assert.equal(loading.busy, 'true');
    assert.equal(loading.overlayVisible, true);
    assert.ok(loading.progress >= 52, JSON.stringify(loading));
    assert.equal(loading.canvasVisibility, 'hidden');
    assert.match(loading.label, /fonts|images|page rules/i);

    await page.evaluate(() => window.__phaseC.resolvePageRules(0));
    await page.waitForFunction(() =>
      document.querySelector('#cpbEditorRoot').getAttribute('aria-busy') === 'false'
      && document.querySelector('#cpbSectionAssembly').hidden
      && window.__phaseC.assemblies.length === 1
    );
    const ready = await page.evaluate(() => ({
      activeSection: document.querySelector('.cpb-tree-link.is-active')?.title,
      printLayout: document.querySelector('#cpbCanvas .cpb-sheet')?.classList.contains('cpb-print-layout'),
      furniture: document.querySelectorAll('#cpbCanvas .cpb-print-furniture-layer').length,
      status: document.querySelector('#cpbSaveStatus').textContent,
      scrollTop: document.querySelector('#cpbCanvas').scrollTop,
      assembly: window.__phaseC.assemblies[0],
    }));
    assert.equal(ready.activeSection, 'Section Twelve');
    assert.equal(ready.printLayout, true);
    assert.ok(ready.furniture >= 1);
    assert.equal(ready.status, 'Ready');
    assert.equal(ready.scrollTop, 0);
    assert.equal(ready.assembly.section_id, 12);
    assert.equal(ready.assembly.complete, true);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('a slower prior section response cannot replace the newest selected section', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    await page.evaluate(() => {
      window.__phaseC.queueLoad({ defer: true });
      window.__phaseC.queueLoad({ defer: true });
    });
    await page.getByTitle('Section Twelve').click();
    await page.waitForFunction(() => window.__phaseC.pendingLoads.length === 1);
    await page.getByTitle('Section Eleven').click();
    await page.waitForFunction(() => window.__phaseC.pendingLoads.length === 2);

    await page.evaluate(() => window.__phaseC.resolveLoad(1));
    await page.waitForFunction(() =>
      document.querySelector('#cpbEditorRoot').getAttribute('aria-busy') === 'false'
      && document.querySelector('.cpb-tree-link.is-active')?.title === 'Section Eleven'
    );
    await page.evaluate(() => window.__phaseC.resolveLoad(0));
    await page.waitForTimeout(150);
    const observed = await page.evaluate(() => ({
      activeSection: document.querySelector('.cpb-tree-link.is-active')?.title,
      status: document.querySelector('#cpbSaveStatus').textContent,
      busy: document.querySelector('#cpbEditorRoot').getAttribute('aria-busy'),
    }));
    assert.deepEqual(observed, {
      activeSection: 'Section Eleven',
      status: 'Ready',
      busy: 'false',
    });
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('long paragraphs and table rows never enter repeated header or footer bands', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    await page.evaluate(() => {
      document.querySelectorAll('#cpbCanvas .cpb-block').forEach((block) => {
        block.style.minHeight = '0';
      });
      const paragraph = document.querySelector('#cpbCanvas [data-block-id="1"] .cpb-paragraph');
      paragraph.innerHTML = Array.from(
        { length: 70 },
        (_, index) => `<div>Long paragraph continuation line ${index + 1}</div>`,
      ).join('');
      const tbody = document.querySelector('#cpbCanvas [data-block-id="3"] tbody');
      const template = tbody.querySelector('tr:not([data-auto-page-break="1"])');
      const initialRows = tbody.querySelectorAll('tr:not([data-auto-page-break="1"])').length;
      for (let index = initialRows; index < 14; index += 1) {
        const row = template.cloneNode(true);
        row.removeAttribute('data-auto-page-break');
        row.removeAttribute('data-editor-only');
        row.classList.remove('cpb-table-page-spacer');
        row.querySelectorAll('td').forEach((cell, cellIndex) => {
          cell.textContent = `Continuation row ${index + 1}, cell ${cellIndex + 1}`;
          cell.style.height = '62px';
        });
        tbody.appendChild(row);
      }
      paragraph.dispatchEvent(new InputEvent('input', { bubbles: true }));
      tbody.querySelector(
        'tr:not([data-auto-page-break="1"]) td'
      ).dispatchEvent(new InputEvent('input', { bubbles: true }));
    });
    await page.waitForTimeout(1800);
    const geometry = await page.evaluate(() => {
      const sheet = document.querySelector('#cpbCanvas .cpb-sheet');
      const body = sheet.querySelector('[data-blocks-root="1"]');
      const sheetTop = sheet.getBoundingClientRect().top;
      const stride = 1056 + 28;
      const contentTop = 152;
      const contentBottom = 152 + 744;
      const violations = [];
      const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
      let node = walker.nextNode();
      while (node) {
        const owner = node.parentElement;
        if (
          node.nodeValue.trim()
          && !owner.closest('[data-editor-only="1"]')
        ) {
          const range = document.createRange();
          range.selectNodeContents(node);
          Array.from(range.getClientRects()).forEach((rect) => {
            if (rect.width <= 0 && rect.height <= 0) return;
            const y = rect.top - sheetTop;
            const pageIndex = Math.max(0, Math.floor(y / stride));
            const localTop = y - pageIndex * stride;
            const localBottom = rect.bottom - sheetTop - pageIndex * stride;
            if (
              pageIndex > 0
              && (localTop < contentTop - 1 || localBottom > contentBottom + 1)
            ) {
              violations.push({
                text: node.nodeValue.trim().slice(0, 40),
                pageIndex,
                localTop,
                localBottom,
              });
            }
          });
        }
        node = walker.nextNode();
      }
      const saves = window.__phaseC.requests.filter((request) =>
        request.action === 'update_block' && Number(request.payload.block_id) === 3
      );
      return {
        inlineSpacers: body.querySelectorAll('span.cpb-flow-page-break--automatic').length,
        tableSpacers: body.querySelectorAll('tr.cpb-table-page-spacer').length,
        sourceRows: body.querySelectorAll(
          '[data-block-id="3"] tbody tr:not([data-auto-page-break="1"])'
        ).length,
        savedRows: saves.at(-1)?.payload.payload.rows.length,
        violations,
      };
    });
    assert.ok(geometry.inlineSpacers >= 1, JSON.stringify(geometry));
    assert.ok(geometry.tableSpacers >= 1, JSON.stringify(geometry));
    assert.equal(geometry.savedRows, geometry.sourceRows);
    assert.deepEqual(geometry.violations, []);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('styled paragraph headings move intact instead of gaining an inline page gap', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    await page.evaluate(() => {
      document.querySelectorAll('#cpbCanvas .cpb-block').forEach((block) => {
        block.style.minHeight = '0';
      });
      const lead = document.querySelector('#cpbCanvas [data-block-id="1"]');
      const heading = document.querySelector(
        '#cpbCanvas [data-block-id="2"] .cpb-paragraph'
      );
      lead.style.height = '720px';
      heading.setAttribute('data-paragraph-style', 'subtitle_1');
      heading.classList.add('cpb-ps-subtitle_1');
      heading.innerHTML = '2.5 Aircraft Journey Logs<br>Operational records';
      heading.dispatchEvent(new InputEvent('input', { bubbles: true }));
    });
    await page.waitForTimeout(1000);
    const observed = await page.evaluate(() => {
      const sheet = document.querySelector('#cpbCanvas .cpb-sheet');
      const block = document.querySelector('#cpbCanvas [data-block-id="2"]');
      const heading = block.querySelector('.cpb-paragraph');
      const stride = 1056 + 28;
      const top = (
        block.getBoundingClientRect().top - sheet.getBoundingClientRect().top
      );
      const pageIndex = Math.max(0, Math.floor(top / stride));
      return {
        inlineSpacers: heading.querySelectorAll('[data-auto-page-break="1"]').length,
        precedingSpacer: block.previousElementSibling?.getAttribute('data-auto-page-break'),
        localTop: top - pageIndex * stride,
      };
    });
    assert.equal(observed.inlineSpacers, 0, JSON.stringify(observed));
    assert.equal(observed.precedingSpacer, '1', JSON.stringify(observed));
    assert.ok(
      observed.localTop >= 151 && observed.localTop <= 190,
      JSON.stringify(observed)
    );
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('enabled creates #cpbProjection alongside unchanged #cpbCanvas and source stays editable', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const observed = await page.evaluate(() => ({
      stageChildren: Array.from(document.querySelector('#cpbEditorStage').children).map((node) => node.id),
      canvasCount: document.querySelectorAll('#cpbCanvas').length,
      projectionCount: document.querySelectorAll('#cpbProjection').length,
      sourceEditable: document.querySelector('#cpbCanvas .cpb-paragraph').isContentEditable,
      sourceParent: document.querySelector('#cpbCanvas').parentElement.id,
      sourceText: document.querySelector('#cpbCanvas .cpb-paragraph').textContent,
    }));
    assert.deepEqual(observed, {
      stageChildren: ['cpbCanvas', 'cpbProjection'],
      canvasCount: 1,
      projectionCount: 1,
      sourceEditable: true,
      sourceParent: 'cpbEditorStage',
      sourceText: 'Alpha beta gamma delta',
    });
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('paragraph control bar stays above paragraph content', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    await page.locator('#cpbCanvas [data-block-id="1"] .cpb-paragraph').focus();
    const geometry = await page.locator('#cpbCanvas [data-block-id="1"]').evaluate((block) => {
      const chrome = block.querySelector('.cpb-block-chrome');
      const paragraphRect = block.querySelector('.cpb-paragraph').getBoundingClientRect();
      const chromeRect = chrome.getBoundingClientRect();
      return {
        cssTop: getComputedStyle(chrome).top,
        contentClearance: paragraphRect.top - chromeRect.bottom,
      };
    });
    assert.equal(geometry.cssTop, '-25px');
    assert.ok(geometry.contentClearance >= 0, JSON.stringify(geometry));
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('table controls stay in the second toolbar row and replace floating tools', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    await page.setViewportSize({ width: 1024, height: 768 });
    const toolbar = page.locator('#cpbTableToolbar');
    await toolbar.waitFor();
    assert.equal(await toolbar.isVisible(), true);
    assert.equal(await page.locator('#cpbCanvas .cpb-table-tools').count(), 0);
    const toolbarLayout = await page.locator('#cpbToolbarMain').evaluate((main) => ({
      rows: main.querySelectorAll('.cpb-toolbar-row').length,
      overflow: Array.from(main.querySelectorAll('.cpb-toolbar-row')).map((row) =>
        Math.max(0, row.scrollWidth - row.clientWidth)
      ),
      tableLines: new Set(Array.from(
        main.querySelectorAll('[data-table-action]')
      ).map((control) => control.closest('.cpb-toolbar-row'))).size,
      toolbarHeight: Math.round(main.closest('.cpb-toolbar').getBoundingClientRect().height),
      rowHeights: Array.from(main.querySelectorAll('.cpb-toolbar-row')).map((row) =>
        Math.round(row.getBoundingClientRect().height)
      ),
      buttonHeight: Math.round(
        main.querySelector('button.cpb-tool-btn').getBoundingClientRect().height
      ),
      primaryRight: Math.max(...Array.from(
        main.querySelectorAll('.cpb-toolbar-row--primary button, .cpb-toolbar-row--primary select, .cpb-toolbar-row--primary input')
      ).map((control) => control.getBoundingClientRect().right)),
      sharedLeft: document.querySelector('#cpbToolbarShared').getBoundingClientRect().left,
    }));
    assert.equal(toolbarLayout.rows, 3);
    assert.equal(toolbarLayout.tableLines, 2, 'table controls must occupy two toolbar rows');
    assert.deepEqual(toolbarLayout.overflow, [0, 0, 0]);
    assert.equal(toolbarLayout.toolbarHeight, 70);
    assert.deepEqual(toolbarLayout.rowHeights, [23, 23, 23]);
    assert.equal(toolbarLayout.buttonHeight, 18);
    assert.ok(toolbarLayout.primaryRight <= toolbarLayout.sharedLeft);
    assert.equal(await toolbar.locator('[data-table-action="table-align-center"]').isDisabled(), true);

    const clickedCell = page.locator(
      '#cpbCanvas [data-block-id="3"] tbody tr:not([data-auto-page-break="1"])'
    ).nth(1).locator('td').nth(1);
    await clickedCell.evaluate((cell) => {
      cell.addEventListener('click', () => {
        cell.closest('table').querySelector('[data-title-row] td').focus();
      }, { once: true });
    });
    await clickedCell.click();
    await page.keyboard.type(' typed');
    await page.waitForFunction(() =>
      window.__phaseC.requests.some((request) =>
        request.action === 'update_block' && Number(request.payload.block_id) === 3
      )
    );
    await page.waitForTimeout(600);
    await page.keyboard.type(' after-save');
    const clickFocus = await page.evaluate(() => {
      const block = document.querySelector('#cpbCanvas [data-block-id="3"]');
      const target = block.querySelectorAll(
        'tbody tr:not([data-auto-page-break="1"])'
      )[1].querySelectorAll('td')[1];
      return {
        activeTarget: document.activeElement === target,
        targetText: target.textContent,
        titleText: block.querySelector('[data-title-row] td').textContent,
      };
    });
    assert.equal(clickFocus.activeTarget, true);
    assert.match(clickFocus.targetText, /typed after-save$/);
    assert.equal(clickFocus.titleText, 'Table title');

    const fillControl = toolbar.locator('[data-table-action="cell-bg"]');
    const savesBeforeColor = await page.evaluate(() =>
      window.__phaseC.requests.filter((request) =>
        request.action === 'update_block' && Number(request.payload.block_id) === 3
      ).length
    );
    await fillControl.focus();
    await fillControl.evaluate((input) => {
      input.value = '#123456';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.waitForTimeout(850);
    assert.equal(
      await page.evaluate(() => window.__phaseC.requests.filter((request) =>
        request.action === 'update_block' && Number(request.payload.block_id) === 3
      ).length),
      savesBeforeColor
    );
    await fillControl.evaluate((input) => {
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForFunction((before) =>
      window.__phaseC.requests.filter((request) =>
        request.action === 'update_block' && Number(request.payload.block_id) === 3
      ).length === before + 1,
    savesBeforeColor);
    await page.waitForTimeout(1000);
    const colorAfterSave = await page.evaluate(() => {
      const block = document.querySelector('#cpbCanvas [data-block-id="3"]');
      const target = block.querySelectorAll(
        'tbody tr:not([data-auto-page-break="1"])'
      )[1].querySelectorAll('td')[1];
      const fill = document.querySelector('#cpbTableToolbar [data-table-action="cell-bg"]');
      return {
        activeFill: document.activeElement === fill,
        cellFill: target.getAttribute('data-cell-bg'),
        saves: window.__phaseC.requests.filter((request) =>
          request.action === 'update_block' && Number(request.payload.block_id) === 3
        ).length,
      };
    });
    assert.equal(colorAfterSave.activeFill, true);
    assert.equal(colorAfterSave.cellFill, '#123456');
    assert.equal(colorAfterSave.saves, savesBeforeColor + 1, 'color commit must issue exactly one save');

    await page.locator(
      '#cpbCanvas [data-block-id="3"] tbody tr:not([data-auto-page-break="1"])'
    ).first().locator('td').first().click();
    assert.equal(await toolbar.locator('[data-table-action="table-align-center"]').isEnabled(), true);
    await toolbar.locator('[data-table-action="table-align-center"]').click();
    const alignment = await page.evaluate(() => ({
      value: document.querySelector('#cpbCanvas [data-block-id="3"] .cpb-table-block')
        .getAttribute('data-table-align'),
      disabled: document.querySelector('#cpbTableToolbar [data-table-action="table-align-center"]').disabled,
      activeElement: document.activeElement?.getAttribute('data-table-action') || document.activeElement?.tagName,
      updates: window.__phaseC.requests.filter((request) => request.action === 'update_block'),
    }));
    assert.equal(alignment.value, 'center', JSON.stringify(alignment));

    await toolbar.locator('[data-table-action="merge-cells-down"]').click();
    const merged = await page.evaluate(() => {
      const block = document.querySelector('#cpbCanvas [data-block-id="3"]');
      const sourceRows = block.querySelectorAll(
        'tbody tr:not([data-auto-page-break="1"])'
      );
      const first = sourceRows[0].querySelector('td:first-child');
      const covered = sourceRows[1].querySelector('td:first-child');
      const saves = window.__phaseC.requests.filter((request) =>
        request.action === 'update_block' && Number(request.payload.block_id) === 3
      );
      return {
        rowspan: first.rowSpan,
        covered: covered.dataset.rowspanCovered,
        payload: saves.at(-1)?.payload.payload,
      };
    });
    assert.equal(merged.rowspan, 2, 'vertical merge must span exactly two rows');
    assert.equal(merged.covered, '1');
    assert.deepEqual(merged.payload.row_rowspans, [[2, 1], [0, 1]]);

    await toolbar.locator('[data-table-action="copy-table"]').click();
    await toolbar.locator('[data-table-action="paste-table"]').click();
    await page.waitForFunction(() =>
      document.querySelectorAll('#cpbCanvas [data-block-type="table"]').length === 2
    );
    const create = await page.evaluate(() =>
      window.__phaseC.requests.find((request) =>
        request.action === 'create_block' && request.payload.block_type === 'table'
      )
    );
    assert.equal(create.payload.insert_after_block_id, 3);
    assert.deepEqual(create.payload.payload.row_rowspans, [[2, 1], [0, 1]]);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('Enter caret stays on the new paragraph through layout and autosave', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser, '');
  try {
    const field = page.locator('#cpbCanvas [data-block-id="1"] .cpb-paragraph');
    await field.evaluate((element) => {
      element.focus();
      const range = document.createRange();
      range.selectNodeContents(element);
      range.collapse(false);
      const selection = getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    });
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1100);
    const afterSave = await field.evaluate((element) => {
      const selection = getSelection();
      const node = selection.anchorNode;
      return {
        active: document.activeElement === element,
        inField: !!node && (node === element || element.contains(node)),
        html: element.innerHTML,
      };
    });
    assert.equal(afterSave.active, true);
    assert.equal(afterSave.inField, true);
    await page.keyboard.type('New line');
    await page.waitForTimeout(800);
    assert.match(await field.innerText(), /New line$/);
    const saved = await page.evaluate(() => {
      const requests = window.__phaseC.requests.filter((request) =>
        request.action === 'update_block' && Number(request.payload.block_id) === 1
      );
      return requests.at(-1)?.payload.payload.html || '';
    });
    assert.match(saved, /New line/);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('stored current page stack renders exact dimensions/header/footer/page number from page_html and dedicated iframe CSS', async (browser) => {
  const initial = {
    ok: true,
    result: {
      pages: [
        storedPage(11, 41, 'first', 640, 900),
        storedPage(11, 42, 'second', 612, 792),
      ],
      book_style_css: '.proof-book-style{color:rgb(12, 34, 56)} .reader-page-footer{letter-spacing:3px}',
      freshness: { is_current: true },
    },
  };
  const { page, browserErrors } = await newEditorPage(browser, 'live_projection=1', initial);
  try {
    const cards = await page.locator('#cpbProjection .cpb-live-projection__page').evaluateAll((nodes) =>
      nodes.map((node) => ({
        page: node.dataset.pageNumber,
        section: node.dataset.sectionId,
        width: node.style.width,
        height: node.style.height,
        label: node.querySelector('.cpb-live-projection__page-label').textContent,
        frameWidth: node.querySelector('iframe').style.width,
        frameHeight: node.querySelector('iframe').style.height,
      }))
    );
    assert.deepEqual(cards, [
      { page: '41', section: '11', width: '640px', height: '900px', label: 'Page 41', frameWidth: '640px', frameHeight: '900px' },
      { page: '42', section: '11', width: '612px', height: '792px', label: 'Page 42', frameWidth: '612px', frameHeight: '792px' },
    ]);
    const firstFrame = page.locator('#cpbProjection iframe').first().contentFrame();
    assert.equal(await firstFrame.locator('.reader-page-header').textContent(), 'Header first');
    assert.match(await firstFrame.locator('.reader-page-footer').textContent(), /Footer first41/);
    assert.equal(await firstFrame.locator('.reader-page-number').textContent(), '41');
    assert.equal(
      await firstFrame.locator('.proof-book-style').evaluate((node) => getComputedStyle(node).color),
      'rgb(12, 34, 56)',
    );
    assert.equal(
      await firstFrame.locator('.reader-page-footer').evaluate((node) => getComputedStyle(node).letterSpacing),
      '3px',
    );
    assert.equal(await page.locator('head style').evaluateAll((styles) =>
      styles.some((style) => style.textContent.includes('.proof-book-style{color:rgb(12, 34, 56)}'))
    ), false);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('every projection node is noneditable and has no active edit controls', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const outer = await page.locator('#cpbProjection').evaluate((projection) => ({
      editable: Array.from(projection.querySelectorAll('*')).filter((node) => node.isContentEditable).length,
      controls: projection.querySelectorAll('button,input,textarea,select,[contenteditable="true"]').length,
      frameSandbox: projection.querySelector('iframe').getAttribute('sandbox'),
      frameTabIndex: projection.querySelector('iframe').getAttribute('tabindex'),
      framePointerEvents: getComputedStyle(projection.querySelector('iframe')).pointerEvents,
    }));
    assert.deepEqual(outer, {
      editable: 0,
      controls: 0,
      frameSandbox: '',
      frameTabIndex: '-1',
      framePointerEvents: 'none',
    });
    const frame = page.locator('#cpbProjection iframe').contentFrame();
    const inner = await frame.locator('body').evaluate((body) => {
      const nodes = [body, ...body.querySelectorAll('*')];
      return {
        editable: nodes.filter((node) => node.isContentEditable).length,
        trueAttributes: body.querySelectorAll('[contenteditable="true"]').length,
        activeControls: Array.from(body.querySelectorAll('button,input,textarea,select')).filter(
          (node) => !node.disabled || node.tabIndex !== -1,
        ).length,
        inlineHandlers: Array.from(body.querySelectorAll('*')).filter((node) =>
          Array.from(node.attributes).some((attribute) => /^on/i.test(attribute.name))
        ).length,
      };
    });
    assert.deepEqual(inner, {
      editable: 0,
      trueAttributes: 0,
      activeControls: 0,
      inlineHandlers: 0,
    });
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('projection refresh occurs only after cpb live status current and uses section-filtered stored_preview', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const baseline = await page.evaluate(() =>
      window.__phaseC.requests.filter((request) => request.action === 'stored_preview').length
    );
    for (const status of ['pending', 'generating', 'stale', 'failed']) {
      await dispatchLiveState(page, status);
    }
    await page.waitForTimeout(100);
    assert.equal(await page.evaluate(() =>
      window.__phaseC.requests.filter((request) => request.action === 'stored_preview').length
    ), baseline);

    await page.evaluate((payload) => window.__phaseC.queuePreview({ payload }), previewResult(11, 'current-event', 52));
    await dispatchLiveState(page, 'current');
    await waitForPreviewCount(page, baseline + 1);
    await page.waitForFunction(() => window.__phaseC.rendered.length >= 2);
    const request = await page.evaluate(() =>
      window.__phaseC.requests.filter((row) => row.action === 'stored_preview').at(-1)
    );
    assert.deepEqual(request.payload, {
      action: 'stored_preview',
      book_version_id: '7',
      section_id: '11',
      include_style: '1',
      check_freshness: '1',
    });
    assert.match(await projectionMarker(page), /current-event/);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('pending/generating keeps last valid projection and failed with prior pages keeps them', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const before = await projectionMarker(page);
    for (const status of ['pending', 'generating']) {
      await dispatchLiveState(page, status);
      assert.equal(await projectionMarker(page), before);
      assert.equal(await page.locator('#cpbProjectionStatus').getAttribute('data-state'), status);
    }
    await dispatchLiveState(page, 'failed');
    assert.equal(await projectionMarker(page), before);
    assert.equal(await page.locator('#cpbProjectionStatus').getAttribute('data-state'), 'failed');
    assert.match(await page.locator('#cpbProjectionStatus').textContent(), /showing last valid map/);

    const baseline = await page.evaluate(() =>
      window.__phaseC.requests.filter((request) => request.action === 'stored_preview').length
    );
    await page.evaluate(() => window.__phaseC.queuePreview({
      status: 500,
      payload: { ok: false, error: 'preview unavailable' },
    }));
    await dispatchLiveState(page, 'current');
    await waitForPreviewCount(page, baseline + 1);
    await page.waitForFunction(() =>
      document.querySelector('#cpbProjectionStatus').dataset.state === 'failed'
    );
    assert.equal(await projectionMarker(page), before);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('projection refresh does not alter source activeElement/selection/undo/redo/canvas scroll', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const field = page.locator('#cpbCanvas [data-block-id="1"] .cpb-paragraph');
    await page.evaluate(() => {
      const canvas = document.querySelector('#cpbCanvas');
      const editable = document.querySelector('#cpbCanvas [data-block-id="1"] .cpb-paragraph');
      canvas.scrollTop = 175;
      editable.focus();
      const range = document.createRange();
      range.selectNodeContents(editable);
      range.collapse(false);
      const selection = getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    });
    await page.keyboard.type('!');
    await page.evaluate(() => window.__phaseC.queuePreview({ defer: true }));
    const baseline = await page.evaluate(() =>
      window.__phaseC.requests.filter((request) => request.action === 'stored_preview').length
    );
    await dispatchLiveState(page, 'current');
    await waitForPreviewCount(page, baseline + 1);
    const before = await page.evaluate(() => {
      const selection = getSelection();
      const field = document.querySelector('#cpbCanvas [data-block-id="1"] .cpb-paragraph');
      return {
        active: document.activeElement === field,
        text: field.textContent,
        anchorOffset: selection.anchorOffset,
        focusOffset: selection.focusOffset,
        selectedText: selection.toString(),
        scrollTop: document.querySelector('#cpbCanvas').scrollTop,
      };
    });
    await page.evaluate((payload) => window.__phaseC.resolvePreview(0, payload), previewResult(11, 'safe-refresh', 61));
    await page.waitForFunction(() => window.__phaseC.rendered.length >= 2);
    const after = await page.evaluate(() => {
      const selection = getSelection();
      const field = document.querySelector('#cpbCanvas [data-block-id="1"] .cpb-paragraph');
      return {
        active: document.activeElement === field,
        text: field.textContent,
        anchorOffset: selection.anchorOffset,
        focusOffset: selection.focusOffset,
        selectedText: selection.toString(),
        scrollTop: document.querySelector('#cpbCanvas').scrollTop,
      };
    });
    assert.deepEqual(after, before);
    await page.keyboard.press('ControlOrMeta+z');
    assert.equal(await field.textContent(), 'Alpha beta gamma delta');
    await page.keyboard.press('ControlOrMeta+Shift+z');
    assert.equal(await field.textContent(), 'Alpha beta gamma delta!');
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('stale response for prior section/request cannot replace current projection', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    await page.evaluate(() => window.__phaseC.queuePreview({ defer: true }));
    const baseline = await page.evaluate(() =>
      window.__phaseC.requests.filter((request) => request.action === 'stored_preview').length
    );
    await dispatchLiveState(page, 'current');
    await waitForPreviewCount(page, baseline + 1);

    await page.evaluate((payload) => window.__phaseC.queuePreview({ payload }), previewResult(12, 'section-twelve', 72));
    await page.getByText('Section Twelve', { exact: true }).click();
    await waitForPreviewCount(page, baseline + 2);
    await page.waitForFunction(() =>
      document.querySelector('#cpbProjection .cpb-live-projection__page')?.dataset.sectionId === '12'
    );
    assert.match(await projectionMarker(page), /section-twelve/);

    await page.evaluate((payload) => window.__phaseC.resolvePreview(0, payload), previewResult(11, 'stale-eleven', 71));
    await page.waitForTimeout(100);
    assert.match(await projectionMarker(page), /section-twelve/);
    assert.doesNotMatch(await projectionMarker(page), /stale-eleven/);
    assert.equal(
      await page.locator('#cpbProjection .cpb-live-projection__page').getAttribute('data-section-id'),
      '12',
    );
    const requests = await page.evaluate(() =>
      window.__phaseC.requests
        .filter((request) => request.action === 'stored_preview')
        .slice(-2)
        .map((request) => request.payload.section_id)
    );
    assert.deepEqual(requests, ['11', '12']);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('mixed-section stored preview renders every authoritative page returned for the selected section', async (browser) => {
  const initial = {
    ok: true,
    result: {
      pages: [
        {
          ...storedPage(9, 89, 'mixed-page'),
          metadata: {
            coverage: [
              { source_fragment_id: 'section-9/block-a/root', section_id: 9 },
              { source_fragment_id: 'section-11/block-b/root', section_id: 11 },
            ],
          },
        },
        storedPage(11, 90, 'primary-page'),
      ],
      page_count: 277,
      returned_page_count: 2,
      book_style_css: '.proof-book-style{color:rgb(12, 34, 56)}',
      freshness: { is_current: true },
    },
  };
  const { page, browserErrors } = await newEditorPage(browser, 'live_projection=1', initial);
  try {
    const pages = await page.locator('#cpbProjection .cpb-live-projection__page').evaluateAll((nodes) =>
      nodes.map((node) => ({
        page: node.dataset.pageNumber,
        primarySection: node.dataset.sectionId,
      }))
    );
    assert.deepEqual(pages, [
      { page: '89', primarySection: '9' },
      { page: '90', primarySection: '11' },
    ]);
    assert.match(await page.locator('#cpbProjectionStatus').textContent(), /2 pages · Current/);
    await page.evaluate((payload) => window.__phaseC.queuePreview({ payload }), initial);
    await page.locator('#cpbViewPaginated').click();
    await page.waitForFunction(() =>
      /Page 89 · 1 of 2 in this section/.test(
        document.querySelector('.cpb-pagination-page-navigation strong')?.textContent || ''
      )
    );
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('source canvas labels its page furniture as approximate editing layout', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    await page.waitForFunction(() =>
      document.querySelector('#cpbCanvas .cpb-print-page[data-page-identity="editing-layout-approximate"]')
    );
    const sourceFurniture = await page.locator('#cpbCanvas .cpb-print-page').first().innerText();
    assert.match(sourceFurniture, /Page:\s*Editing layout/);
    assert.doesNotMatch(sourceFurniture, /Page:\s*\d+/);
    assert.equal(
      await page.locator('#cpbProjection .cpb-live-projection__page-label').first().textContent(),
      'Page 41',
    );
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('zoom applies to projection', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    assert.equal(
      await page.locator('#cpbProjectionPages').evaluate((node) =>
        node.style.getPropertyValue('--cpb-projection-zoom')
      ),
      '1',
    );
    await page.locator('#cpbZoomIn').click();
    assert.equal(await page.locator('#cpbZoomLabel').textContent(), '110%');
    assert.equal(
      await page.locator('#cpbProjectionPages').evaluate((node) =>
        node.style.getPropertyValue('--cpb-projection-zoom')
      ),
      '1.1',
    );
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

testPhaseD('paragraph portal activates only the canonical source object', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    assert.equal(await page.locator('#cpbProjection .cpb-live-projection__portal').count(), 2);
    assert.deepEqual(
      await page.locator('#cpbProjection .cpb-live-projection__portal').evaluateAll((portals) =>
        portals.map((portal) => portal.dataset.stableAnchor)
      ),
      ['paragraph-1', 'paragraph-2'],
    );
    await page.locator(
      '#cpbProjection .cpb-live-projection__portal[data-stable-anchor="paragraph-1"]'
    ).click();
    const observed = await page.evaluate(() => {
      const root = document.querySelector('#cpbEditorRoot');
      const active = document.activeElement;
      const bookmark = root.__cpbPhaseB.captureSelection();
      return {
        activeClass: active.className,
        sourceBlock: active.closest('.cpb-block').dataset.stableAnchor,
        sourceOffset: bookmark.anchor.source_offset,
        activated: window.__phaseC.activated,
        portalEditable: document.querySelectorAll(
          '#cpbProjection [contenteditable="true"]'
        ).length,
      };
    });
    assert.match(observed.activeClass, /(?:^|\s)cpb-paragraph(?:\s|$)/);
    assert.equal(observed.sourceBlock, 'paragraph-1');
    assert.equal(observed.sourceOffset, 0);
    assert.equal(observed.portalEditable, 0);
    assert.deepEqual(observed.activated.at(-1), {
      section_id: 11,
      block_id: 1,
      stable_anchor: 'paragraph-1',
      block_type: 'paragraph',
      source_offset: 0,
    });
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

testPhaseD('paragraph portal typing changes source only and preserves the baseline save payload', async (browser) => {
  const portalEditor = await newEditorPage(browser);
  const directEditor = await newEditorPage(browser);
  try {
    await portalEditor.page.locator(
      '#cpbProjection .cpb-live-projection__portal[data-stable-anchor="paragraph-1"]'
    ).click();
    const frame = portalEditor.page.locator('#cpbProjection iframe').first().contentFrame();
    const projectionBefore = await frame.locator('.reader-semantic-piece').first().innerText();
    await portalEditor.page.keyboard.type('X');
    await portalEditor.page.waitForFunction(() =>
      window.__phaseC.requests.some((request) => request.action === 'update_block')
    );
    assert.equal(
      await portalEditor.page.locator(
        '#cpbCanvas [data-stable-anchor="paragraph-1"] .cpb-paragraph'
      ).innerText(),
      'XAlpha beta gamma delta',
    );
    assert.equal(
      await frame.locator('.reader-semantic-piece').first().innerText(),
      projectionBefore,
    );

    const directField = directEditor.page.locator(
      '#cpbCanvas [data-stable-anchor="paragraph-1"] .cpb-paragraph'
    );
    await directField.evaluate((field) => {
      field.focus();
      const range = document.createRange();
      range.setStart(field.firstChild, 0);
      range.collapse(true);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    });
    await directEditor.page.keyboard.type('X');
    await directEditor.page.waitForFunction(() =>
      window.__phaseC.requests.some((request) => request.action === 'update_block')
    );
    const payloads = await Promise.all([portalEditor.page, directEditor.page].map((editorPage) =>
      editorPage.evaluate(() =>
        window.__phaseC.requests.find((request) => request.action === 'update_block').payload
      )
    ));
    assert.deepEqual(payloads[0], payloads[1]);
  } finally {
    assert.deepEqual(portalEditor.browserErrors, []);
    assert.deepEqual(directEditor.browserErrors, []);
    await portalEditor.page.close();
    await directEditor.page.close();
  }
});

testPhaseD('paragraph portal refresh preserves focus backward selection caret and source scroll', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    await page.locator(
      '#cpbProjection .cpb-live-projection__portal[data-stable-anchor="paragraph-2"]'
    ).click();
    await page.evaluate(() => {
      const hooks = document.querySelector('#cpbEditorRoot').__cpbPhaseB;
      const bookmark = hooks.captureSelection();
      bookmark.anchor.source_offset = 6;
      bookmark.focus.source_offset = 2;
      bookmark.direction = 'backward';
      bookmark.is_collapsed = false;
      hooks.restoreSelection(bookmark);
    });
    const before = await page.evaluate(() => ({
      scroll: document.querySelector('#cpbCanvas').scrollTop,
      selection: document.querySelector('#cpbEditorRoot').__cpbPhaseB.captureSelection(),
      rendered: window.__phaseC.rendered.length,
    }));
    assert.ok(before.scroll > 0);
    assert.equal(before.selection.direction, 'backward');
    assert.equal(before.selection.anchor.source_offset, 6);
    assert.equal(before.selection.focus.source_offset, 2);

    await page.evaluate((payload) => window.__phaseC.queuePreview({ payload }),
      previewResult(11, 'paragraph-refresh', 41));
    await dispatchLiveState(page, 'current');
    await page.waitForFunction((count) => window.__phaseC.rendered.length > count, before.rendered);
    const after = await page.evaluate(() => {
      const active = document.activeElement;
      return {
        activeAnchor: active.closest('.cpb-block').dataset.stableAnchor,
        scroll: document.querySelector('#cpbCanvas').scrollTop,
        selection: document.querySelector('#cpbEditorRoot').__cpbPhaseB.captureSelection(),
      };
    });
    assert.equal(after.activeAnchor, 'paragraph-2');
    assert.equal(after.scroll, before.scroll);
    assert.equal(after.selection.direction, 'backward');
    assert.equal(after.selection.anchor.source_offset, 6);
    assert.equal(after.selection.focus.source_offset, 2);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

testPhaseD('paragraph portal editing retains undo and redo behavior', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const field = page.locator(
      '#cpbCanvas [data-stable-anchor="paragraph-1"] .cpb-paragraph'
    );
    await page.locator(
      '#cpbProjection .cpb-live-projection__portal[data-stable-anchor="paragraph-1"]'
    ).click();
    await page.keyboard.type('Z');
    assert.equal(await field.innerText(), 'ZAlpha beta gamma delta');
    await page.locator('#cpbUndo').click();
    assert.equal(await page.locator(
      '#cpbCanvas [data-stable-anchor="paragraph-1"] .cpb-paragraph'
    ).innerText(), 'Alpha beta gamma delta');
    await page.locator('#cpbRedo').click();
    assert.equal(await page.locator(
      '#cpbCanvas [data-stable-anchor="paragraph-1"] .cpb-paragraph'
    ).innerText(), 'ZAlpha beta gamma delta');
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('continuous_editor=1 emergency fallback disables live projection', async (browser) => {
  const { page, browserErrors } = await newEditorPage(
    browser,
    'live_projection=1&continuous_editor=1',
  );
  try {
    await page.waitForTimeout(100);
    assert.equal(await page.locator('#cpbProjection').count(), 0);
    assert.equal(await page.evaluate(() =>
      document.querySelector('#cpbEditorRoot').__cpbPhaseC.enabled
    ), false);
    assert.equal(await page.evaluate(() =>
      window.__phaseC.requests.filter((request) => request.action === 'stored_preview').length
    ), 0);
    assert.equal(
      await page.locator('#cpbCanvas .cpb-paragraph').first().getAttribute('contenteditable'),
      'true',
    );
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

let browser;
let failures = 0;
let phaseDFailures = 0;
try {
  try {
    browser = await chromium.launch({ headless: true });
  } catch (bundledError) {
    browser = await chromium.launch({ headless: true, channel: 'chrome' }).catch(() => {
      throw bundledError;
    });
  }
  for (const testCase of cases) {
    try {
      await testCase.run(browser);
      console.log(`PASS ${testCase.name}`);
    } catch (error) {
      failures += 1;
      if (phaseDCases.includes(testCase)) phaseDFailures += 1;
      console.log(`FAIL ${testCase.name} — ${String(error.message || error).replace(/\s+/g, ' ')}`);
    }
  }
} catch (error) {
  failures += 1;
  console.error(`FAIL phase_c.browser_harness — ${error.message || error}`);
} finally {
  if (browser) await browser.close();
}

const phaseCCaseCount = cases.length - phaseDCases.length;
const phaseCFailures = failures - phaseDFailures;
console.log(`\nPhase C browser requirements: executed=${phaseCCaseCount} passed=${phaseCCaseCount - phaseCFailures} failed=${phaseCFailures}`);
console.log(`Phase D paragraph requirements: executed=${phaseDCases.length} passed=${phaseDCases.length - phaseDFailures} failed=${phaseDFailures}`);
if (failures) {
  console.log('CONTROLLED_BOOK_EDITOR_PHASE_C: FAIL');
  console.log('CONTROLLED_BOOK_EDITOR_PHASE_D_PARAGRAPH: FAIL');
  process.exit(1);
}
console.log('CONTROLLED_BOOK_EDITOR_PHASE_C: PASS');
console.log('CONTROLLED_BOOK_EDITOR_PHASE_D_PARAGRAPH: PASS');
