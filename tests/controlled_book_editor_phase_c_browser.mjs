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
  </div>`;

function storedPage(sectionId, pageNumber, marker, width = 640, height = 900) {
  return {
    section_id: sectionId,
    page_number: pageNumber,
    page_html: `<article class="reader-generated-page proof-book-style" style="width:${width}px;height:${height}px">
      <header class="reader-page-header">Header ${marker}</header>
      <main><div contenteditable="true">Body ${marker}</div><button type="button" onclick="window.__projectionClicked=1">Edit ${marker}</button></main>
      <footer class="reader-page-footer">Footer ${marker}<span class="reader-page-number">${pageNumber}</span></footer>
    </article>`,
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
      rendered: [],
      queuePreview(plan) { this.previewPlans.push(plan); },
      resolvePreview(index, payload) { this.pendingPreviews[index].deferred.resolve(payload); },
    };
    document.querySelector('#cpbEditorRoot').addEventListener(
      'cpb:live-projection-rendered',
      (event) => window.__phaseC.rendered.push(structuredClone(event.detail)),
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
        return jsonResponse({ ok: true, breaks: [], candidates: [] });
      }
      if (action === 'section_index') {
        return jsonResponse({ ok: true, section_page_index: {}, page_count: 1 });
      }
      if (action === 'update_block') return jsonResponse({ ok: true });
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
function test(name, run) {
  cases.push({ name, run });
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
  console.error(`FAIL phase_c.browser_harness — ${error.message || error}`);
} finally {
  if (browser) await browser.close();
}

console.log(`\nPhase C browser requirements: executed=${cases.length} passed=${cases.length - failures} failed=${failures}`);
if (failures) {
  console.log('CONTROLLED_BOOK_EDITOR_PHASE_C: FAIL');
  process.exit(1);
}
console.log('CONTROLLED_BOOK_EDITOR_PHASE_C: PASS');
