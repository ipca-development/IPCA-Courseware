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
    <div class="cpb-block-chrome" contenteditable="false"></div>
    <div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">First anchor text</div></div>
  </div>
  <div class="cpb-block cpb-block--paragraph" data-block-id="2" data-block-type="paragraph" data-stable-anchor="paragraph-2">
    <div class="cpb-block-chrome" contenteditable="false"></div>
    <div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">Alpha beta gamma delta</div></div>
  </div>
  <div class="cpb-block cpb-block--paragraph" data-block-id="3" data-block-type="paragraph" data-stable-anchor="paragraph-3">
    <div class="cpb-block-chrome" contenteditable="false"></div>
    <div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">Last anchor text</div></div>
  </div>`;

function renderDocument() {
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
    );
  return `<!doctype html><html><head><meta charset="utf-8">
    <style>${editorCss}</style>
    <style>
      body{margin:0}.cpb-editor-root{height:700px}#cpbCanvas{height:240px;overflow-y:auto;overflow-anchor:none;flex:none}
      .cpb-sheet{min-height:1000px}.cpb-block{box-sizing:border-box;min-height:260px;margin:0}
    </style>
    </head><body>${shell}</body></html>`;
}

async function installMock(page) {
  await page.evaluate(({ sourceBlocks }) => {
    const pageHtml = `<div class="cpb-sheet"><div class="cpb-sheet-body" data-blocks-root="1">${sourceBlocks}</div></div>`;
    const jsonResponse = (body, status = 200) => new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
    const deferred = () => {
      let resolve;
      const promise = new Promise((done) => { resolve = done; });
      return { promise, resolve };
    };
    window.__phaseB = {
      requests: [],
      committed: [],
      states: [],
      updatePlans: [],
      livePlans: { live_ensure: [], live_status: [] },
      pendingUpdates: [],
      pendingLive: [],
      activeByVersion: {},
      maxActiveByVersion: {},
      queueUpdate(plan) { this.updatePlans.push(plan); },
      queueLive(action, plan) { this.livePlans[action].push(plan); },
      resolveUpdate(index, payload) { this.pendingUpdates[index].resolve(payload); },
      resolveLive(index, payload) { this.pendingLive[index].deferred.resolve(payload); },
    };
    const root = document.querySelector('#cpbEditorRoot');
    root.addEventListener('cpb:source-mutation-committed', (event) => {
      window.__phaseB.committed.push(structuredClone(event.detail));
    });
    root.addEventListener('cpb:live-pagination-state', (event) => {
      window.__phaseB.states.push(structuredClone(event.detail));
    });
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
        const parsed = new URL(String(url), 'http://phase-b.test');
        action = String(parsed.searchParams.get('action') || '');
        payload = Object.fromEntries(parsed.searchParams.entries());
      }
      const request = { action, payload: structuredClone(payload), url: String(url) };
      window.__phaseB.requests.push(request);

      if (action === 'get_callout_presets') return jsonResponse({ ok: true, presets: [] });
      if (action === 'load') {
        return jsonResponse({
          ok: true,
          editable: true,
          section_id: 11,
          sections_tree: [{ id: 11, nav_id: '11', title: 'Phase B', children: [] }],
          section: { id: 11, section_key: 'main_content', title: 'Phase B' },
          version: { id: 7, book_key: 'phase-b-book', status: 'draft' },
          page_html: pageHtml,
          section_number_display: {},
          suggested_regulatory_refs: {},
          manual_code: 'PB',
        });
      }
      if (String(url).includes('controlled_book_page_break_api.php')) {
        return jsonResponse({ ok: true, breaks: [], candidates: [] });
      }
      if (action === 'section_index') {
        return jsonResponse({ ok: true, section_page_index: {}, page_count: 1 });
      }
      if (action === 'update_block') {
        const plan = window.__phaseB.updatePlans.shift() || { payload: { ok: true } };
        if (plan.defer) {
          const item = deferred();
          window.__phaseB.pendingUpdates.push(item);
          return jsonResponse(await item.promise);
        }
        return jsonResponse(plan.payload || { ok: true });
      }
      if (action === 'live_ensure' || action === 'live_status') {
        const version = String(payload.book_version_id);
        window.__phaseB.activeByVersion[version] =
          (window.__phaseB.activeByVersion[version] || 0) + 1;
        window.__phaseB.maxActiveByVersion[version] = Math.max(
          window.__phaseB.maxActiveByVersion[version] || 0,
          window.__phaseB.activeByVersion[version],
        );
        const plan = window.__phaseB.livePlans[action].shift()
          || { payload: { ok: true, status: 'current', source_hash: `hash-${payload.client_mutation_revision}` } };
        let responsePayload;
        if (plan.defer) {
          const item = deferred();
          window.__phaseB.pendingLive.push({ action, request, deferred: item });
          responsePayload = await item.promise;
        } else {
          responsePayload = plan.payload;
        }
        window.__phaseB.activeByVersion[version] -= 1;
        return jsonResponse(responsePayload);
      }
      return jsonResponse({ ok: true });
    };
  }, { sourceBlocks });
}

async function newEditorPage(browser) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const browserErrors = [];
  page.on('pageerror', (error) => browserErrors.push(error.message));
  await page.setContent(renderDocument(), { waitUntil: 'domcontentloaded' });
  await installMock(page);
  await page.addScriptTag({ content: editorJs });
  await page.waitForFunction(() =>
    document.querySelector('#cpbCanvas .cpb-block[data-block-id="2"]')
    && document.querySelector('#cpbEditorRoot').__cpbPhaseB
  );
  await page.waitForFunction(() =>
    document.querySelector('#cpbEditorRoot').getAttribute('aria-busy') === 'false'
  );
  await page.evaluate(() => {
    window.__phaseB.requests = window.__phaseB.requests.filter((request) =>
      !['get_callout_presets', 'load', 'list', 'section_index'].includes(request.action)
    );
  });
  return { page, browserErrors };
}

async function mutateAndBlur(page, html, blockId = 2) {
  await page.evaluate(({ html, blockId }) => {
    const field = document.querySelector(
      `.cpb-block[data-block-id="${blockId}"] .cpb-paragraph`,
    );
    field.focus();
    field.innerHTML = html;
    field.dispatchEvent(new InputEvent('input', { bubbles: true }));
    field.blur();
  }, { html, blockId });
}

async function waitForCount(page, expression, count, timeout = 4000) {
  await page.waitForFunction(
    ({ expression, count }) => {
      const mock = window.__phaseB;
      if (expression === 'updates') {
        return mock.requests.filter((request) => request.action === 'update_block').length >= count;
      }
      if (expression === 'ensures') {
        return mock.requests.filter((request) => request.action === 'live_ensure').length >= count;
      }
      if (expression === 'statuses') {
        return mock.requests.filter((request) => request.action === 'live_status').length >= count;
      }
      return mock.committed.length >= count;
    },
    { expression, count },
    { timeout },
  );
}

const cases = [];
function test(name, run) {
  cases.push({ name, run });
}

test('committed notification follows successful source mutation and preserves payload/anchor', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    await page.evaluate(() => window.__phaseB.queueUpdate({ defer: true }));
    await mutateAndBlur(page, '<strong>Committed</strong> source');
    await waitForCount(page, 'updates', 1);
    assert.equal(await page.evaluate(() => window.__phaseB.committed.length), 0);
    assert.equal(await page.evaluate(() =>
      window.__phaseB.requests.filter((request) => request.action === 'live_ensure').length
    ), 0);

    await page.evaluate(() => window.__phaseB.resolveUpdate(0, { ok: true }));
    await waitForCount(page, 'committed', 1);
    const observed = await page.evaluate(() => ({
      update: window.__phaseB.requests.find((request) => request.action === 'update_block'),
      committed: window.__phaseB.committed[0],
    }));
    assert.deepEqual(observed.update.payload, {
      action: 'update_block',
      version_id: 7,
      block_id: 2,
      payload: {
        html: '<strong>Committed</strong> source',
        paragraph_style: 'body',
        text_align: 'left',
        indent_level: 0,
      },
    });
    assert.equal(observed.committed.stable_anchor, 'paragraph-2');
    assert.equal(observed.committed.client_mutation_revision, 1);
    assert.equal(observed.committed.mutation_kind, 'update_block');
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('failed source mutation emits no commit and starts no live pagination', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    await page.evaluate(() => window.__phaseB.queueUpdate({ payload: { ok: false, error: 'rejected' } }));
    await mutateAndBlur(page, 'Rejected source');
    await waitForCount(page, 'updates', 1);
    await page.waitForTimeout(650);
    const observed = await page.evaluate(() => ({
      committed: window.__phaseB.committed.length,
      live: window.__phaseB.requests.filter((request) =>
        request.action === 'live_ensure' || request.action === 'live_status'
      ).length,
      revision: document.querySelector('#cpbEditorRoot').__cpbPhaseB
        .livePaginationState().mutationRevision,
    }));
    assert.deepEqual(observed, { committed: 0, live: 0, revision: 0 });
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('rapid commits coalesce with monotonic revisions and stable anchors', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    await mutateAndBlur(page, 'Rapid one');
    await mutateAndBlur(page, 'Rapid two');
    await mutateAndBlur(page, 'Rapid three');
    await waitForCount(page, 'committed', 3);
    await waitForCount(page, 'ensures', 1);
    await page.waitForTimeout(100);
    const observed = await page.evaluate(() => ({
      revisions: window.__phaseB.committed.map((event) => event.client_mutation_revision),
      anchors: window.__phaseB.committed.map((event) => event.stable_anchor),
      ensureRevisions: window.__phaseB.requests
        .filter((request) => request.action === 'live_ensure')
        .map((request) => request.payload.client_mutation_revision),
    }));
    assert.deepEqual(observed.revisions, [1, 2, 3]);
    assert.deepEqual(observed.anchors, ['paragraph-2', 'paragraph-2', 'paragraph-2']);
    assert.deepEqual(observed.ensureRevisions, [3]);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('one request stays active; newest pending runs next; stale result loses', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    await page.evaluate(() => {
      window.__phaseB.queueLive('live_ensure', { defer: true });
      window.__phaseB.queueLive('live_ensure', {
        payload: { ok: true, status: 'generating', source_hash: 'hash-3-pending' },
      });
      window.__phaseB.queueLive('live_status', {
        payload: { ok: true, status: 'current', source_hash: 'hash-3-current' },
      });
    });
    await mutateAndBlur(page, 'Active revision one');
    await waitForCount(page, 'ensures', 1);
    await mutateAndBlur(page, 'Pending revision two');
    await mutateAndBlur(page, 'Newest revision three');
    await waitForCount(page, 'committed', 3);
    await page.waitForTimeout(600);
    assert.deepEqual(await page.evaluate(() =>
      window.__phaseB.requests
        .filter((request) => request.action === 'live_ensure')
        .map((request) => request.payload.client_mutation_revision)
    ), [1]);

    await page.evaluate(() => window.__phaseB.resolveLive(0, {
      ok: true,
      status: 'current',
      source_hash: 'stale-hash-1',
    }));
    await waitForCount(page, 'ensures', 2);
    assert.deepEqual(await page.evaluate(() =>
      window.__phaseB.requests
        .filter((request) => request.action === 'live_ensure')
        .map((request) => request.payload.client_mutation_revision)
    ), [1, 3]);
    assert.equal(await page.evaluate(() =>
      document.querySelector('#cpbEditorRoot').__cpbPhaseB.livePaginationState().acceptedRevision
    ), 0);

    await waitForCount(page, 'statuses', 1, 5000);
    await page.waitForFunction(() =>
      document.querySelector('#cpbEditorRoot').__cpbPhaseB
        .livePaginationState().acceptedRevision === 3
    );
    const observed = await page.evaluate(() => {
      const state = document.querySelector('#cpbEditorRoot').__cpbPhaseB.livePaginationState();
      return {
        maxActive: window.__phaseB.maxActiveByVersion['7'],
        statusRevisions: window.__phaseB.requests
          .filter((request) => request.action === 'live_status')
          .map((request) => request.payload.client_mutation_revision),
        acceptedRevision: state.acceptedRevision,
        sourceHash: state.sourceHash,
        status: state.status,
      };
    });
    assert.deepEqual(observed, {
      maxActive: 1,
      statusRevisions: [3],
      acceptedRevision: 3,
      sourceHash: 'hash-3-current',
      status: 'current',
    });
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('semantic range, caret, and direction survive DOM replacement', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const observed = await page.evaluate(() => {
      const root = document.querySelector('#cpbEditorRoot');
      const hooks = root.__cpbPhaseB;
      const field = document.querySelector('[data-block-id="2"] .cpb-paragraph');
      const text = field.firstChild;
      field.focus();
      getSelection().setBaseAndExtent(text, 16, text, 6);
      const rangeBookmark = hooks.captureSelection();
      hooks.captureSurface('range-replacement');
      const blocksRoot = document.querySelector('[data-blocks-root]');
      blocksRoot.innerHTML = blocksRoot.innerHTML.replace(
        'Alpha beta gamma delta',
        '<span>Alpha beta</span> gamma delta',
      );
      const restoredRange = hooks.restoreSurface('range-replacement');
      const rangeAfter = hooks.captureSelection();
      const selectedText = getSelection().toString();

      const replacementField = document.querySelector('[data-block-id="2"] .cpb-paragraph');
      const replacementText = replacementField.lastChild;
      replacementField.focus();
      const caretRange = document.createRange();
      caretRange.setStart(replacementText, 3);
      caretRange.collapse(true);
      const selection = getSelection();
      selection.removeAllRanges();
      selection.addRange(caretRange);
      const caretBookmark = hooks.captureSelection();
      document.querySelector('[data-blocks-root]').innerHTML =
        document.querySelector('[data-blocks-root]').innerHTML;
      const restoredCaret = hooks.restoreSelection(caretBookmark);
      const caretAfter = hooks.captureSelection();
      return {
        restoredRange,
        rangeBefore: {
          anchor: rangeBookmark.anchor.source_offset,
          focus: rangeBookmark.focus.source_offset,
          direction: rangeBookmark.direction,
        },
        rangeAfter: {
          anchor: rangeAfter.anchor.source_offset,
          focus: rangeAfter.focus.source_offset,
          direction: rangeAfter.direction,
        },
        selectedText,
        restoredCaret,
        caretBefore: caretBookmark.anchor.source_offset,
        caretAfter: caretAfter.anchor.source_offset,
        caretCollapsed: caretAfter.is_collapsed,
      };
    });
    assert.deepEqual(observed, {
      restoredRange: true,
      rangeBefore: { anchor: 16, focus: 6, direction: 'backward' },
      rangeAfter: { anchor: 16, focus: 6, direction: 'backward' },
      selectedText: 'beta gamma',
      restoredCaret: true,
      caretBefore: 13,
      caretAfter: 13,
      caretCollapsed: true,
    });
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('scroll restore is stable-anchor relative after DOM replacement', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const observed = await page.evaluate(() => {
      const canvas = document.querySelector('#cpbCanvas');
      const hooks = document.querySelector('#cpbEditorRoot').__cpbPhaseB;
      canvas.scrollTop = 300;
      const bookmark = hooks.captureScroll();
      const beforeAnchor = document.querySelector(
        `.cpb-block[data-stable-anchor="${bookmark.stable_anchor}"]`,
      );
      const beforeOffset = beforeAnchor.getBoundingClientRect().top
        - canvas.getBoundingClientRect().top;
      const blocksRoot = document.querySelector('[data-blocks-root]');
      blocksRoot.insertAdjacentHTML(
        'afterbegin',
        '<div class="cpb-block" style="height:120px;min-height:120px">replacement spacer</div>',
      );
      const displacedOffset = document.querySelector(
        `.cpb-block[data-stable-anchor="${bookmark.stable_anchor}"]`,
      ).getBoundingClientRect().top - canvas.getBoundingClientRect().top;
      const preRestoreScroll = canvas.scrollTop;
      const restored = hooks.restoreScroll(bookmark);
      const afterAnchor = document.querySelector(
        `.cpb-block[data-stable-anchor="${bookmark.stable_anchor}"]`,
      );
      const afterOffset = afterAnchor.getBoundingClientRect().top
        - canvas.getBoundingClientRect().top;
      return {
        restored,
        stableAnchor: bookmark.stable_anchor,
        beforeOffset: Math.round(beforeOffset),
        afterOffset: Math.round(afterOffset),
        anchorDisplacement: Math.round(displacedOffset - beforeOffset),
        scrollDelta: Math.round(canvas.scrollTop - preRestoreScroll),
      };
    });
    assert.equal(observed.restored, true);
    assert.match(observed.stableAnchor, /^paragraph-[123]$/);
    assert.equal(observed.afterOffset, observed.beforeOffset);
    assert.ok(observed.anchorDisplacement > 0);
    assert.equal(observed.scrollDelta, observed.anchorDisplacement);
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

test('native content undo and redo behavior remains unchanged', async (browser) => {
  const { page, browserErrors } = await newEditorPage(browser);
  try {
    const field = page.locator('[data-block-id="2"] .cpb-paragraph');
    await page.evaluate(() => {
      const editable = document.querySelector('[data-block-id="2"] .cpb-paragraph');
      editable.focus();
      const range = document.createRange();
      range.selectNodeContents(editable);
      range.collapse(false);
      const selection = getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    });
    await page.keyboard.type('!');
    assert.equal(await field.textContent(), 'Alpha beta gamma delta!');
    await page.keyboard.press('ControlOrMeta+z');
    assert.equal(await field.textContent(), 'Alpha beta gamma delta');
    await page.keyboard.press('ControlOrMeta+Shift+z');
    assert.equal(await field.textContent(), 'Alpha beta gamma delta!');
  } finally {
    assert.deepEqual(browserErrors, []);
    await page.close();
  }
});

let browser;
let failures = 0;
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
      console.log(`FAIL ${testCase.name} — ${String(error.message || error).replace(/\s+/g, ' ')}`);
    }
  }
} catch (error) {
  failures += 1;
  console.error(`FAIL phase_b.browser_harness — ${error.message || error}`);
} finally {
  if (browser) await browser.close();
}

console.log(`\nPhase B browser requirements: executed=${cases.length} passed=${cases.length - failures} failed=${failures}`);
if (failures) {
  console.log('CONTROLLED_BOOK_EDITOR_PHASE_B: FAIL');
  process.exit(1);
}
console.log('CONTROLLED_BOOK_EDITOR_PHASE_B: PASS');
