#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const requireFromGarmin = createRequire(path.join(root, 'scripts/garmin/package.json'));
const { chromium } = requireFromGarmin('playwright');
const reportPath = path.join(root, 'docs/qa/controlled-book-editor-44df02b9-parity.md');
const editorPaths = {
  js: 'public/assets/controlled_book_editor.js',
  css: 'public/assets/controlled_book_editor.css',
  shell: 'public/admin/compliance/controlled_book_editor.php',
};
const report = fs.readFileSync(reportPath, 'utf8');
const rows = [...report.matchAll(/^\|[^|\n]+\|[^|\n]+\|[^|\n]+\|[^|\n]+\|\s*`([^`]+)`\s*\|$/gm)]
  .map((match) => match[1]);
const expectedRowCount = 67;

if (rows.length !== expectedRowCount || new Set(rows).size !== expectedRowCount) {
  console.error(`FAIL parity.contract_rows — expected ${expectedRowCount} unique named rows, found ${rows.length}`);
  process.exit(1);
}

const gitBaseline = (relativePath) => execFileSync(
  'git',
  ['show', `44df02b9:${relativePath}`],
  { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
);
let baselineAssets;
try {
  baselineAssets = {
    js: gitBaseline(editorPaths.js),
    css: gitBaseline(editorPaths.css),
    shell: gitBaseline(editorPaths.shell),
  };
} catch (error) {
  console.error('FAIL parity.baseline_available — cannot read 44df02b9 editor JS/CSS/shell assets');
  process.exit(1);
}
const currentAssets = Object.fromEntries(
  Object.entries(editorPaths).map(([kind, relativePath]) => [kind, fs.readFileSync(path.join(root, relativePath), 'utf8')]),
);

function renderVariantDocument(assets) {
  const start = assets.shell.indexOf('<div class="cpb-editor-root" id="cpbEditorRoot"');
  const end = assets.shell.indexOf('<script src="/assets/controlled_book_editor.js', start);
  if (start < 0 || end < 0) throw new Error('editor shell extraction boundary missing');
  let rootMarkup = assets.shell.slice(start, end).replace(/<\?[\s\S]*?\?>/g, '');
  rootMarkup = rootMarkup
    .replace(/data-version-id="[^"]*"/, 'data-version-id="7"')
    .replace(/data-section-id="[^"]*"/, 'data-section-id="11"')
    .replace(/data-initial-view="[^"]*"/, 'data-initial-view="edit"')
    .replace('id="cpbEditorRoot"', 'id="cpbEditorRoot" data-api-base="/mock/editor" data-document-type="form"');
  if (!rootMarkup.includes('id="cpbToolbar"') || !rootMarkup.includes('id="cpbCanvas"')) {
    throw new Error('extracted editor shell lacks toolbar or canvas');
  }
  return `<!doctype html><html><head><meta charset="utf-8"><style>${assets.css}</style>
    <style>body{margin:0}.cmp-page{height:900px}.cpb-editor-root{height:850px}#cpbCanvas{height:300px;flex:none}
    .cpb-block{position:relative;margin:8px}.cpb-table-tools{display:block}.cpb-print-furniture-layer{pointer-events:none}</style>
    </head><body><div class="cmp-page">${rootMarkup}</div></body></html>`;
}

function browserFixture(mode) {
  const chrome = '<div class="cpb-block-chrome" contenteditable="false"><button data-action="insert-paragraph">insert</button><button data-action="move-up">up</button><button data-action="move-down">down</button><button data-action="delete">delete</button></div>';
  const tableTools = `<div class="cpb-table-tools" aria-hidden="true"><span class="cpb-table-tools__selection"></span><button data-table-tools-close>close</button>
    ${['add-row','del-row','add-col','del-col','toggle-title','merge-cells-right','unmerge-cells','copy-cells','paste-cells','cell-align-center','border-thick','delete-table'].map((a) => `<button data-table-action="${a}">${a}</button>`).join('')}
    <input data-table-action="cell-bg" type="color" value="#ffffff"><input data-table-action="cell-text-color" type="color" value="#0f172a"><input data-table-action="border-color" type="color" value="#94a3b8">
  </div>`;
  const blocks = `
    <div class="cpb-block cpb-block--heading" data-block-id="1" data-block-type="heading" data-stable-anchor="heading-1">${chrome}<div class="cpb-heading-row"><span class="cpb-section-number">1.</span><h2 class="cpb-heading" contenteditable="true" data-field="text" data-level="2" data-paragraph-style="subtitle_1">Baseline heading</h2></div></div>
    <div class="cpb-block cpb-block--paragraph" data-block-id="2" data-block-type="paragraph" data-stable-anchor="paragraph-2">${chrome}<div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">Alpha beta gamma</div></div></div>
    <div class="cpb-block cpb-block--paragraph" data-block-id="3" data-block-type="paragraph" data-stable-anchor="paragraph-3">${chrome}<div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">Second paragraph</div></div></div>
    <div class="cpb-block cpb-block--list" data-block-id="4" data-block-type="list" data-stable-anchor="list-4">${chrome}<ol class="cpb-list" start="3" contenteditable="true" data-field="items" data-paragraph-style="body"><li>One</li><li>Two</li><li>Three</li></ol></div>
    <div class="cpb-block cpb-block--list" data-block-id="5" data-block-type="list" data-stable-anchor="list-5">${chrome}<ul class="cpb-list" contenteditable="true" data-field="items" data-paragraph-style="body"><li>Bullet A</li><li>Bullet B</li></ul></div>
    <div class="cpb-block cpb-block--table" data-block-id="6" data-block-type="table" data-stable-anchor="table-6">${chrome}<div class="cpb-table-block cpb-table-block--align-left" data-table-align="left" data-table-style-kind="standard"><div class="cpb-table-wrap cpb-table-border-medium" data-border-width="medium" data-border-color="#94a3b8"><table class="cpb-table" data-field="table"><colgroup><col style="width:140px"><col style="width:140px"></colgroup><thead><tr data-title-row="1" class="cpb-table-title-row"><td colspan="2" contenteditable="true">Fixture table</td></tr><tr class="cpb-table-header-row"><th contenteditable="true"><span class="cpb-th-text">H1</span><span class="cpb-col-resize" data-col-index="0"></span></th><th contenteditable="true"><span class="cpb-th-text">H2</span><span class="cpb-col-resize" data-col-index="1"></span></th></tr></thead><tbody data-table-part="body"><tr><td contenteditable="true">A1</td><td contenteditable="true">B1</td></tr><tr><td contenteditable="true">A2</td><td contenteditable="true">B2</td></tr></tbody></table></div>${tableTools}</div></div>
    <div class="cpb-block cpb-block--callout" data-block-id="7" data-block-type="callout" data-stable-anchor="callout-7">${chrome}<div class="cpb-callout cpb-callout--warning" data-callout-type="warning"><div class="cpb-callout-title" contenteditable="true" data-field="callout_title">WARNING</div><div class="cpb-callout-text" contenteditable="true" data-field="callout_text">Fixture warning</div></div></div>
    <div class="cpb-block cpb-block--image" data-block-id="8" data-block-type="image" data-stable-anchor="image-8">${chrome}<figure class="cpb-image cpb-image--editable" data-field="image" data-width-pct="60" style="width:60%"><div class="cpb-image-frame"><img src="/fixture.png"><button class="cpb-image-rotate">rotate</button><span class="cpb-image-resize"></span></div><figcaption contenteditable="true" data-field="alt">Fixture caption</figcaption></figure></div>
    <div class="cpb-block cpb-block--field" data-block-id="9" data-block-type="field">${chrome}<div data-form-field="1" data-field-key="student" data-field-type="text" data-label="Student" data-required="0" data-assigned-role="instructor" data-variable-key="" data-placeholder=""><span class="cpb-form-field-label">Student</span><span class="cpb-form-role">instructor</span><span class="cpb-form-input-line"></span></div></div>`;
  let page = `<div class="cpb-sheet"><div class="cpb-page-header">Header {page}</div><div class="cpb-sheet-body" data-blocks-root="1">${blocks}</div><div class="cpb-page-footer">Footer {page}</div></div>`;
  if (mode === 'cover') page = '<div class="cpb-sheet cpb-sheet--cover"><div contenteditable="true" data-cover-field="company_name">EuroPilot</div><div contenteditable="true" data-cover-field="registration_number">ATO-1</div><div contenteditable="true" data-cover-field="manual_title">Manual</div><div data-cover-drop="logo"></div></div>';
  if (mode === 'toc') page = '<div class="cpb-sheet cpb-sheet--toc"><div class="cpb-toc-row" data-canonical-section-ref="1"><span>1. Fixture</span></div></div>';
  if (mode === 'lep') page = '<div class="cpb-sheet cpb-sheet--lep"><div contenteditable="true" data-lep-field="certification_text">Certified</div><div contenteditable="true" data-lep-field="on_behalf_text">Authority</div><div contenteditable="true" data-lep-field="heading_subtitle_2">Effective Parts</div></div>';
  if (mode === 'part0') page = '<div class="cpb-sheet cpb-sheet--part0"><div contenteditable="true" data-part0-field="heading_title" data-paragraph-style="title">Definitions</div><div class="cpb-part0-def-row"><span contenteditable="true" data-part0-col="term">Term</span><span contenteditable="true" data-part0-col="definition">Meaning</span></div></div>';
  if (mode === 'annex') page = `<div class="cpb-sheet cpb-sheet--annex"><div data-annex-link="12">Annex A</div><div data-blocks-root="1">${blocks}</div></div>`;
  return { page, blocks, chrome };
}

async function installMock(page, mode) {
  const fixture = browserFixture(mode);
  await page.evaluate(({ fixture, mode }) => {
    const root = document.querySelector('#cpbEditorRoot');
    if (!root) throw new Error('variant editor shell did not render cpbEditorRoot');
    const listStart = root.querySelector('#cpbListStart');
    if (listStart) {
      listStart.innerHTML = Array.from({ length: 100 }, (_, index) => `<option value="${index + 1}">${index + 1}</option>`).join('');
      listStart.value = '1';
    }
    root.insertAdjacentHTML('beforeend', `
      <button data-form-tool="field" id="cpbAddField">Field</button>
      <button id="cpbFormFieldSettings">Field settings</button>
      <button id="cpbFormVariablePicker">Variables</button>
      <select id="cpbFormVariableSelect"><option value=""></option><option value="student.full_name">Student full name</option></select>
    `);
    window.__requests = [];
    window.__fixturePage = fixture.page;
    window.__fixtureBlocks = fixture.blocks;
    window.alert = () => {};
    window.confirm = () => true;
    window.prompt = (_message, fallback = '') => fallback || 'Fixture';
    window.open = (url) => { window.__openedUrl = url; };
    Element.prototype.scrollIntoView = function () { this.setAttribute('data-scrolled', '1'); };
    Date.now = () => 1700000000000;
    const response = (body) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
    const blockHtml = (type, id) => {
      const chrome = fixture.chrome;
      if (type === 'table') return `<div class="cpb-block cpb-block--table" data-block-id="${id}" data-block-type="table">${chrome}<div class="cpb-table-block" data-table-align="left"><div class="cpb-table-wrap cpb-table-border-medium"><table class="cpb-table"><colgroup><col style="width:140px"><col style="width:140px"></colgroup><thead><tr class="cpb-table-header-row"><th contenteditable="true"><span class="cpb-th-text">Column 1</span></th><th contenteditable="true"><span class="cpb-th-text">Column 2</span></th></tr></thead><tbody data-table-part="body"><tr><td contenteditable="true"></td><td contenteditable="true"></td></tr></tbody></table></div></div></div>`;
      if (type === 'callout') return `<div class="cpb-block cpb-block--callout" data-block-id="${id}" data-block-type="callout">${chrome}<div class="cpb-callout cpb-callout--warning" data-callout-type="warning"><div class="cpb-callout-title" contenteditable="true">WARNING</div><div class="cpb-callout-text" contenteditable="true">New callout</div></div></div>`;
      if (['field', 'checkbox', 'date', 'signature', 'initial'].includes(type)) return `<div class="cpb-block cpb-block--${type}" data-block-id="${id}" data-block-type="${type}">${chrome}<div data-form-field="1" data-field-key="${type}_1700000000000" data-field-type="${type}" data-label="${type}" data-required="0" data-assigned-role="instructor"></div></div>`;
      return `<div class="cpb-block cpb-block--paragraph" data-block-id="${id}" data-block-type="paragraph">${chrome}<div class="cpb-paragraph-row"><div class="cpb-paragraph" contenteditable="true" data-field="html" data-paragraph-style="body">New paragraph</div></div></div>`;
    };
    window.fetch = async (url, options = {}) => {
      let action = '';
      let payload = {};
      if (options.body instanceof FormData) {
        for (const [key, value] of options.body.entries()) payload[key] = value instanceof File ? { name: value.name, type: value.type, size: value.size } : value;
        action = payload.action || '';
      } else if (options.body) {
        payload = JSON.parse(options.body);
        action = payload.action || '';
      } else {
        const parsed = new URL(String(url), 'http://fixture.test');
        action = parsed.searchParams.get('action') || '';
        payload = Object.fromEntries(parsed.searchParams.entries());
      }
      window.__requests.push({ action, payload });
      if (action === 'get_callout_presets') return response({ ok: true, presets: [
        { callout_type: 'warning', title: 'WARNING', text: '' }, { callout_type: 'note', title: 'NOTE', text: '' },
        { callout_type: 'caution', title: 'CAUTION', text: '' }, { callout_type: 'info', title: 'INFO', text: '' },
      ] });
      if (action === 'wizard_changes') return response({ ok: true, changes: null });
      if (action === 'load') return response({
        ok: true, section_id: 11, editable: true, sections_tree: [{ id: 11, nav_id: '11', section_key: 'main_content', title: 'Fixture', children: [{ id: 12, nav_id: '12', title: 'Child', children: [] }] }],
        section: { id: 11, section_key: mode === 'annex' ? 'annexes' : 'main_content', title: 'Fixture' }, page_html: fixture.page,
        is_cover_section: mode === 'cover', is_toc_section: mode === 'toc', is_lep_section: mode === 'lep',
        is_part0_section: mode === 'part0', part0_section_key: mode === 'part0' ? 'definitions' : '', part0_structured: mode === 'part0',
        is_annex_register_section: mode === 'annex', cross_ref_annex: { ORO: [{ key: 'ORO.GEN.110', label: 'ORO.GEN.110' }] },
        cover_page: { company_name: 'EuroPilot', registration_number: 'ATO-1', manual_title: 'Manual' },
        lep_page: { certification_text: 'Certified', on_behalf_text: 'Authority', headings: [{ key: 'subtitle_2', style: 'subtitle_2', text: 'Effective Parts' }], signatories: [] },
        lep_approval_url: '/approval/fixture',
        part0_page: { entries: [{ term: 'Term', definition: 'Meaning' }], empty_rows: 0 },
        toc_settings: { include_title: true, include_subtitle_1: true }, toc_settings_catalog: [],
        section_number_display: { 1: '1.1' }, suggested_regulatory_refs: {}, manual_code: 'OM-A',
      });
      if (String(url).includes('controlled_book_page_break_api.php')) {
        if (action === 'list') return response({ ok: true, breaks: [], candidates: [{ anchor: 'paragraph-3' }] });
        return response({ ok: true, break: { id: 1, before_block_anchor: payload.before_block_anchor || 'paragraph-3' } });
      }
      if (action === 'create_block') return response({ ok: true, block_id: 100, block_html: blockHtml(payload.block_type, 100), section_number_display: {} });
      if (action === 'upload_image') return response({ ok: true, block_id: 101, block_html: `<div class="cpb-block cpb-block--image" data-block-id="101" data-block-type="image"><figure class="cpb-image cpb-image--editable" data-width-pct="100"><img src="/uploaded.png"><button class="cpb-image-rotate">rotate</button><span class="cpb-image-resize"></span><figcaption contenteditable="true">Uploaded</figcaption></figure></div>` });
      if (action === 'move_block') return response({ ok: true, page_body_html: fixture.blocks });
      if (action === 'recompute_section_numbers') return response({ ok: true, page_html: '', section_number_display: { 1: '1.1' } });
      if (action === 'variables') return response({ ok: true, variables: [{ group: 'Student', variables: [{ key: 'student.full_name', label: 'Student full name' }] }] });
      if (action === 'ensure_lep_approval') return response({ ok: true, approval_url: '/approval/fixture' });
      if (action === 'save_cover_page') return response({ ok: true, cover_page: payload.cover_page });
      if (action === 'save_lep_page') return response({ ok: true, lep_page: payload.lep_page });
      if (action === 'save_part0_page') return response({ ok: true, part0_page: payload.part0_page });
      if (['regenerate_toc','regenerate_lep_parts','regenerate_definitions','regenerate_annex_register','regenerate_annex_highlights','sync_manual_structure','regenerate_highlights'].includes(action)) {
        return response({ ok: true, page_html: fixture.page, result: { entries_count: 1 }, toc_settings: { include_title: true } });
      }
      return response({ ok: true, presets: payload.presets, page_html: fixture.page });
    };
  }, { fixture, mode });
}

function modeFor(row) {
  if (row === 'special.cover') return 'cover';
  if (row === 'special.toc') return 'toc';
  if (row === 'special.lep') return 'lep';
  if (row === 'special.part0') return 'part0';
  if (row === 'special.annex') return 'annex';
  return 'normal';
}

async function exerciseRow(page, row) {
  return page.evaluate(async (name) => {
    const q = (selector) => document.querySelector(selector);
    const qa = (selector) => [...document.querySelectorAll(selector)];
    const wait = (ms = 30) => new Promise((resolve) => setTimeout(resolve, ms));
    const click = (selector) => { const el = q(selector); if (!el) throw new Error(`missing ${selector}`); el.click(); return el; };
    const focus = (selector) => { const el = q(selector); if (!el) throw new Error(`missing ${selector}`); el.focus(); return el; };
    const selectText = (selector, start = 0, end = null) => {
      const el = focus(selector);
      const node = el.firstChild || el.appendChild(document.createTextNode(''));
      const range = document.createRange();
      range.setStart(node, Math.min(start, node.length || 0));
      range.setEnd(node, Math.min(end === null ? (node.length || 0) : end, node.length || 0));
      const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range);
      document.dispatchEvent(new Event('selectionchange'));
      return el;
    };
    const selectAll = (selector) => {
      const el = focus(selector);
      const range = document.createRange();
      range.selectNodeContents(el);
      const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range);
      document.dispatchEvent(new Event('selectionchange'));
      return el;
    };
    const edit = async (selector, html) => {
      const el = focus(selector);
      el.dispatchEvent(new InputEvent('beforeinput', { bubbles: true, inputType: 'insertText' }));
      el.innerHTML = html;
      el.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: html }));
      el.blur();
      await wait();
      return el;
    };
    const key = (selector, keyName, shift = false) => {
      const el = focus(selector);
      return el.dispatchEvent(new KeyboardEvent('keydown', { key: keyName, shiftKey: shift, bubbles: true, cancelable: true }));
    };
    const last = (action) => [...window.__requests].reverse().find((request) => request.action === action);
    const payload = (id) => [...window.__requests].reverse().find((request) => request.action === 'update_block' && request.payload.block_id === id)?.payload.payload;
    // Phase B pagination transport is additive and has its own behavioral contract.
    // Keep this parity observation focused on the frozen source-editor API calls and payloads.
    const observe = (value) => ({ value, requests: window.__requests.filter((r) => !['load', 'get_callout_presets', 'wizard_changes', 'list', 'section_index', 'generate', 'live_ensure', 'live_status', 'live_retry', 'review_threads'].includes(r.action)) });
    await wait(50);

    switch (name) {
      case 'toolbar.structure_and_order': {
        const style = (selector) => getComputedStyle(q(selector));
        return observe({
          order: qa('#cpbToolbarMain button,#cpbToolbarMain select,#cpbToolbarMain input')
            .filter((e) => !e.hasAttribute('data-table-action'))
            .map((e) => e.id || e.dataset.cmd || e.dataset.align || e.dataset.addBlock)
            .filter((control) => control
              && control !== 'cpbUndoWizardEdit'
              && !String(control).startsWith('cpbCell')),
          rows: qa(
            '#cpbToolbarMain .cpb-toolbar-row'
              + ':not(.cpb-toolbar-row--table)'
          ).length,
          visibility: {
            main: style('#cpbToolbarMain').display,
            toc: style('#cpbToolbarToc').display,
            lep: style('#cpbToolbarLep').display,
            part0: style('#cpbToolbarPart0').display,
          },
          layout: {
            toolbarDisplay: style('#cpbToolbar').display,
            mainDirection: style('#cpbToolbarMain').flexDirection,
            canvasOverflowY: style('#cpbCanvas').overflowY,
            canvasPaddingTop: style('#cpbCanvas').paddingTop,
            sheetWidth: style('.cpb-sheet').width,
            rotatePosition: style('.cpb-image-rotate').position,
            rotateWidth: style('.cpb-image-rotate').width,
            resizePosition: style('.cpb-image-resize').position,
            resizeWidth: style('.cpb-image-resize').width,
            captionFontSize: style('.cpb-image figcaption').fontSize,
            captionAlign: style('.cpb-image figcaption').textAlign,
          },
        });
      }
      case 'toolbar.all_commands_target_source': {
        selectText('.cpb-paragraph', 0, 5); click('[data-cmd="bold"]'); q('.cpb-paragraph').blur(); await wait();
        return observe({ sourceCount: qa('.cpb-block[data-block-id="2"]').length, html: q('.cpb-paragraph').innerHTML, blockId: last('update_block')?.payload.block_id });
      }
      case 'toolbar.menus_and_popovers':
        q('#cpbCalloutSelect').value = 'manage'; q('#cpbCalloutSelect').dispatchEvent(new Event('change', { bubbles: true }));
        return observe({ dialog: q('[aria-label="Manage callout presets"]')?.textContent.trim(), options: qa('#cpbCalloutSelect option').map((o) => o.value) });
      case 'paragraph.typing_single_source_object':
        await edit('.cpb-block[data-block-id="2"] .cpb-paragraph', 'Edited <strong>paragraph</strong>');
        return observe({ count: qa('[data-block-id="2"] .cpb-paragraph').length, saved: payload(2)?.html });
      case 'paragraph.enter': {
        const el = selectText('[data-block-id="2"] .cpb-paragraph', 5, 5); document.execCommand('insertParagraph'); el.dispatchEvent(new InputEvent('input', { bubbles: true })); el.blur(); await wait();
        return observe({ html: el.innerHTML, saved: payload(2)?.html, oneField: qa('[data-block-id="2"] .cpb-paragraph').length });
      }
      case 'paragraph.shift_enter': {
        const el = selectText('[data-block-id="2"] .cpb-paragraph', 5, 5); document.execCommand('insertLineBreak'); el.dispatchEvent(new InputEvent('input', { bubbles: true })); el.blur(); await wait();
        return observe({ br: el.querySelectorAll('br').length, saved: payload(2)?.html });
      }
      case 'paragraph.cross_page_identity':
        return observe({ semanticInstances: qa('[data-block-id="2"] .cpb-paragraph').length, autoCopies: qa('[data-block-id="2"] [data-presentation-copy]').length, editable: q('[data-block-id="2"] .cpb-paragraph').isContentEditable });
      case 'heading.edit_and_save':
        await edit('[data-block-id="1"] .cpb-heading', '1.1 Updated heading');
        return observe({ saved: payload(1), focusedPath: q('[data-block-id="1"] .cpb-heading').dataset.field });
      case 'heading.enter': {
        const el = selectText('.cpb-heading', 8, 8); document.execCommand('insertParagraph'); el.dispatchEvent(new InputEvent('input', { bubbles: true })); el.blur(); await wait();
        return observe({ html: el.innerHTML, sourceFields: qa('[data-block-id="1"] .cpb-heading').length });
      }
      case 'heading.numbering':
        focus('.cpb-heading'); q('#cpbParagraphStyleSelect').value = 'title'; q('#cpbParagraphStyleSelect').dispatchEvent(new Event('change', { bubbles: true })); await wait(80);
        return observe({ style: q('.cpb-heading').dataset.paragraphStyle, recompute: !!last('recompute_section_numbers'), payload: payload(1) });
      case 'selection.cross_page_source_range': {
        const el = selectText('[data-block-id="2"] .cpb-paragraph', 0, 10); const range = getSelection().getRangeAt(0);
        return observe({ text: range.toString(), commonBlock: range.commonAncestorContainer.parentElement.closest('.cpb-block')?.dataset.blockId, sourceCount: qa('[data-block-id="2"]').length });
      }
      case 'clipboard.copy':
        selectText('[data-block-id="2"] .cpb-paragraph', 0, 10);
        return observe({ copiedText: getSelection().toString(), sourceUnchanged: q('[data-block-id="2"] .cpb-paragraph').textContent });
      case 'clipboard.paste': {
        const el = selectText('[data-block-id="2"] .cpb-paragraph', 5, 5); document.execCommand('insertText', false, ' PASTED '); el.dispatchEvent(new InputEvent('input', { bubbles: true })); el.blur(); await wait();
        return observe({ text: el.textContent, scriptCount: el.querySelectorAll('script').length, saved: payload(2)?.html });
      }
      case 'formatting.all_text_commands': {
        selectAll('[data-block-id="2"] .cpb-paragraph'); click('[data-cmd="bold"]');
        selectAll('[data-block-id="2"] .cpb-paragraph'); click('[data-cmd="italic"]');
        selectAll('[data-block-id="2"] .cpb-paragraph'); click('[data-cmd="underline"]');
        selectAll('[data-block-id="2"] .cpb-paragraph'); click('[data-align="center"]');
        q('#cpbFontSelect').value = 'sans'; q('#cpbFontSelect').dispatchEvent(new Event('change', { bubbles: true }));
        q('#cpbFontSizeSelect').value = '14'; q('#cpbFontSizeSelect').dispatchEvent(new Event('change', { bubbles: true }));
        q('#cpbTextColor').value = '#112233'; q('#cpbTextColor').dispatchEvent(new Event('input', { bubbles: true })); await wait();
        return observe({ html: q('[data-block-id="2"] .cpb-paragraph').innerHTML, attrs: { align: q('[data-block-id="2"] .cpb-paragraph').dataset.textAlign, font: q('[data-block-id="2"] .cpb-paragraph').dataset.fontFamily, size: q('[data-block-id="2"] .cpb-paragraph').dataset.fontSize, color: q('[data-block-id="2"] .cpb-paragraph').dataset.textColor }, saved: payload(2) });
      }
      case 'history.undo': {
        const before = q('[data-block-id="2"] .cpb-paragraph').innerHTML; await edit('[data-block-id="2"] .cpb-paragraph', 'Changed'); click('#cpbUndo'); await wait();
        return observe({ before, after: q('[data-block-id="2"] .cpb-paragraph').innerHTML });
      }
      case 'history.redo': {
        await edit('[data-block-id="2"] .cpb-paragraph', 'Changed'); click('#cpbUndo'); click('#cpbRedo'); await wait();
        return observe({ after: q('[data-block-id="2"] .cpb-paragraph').innerHTML });
      }
      case 'list.bullet_visual_identity':
        return observe({ blocks: qa('[data-block-id="5"]').length, lists: qa('[data-block-id="5"] > ul.cpb-list').length, items: qa('[data-block-id="5"] > ul.cpb-list > li').map((li) => li.textContent) });
      case 'list.numbered_visual_identity':
        return observe({ blocks: qa('[data-block-id="4"]').length, lists: qa('[data-block-id="4"] > ol.cpb-list').length, start: q('[data-block-id="4"] ol').start, items: qa('[data-block-id="4"] > ol > li').length });
      case 'list.enter': {
        const li = q('[data-block-id="4"] li'); selectText('[data-block-id="4"] li', 1, 1); document.execCommand('insertParagraph'); q('[data-block-id="4"] ol').dispatchEvent(new InputEvent('input', { bubbles: true })); q('[data-block-id="4"] ol').blur(); await wait();
        return observe({ sameList: li.closest('ol') === q('[data-block-id="4"] ol'), items: qa('[data-block-id="4"] ol > li').map((x) => x.textContent), saved: payload(4)?.items });
      }
      case 'list.empty_item_exit': {
        const list = q('[data-block-id="4"] ol'); list.insertAdjacentHTML('beforeend', '<li><br></li>'); const item = list.lastElementChild; item.focus(); const range = document.createRange(); range.selectNodeContents(item); range.collapse(true); getSelection().removeAllRanges(); getSelection().addRange(range); key('[data-block-id="4"] ol', 'Enter'); await wait();
        return observe({ continuation: q('[data-block-id="4"] .cpb-list-continuation')?.isContentEditable, emptyItems: qa('[data-block-id="4"] ol > li').filter((li) => !li.textContent.trim()).length, saved: payload(4)?.continuation_after });
      }
      case 'list.shift_enter': {
        const list = q('[data-block-id="4"] ol'); const item = list.firstElementChild; const range = document.createRange(); range.selectNodeContents(item); range.collapse(false); getSelection().removeAllRanges(); getSelection().addRange(range); list.focus(); key('[data-block-id="4"] ol', 'Enter', true); await wait();
        return observe({ continuation: q('[data-block-id="4"] .cpb-list-continuation')?.textContent, listStillOneBlock: qa('[data-block-id="4"]').length, saved: payload(4)?.continuation_html });
      }
      case 'list.indent':
      case 'list.outdent': {
        const li = q('[data-block-id="4"] li:nth-child(2)'); selectText('[data-block-id="4"] li:nth-child(2)', 0, 3); if (name.endsWith('outdent')) li.dataset.indentLevel = '2'; click(name.endsWith('outdent') ? '#cpbOutdent' : '#cpbIndent'); await wait();
        return observe({ level: li.getAttribute('data-indent-level') || '0', saved: payload(4)?.item_indent_levels });
      }
      case 'list.nested_structure': {
        const list = focus('[data-block-id="4"] ol'); const items = qa('[data-block-id="4"] li'); items[1].dataset.indentLevel = '1'; items[2].dataset.indentLevel = '2'; list.dispatchEvent(new InputEvent('input', { bubbles: true })); list.blur(); await wait();
        return observe({ oneSourceList: qa('[data-block-id="4"] ol.cpb-list').length, hierarchy: payload(4)?.item_indent_levels });
      }
      case 'list.ordered_start':
        focus('[data-block-id="4"] ol'); q('#cpbListStart').value = '5'; q('#cpbListStart').dispatchEvent(new Event('change', { bubbles: true })); await wait();
        return observe({ domStart: q('[data-block-id="4"] ol').start, payloadStart: payload(4)?.start_number });
      case 'list.copy_paste_multiple_items': {
        const list = q('[data-block-id="4"] ol'); const range = document.createRange(); range.setStart(list.children[0].firstChild, 0); range.setEnd(list.children[1].firstChild, list.children[1].textContent.length); getSelection().removeAllRanges(); getSelection().addRange(range);
        const copied = getSelection().toString(); list.lastElementChild.textContent += ` ${copied}`; list.dispatchEvent(new InputEvent('input', { bubbles: true })); list.blur(); await wait();
        return observe({ copied, sourceLists: qa('[data-block-id="4"] ol').length, savedItems: payload(4)?.items });
      }
      case 'list.single_block_actions': {
        const actions = qa('[data-block-id="4"] > .cpb-block-chrome [data-action]').map((e) => e.dataset.action);
        click('[data-block-id="4"] [data-action="insert-paragraph"]'); await wait(80);
        const insertRequest = last('create_block')?.payload;
        click('[data-block-id="4"] [data-action="move-up"]'); await wait(80);
        const moveRequest = last('move_block')?.payload;
        click('[data-block-id="4"] [data-action="delete"]'); await wait(800);
        return observe({ chrome: 1, actions, insertRequest, moveRequest, deleteRequest: last('delete_block')?.payload, deleted: !q('[data-block-id="4"]') });
      }
      case 'block.insert':
        focus('[data-block-id="2"] .cpb-paragraph'); click('[data-add-block="paragraph"]'); await wait(80);
        return observe({ create: last('create_block')?.payload, created: q('[data-block-id="100"]')?.dataset.blockType, focused: document.activeElement.closest('.cpb-block')?.dataset.blockId });
      case 'block.delete':
        click('[data-block-id="3"] [data-action="delete"]'); await wait(80);
        return observe({ deleted: !q('[data-block-id="3"]'), request: last('delete_block')?.payload });
      case 'block.move_up':
      case 'block.move_down':
        click(`[data-block-id="3"] [data-action="${name.endsWith('up') ? 'move-up' : 'move-down'}"]`); await wait(80);
        return observe({ request: last('move_block')?.payload, sourceBlocks: qa('[data-blocks-root] > .cpb-block').length });
      case 'block.insert_paragraph_below':
        click('[data-block-id="2"] [data-action="insert-paragraph"]'); await wait(80);
        return observe({ afterId: q('[data-block-id="2"]')?.nextElementSibling?.dataset.blockId, request: last('create_block')?.payload, focusedBlock: document.activeElement.closest('.cpb-block')?.dataset.blockId });
      case 'table.single_source_object':
        return observe({ blocks: qa('[data-block-id="6"]').length, tables: qa('[data-block-id="6"] table.cpb-table').length, rows: qa('[data-block-id="6"] tr').length, tools: qa('[data-block-id="6"] .cpb-table-tools').length });
      case 'table.cell_edit':
        await edit('[data-block-id="6"] tbody tr:first-child td:first-child', 'Edited A1');
        return observe({ focusedCell: q('[data-block-id="6"] tbody td').dataset.cellFocusWired, saved: payload(6)?.rows?.[0]?.[0] });
      case 'table.add_row':
        focus('[data-block-id="6"] tbody td'); click('[data-block-id="6"] [data-table-action="add-row"]'); await wait();
        return observe({ rows: qa('[data-block-id="6"] tbody tr').length, savedRows: payload(6)?.rows?.length });
      case 'table.delete_row':
        focus('[data-block-id="6"] tbody td'); click('[data-block-id="6"] [data-table-action="del-row"]'); await wait();
        return observe({ rows: qa('[data-block-id="6"] tbody tr').length, savedRows: payload(6)?.rows?.length });
      case 'table.add_column':
        focus('[data-block-id="6"] tbody td'); click('[data-block-id="6"] [data-table-action="add-col"]'); await wait();
        return observe({ cols: qa('[data-block-id="6"] colgroup col').length, headerCells: qa('[data-block-id="6"] .cpb-table-header-row th').length, rowWidths: qa('[data-block-id="6"] tbody tr').map((tr) => tr.cells.length), payloadWidths: payload(6)?.col_widths?.length });
      case 'table.delete_column':
        focus('[data-block-id="6"] tbody td'); click('[data-block-id="6"] [data-table-action="del-col"]'); await wait();
        return observe({ cols: qa('[data-block-id="6"] colgroup col').length, rowWidths: qa('[data-block-id="6"] tbody tr').map((tr) => tr.cells.length), payloadWidths: payload(6)?.col_widths?.length });
      case 'table.column_resize': {
        const handle = q('[data-block-id="6"] .cpb-col-resize'); handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: 100 })); document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 130 })); document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, clientX: 130 })); await wait();
        return observe({ widths: qa('[data-block-id="6"] col').map((c) => c.style.width), payloadWidths: payload(6)?.col_widths });
      }
      case 'table.title_row':
        focus('[data-block-id="6"] tbody td'); click('[data-block-id="6"] [data-table-action="toggle-title"]'); await wait();
        return observe({ titleRows: qa('[data-block-id="6"] [data-title-row]').length, persisted: payload(6)?.has_title_row });
      case 'table.header_row':
        await edit('[data-block-id="6"] .cpb-table-header-row th:first-child .cpb-th-text', 'Header changed'); await wait(750);
        return observe({ headerRows: qa('[data-block-id="6"] .cpb-table-header-row').length, header: payload(6)?.headers?.[0], persisted: payload(6)?.has_header_row });
      case 'table.repeated_header_is_presentation_only':
        return observe({ semanticHeaders: qa('[data-block-id="6"] thead .cpb-table-header-row').length, presentationCopiesInsideSource: qa('[data-block-id="6"] [data-presentation-copy]').length, payloadHeaders: 2 });
      case 'table.merged_and_spanned_cells':
        focus('[data-block-id="6"] tbody td:first-child'); click('[data-block-id="6"] [data-table-action="merge-cells-right"]'); await wait();
        return observe({ cells: q('[data-block-id="6"] tbody tr').cells.length, colspan: q('[data-block-id="6"] tbody td').colSpan, payloadSpan: payload(6)?.row_colspans?.[0]?.[0] });
      case 'table.formatting':
        focus('[data-block-id="6"] tbody td'); click('[data-block-id="6"] [data-table-action="cell-align-center"]'); q('[data-block-id="6"] [data-table-action="cell-bg"]').value = '#abcdef'; q('[data-block-id="6"] [data-table-action="cell-bg"]').dispatchEvent(new Event('input', { bubbles: true })); await wait();
        return observe({ align: q('[data-block-id="6"] tbody td').dataset.cellAlign, bg: q('[data-block-id="6"] tbody td').dataset.cellBg, payloadAlign: payload(6)?.cell_align?.[0]?.[0] });
      case 'table.cell_copy_paste':
        q('[data-block-id="6"] tbody tr:first-child td:first-child').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
        q('[data-block-id="6"] tbody tr:first-child td:nth-child(2)').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, ctrlKey: true }));
        {
          const target = q('[data-block-id="6"] tbody tr:nth-child(2) td:first-child');
          const data = new DataTransfer(); data.setData('text/plain', 'Copied A\tCopied B');
          target.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: data }));
        }
        await wait();
        return observe({ pastedRow: qa('[data-block-id="6"] tbody tr:nth-child(2) td').map((td) => td.textContent), payloadRow: payload(6)?.rows?.[1] });
      case 'table.undo_redo':
        focus('[data-block-id="6"] tbody td'); click('[data-block-id="6"] [data-table-action="add-row"]'); click('#cpbUndo'); const undone = qa('[data-block-id="6"] tbody tr').length; click('#cpbRedo');
        return observe({ undone, redone: qa('[data-block-id="6"] tbody tr').length });
      case 'callout.single_source_object':
        return observe({ blocks: qa('[data-block-id="7"]').length, callouts: qa('[data-block-id="7"] .cpb-callout').length, editableFields: qa('[data-block-id="7"] [contenteditable="true"]').length });
      case 'callout.edit_and_save':
        await edit('[data-block-id="7"] .cpb-callout-title', 'NOTICE'); await edit('[data-block-id="7"] .cpb-callout-text', 'Updated text');
        return observe({ saved: payload(7), oneCallout: qa('[data-block-id="7"] .cpb-callout').length });
      case 'callout.type_switch':
        focus('[data-block-id="7"] .cpb-callout-text'); q('#cpbCalloutSelect').value = 'note'; q('#cpbCalloutSelect').dispatchEvent(new Event('change', { bubbles: true })); await wait();
        return observe({ type: q('[data-block-id="7"] .cpb-callout').dataset.calloutType, className: q('[data-block-id="7"] .cpb-callout').className, savedType: payload(7)?.callout_type });
      case 'image.upload': {
        const input = q('#cpbImageInput'); const file = new File(['fixture'], 'fixture.png', { type: 'image/png' }); const transfer = new DataTransfer(); transfer.items.add(file); Object.defineProperty(input, 'files', { value: transfer.files, configurable: true }); input.dispatchEvent(new Event('change', { bubbles: true })); await wait(80);
        return observe({ upload: last('upload_image')?.payload, inserted: q('[data-block-id="101"] img')?.getAttribute('src'), focusedBlock: document.activeElement.closest('.cpb-block')?.dataset.blockId });
      }
      case 'image.resize': {
        const figure = q('[data-block-id="8"] figure'); Object.defineProperty(figure, 'offsetWidth', { value: 300 }); Object.defineProperty(figure.parentElement, 'clientWidth', { value: 600 }); const handle = q('[data-block-id="8"] .cpb-image-resize'); handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: 100 })); document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 160 })); document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true })); await wait();
        return observe({ width: figure.dataset.widthPct, payloadWidth: payload(8)?.width_pct });
      }
      case 'image.rotate':
        click('[data-block-id="8"] .cpb-image-rotate'); await wait();
        return observe({ rotation: q('[data-block-id="8"] figure').dataset.rotationDeg, transform: q('[data-block-id="8"] img').style.transform, payloadRotation: payload(8)?.rotation_deg });
      case 'image.caption':
        await edit('[data-block-id="8"] figcaption', 'Updated caption');
        return observe({ caption: q('[data-block-id="8"] figcaption').textContent, savedAlt: payload(8)?.alt, oneFigure: qa('[data-block-id="8"] figure').length });
      case 'figure.all_controls': {
        const figure = q('[data-block-id="8"] figure');
        Object.defineProperty(figure, 'offsetWidth', { value: 300 });
        Object.defineProperty(figure.parentElement, 'clientWidth', { value: 600 });
        click('[data-block-id="8"] .cpb-image-rotate');
        const handle = q('[data-block-id="8"] .cpb-image-resize');
        handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: 100 }));
        document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 160 }));
        document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
        await edit('[data-block-id="8"] figcaption', 'Combined figure caption');
        return observe({
          figureCount: qa('[data-block-id="8"] figure').length,
          rotation: figure.dataset.rotationDeg,
          width: figure.dataset.widthPct,
          caption: q('[data-block-id="8"] figcaption').textContent,
          saved: payload(8),
        });
      }
      case 'field.all_controls':
        click('[data-block-id="9"]'); click('#cpbFormFieldSettings'); q('#cffFieldLabel').value = 'Pilot'; q('.cpb-callout-save').click(); q('#cpbFormVariableSelect').value = 'student.full_name'; q('#cpbFormVariableSelect').dispatchEvent(new Event('change', { bubbles: true })); await wait();
        return observe({ label: q('[data-block-id="9"] [data-form-field]').dataset.label, variable: q('[data-block-id="9"] [data-form-field]').dataset.variableKey, saved: payload(9) });
      case 'special.cover':
        await edit('[data-cover-field="company_name"]', 'EuroPilot Center');
        return observe({ shell: qa('.cpb-sheet--cover').length, saved: last('save_cover_page')?.payload.cover_page, dropTools: qa('[data-cover-drop]').length });
      case 'special.toc':
        click('#cpbToolbarToc .cpb-toc-regenerate'); await wait();
        return observe({ shell: qa('.cpb-sheet--toc').length, rows: qa('.cpb-toc-row').length, request: last('regenerate_toc')?.payload });
      case 'special.lep':
        await edit('[data-lep-field="certification_text"]', 'Updated certification'); click('#cpbLepSave'); await wait();
        return observe({ shell: qa('.cpb-sheet--lep').length, saved: last('save_lep_page')?.payload.lep_page, approvalEnabled: !q('#cpbLepApprovalLink').disabled });
      case 'special.part0':
        await edit('[data-part0-col="definition"]', 'Updated meaning'); click('#cpbPart0Save'); await wait();
        return observe({ shell: qa('.cpb-sheet--part0').length, saved: last('save_part0_page')?.payload.part0_page, heading: last('save_part0_page')?.payload.headings?.[0] });
      case 'special.annex':
        click('[data-annex-link="12"]'); await wait();
        return observe({ shell: qa('.cpb-sheet--annex').length, loadSection12: window.__requests.some((r) => r.action === 'load' && r.payload.section_id === '12'), sourceRootCount: qa('[data-blocks-root]').length });
      case 'save.identical_payloads':
        await edit('[data-block-id="2"] .cpb-paragraph', '<em>Canonical</em> payload');
        return observe({ action: last('update_block')?.action, envelope: last('update_block')?.payload, sourceBlockCount: qa('[data-block-id="2"]').length });
      case 'save.timing_and_status': {
        const el = focus('[data-block-id="2"] .cpb-paragraph'); el.textContent = 'Debounced'; el.dispatchEvent(new InputEvent('input', { bubbles: true })); const editingStatus = q('#cpbSaveStatus').textContent; const before = window.__requests.filter((r) => r.action === 'update_block').length; await wait(750); const after = window.__requests.filter((r) => r.action === 'update_block').length;
        return observe({ editingStatus, before, after, finalStatus: q('#cpbSaveStatus').textContent });
      }
      case 'editing.focus_retention': {
        const el = focus('[data-block-id="2"] .cpb-paragraph'); el.textContent = 'Focused'; el.dispatchEvent(new InputEvent('input', { bubbles: true })); await wait(750);
        return observe({ activeBlock: document.activeElement.closest('.cpb-block')?.dataset.blockId, activeField: document.activeElement.dataset.field, saved: payload(2)?.html });
      }
      case 'editing.scroll_retention': {
        const canvas = q('#cpbCanvas'); const el = focus('[data-block-id="2"] .cpb-paragraph'); canvas.scrollTop = 240; el.textContent = 'Scroll stable'; el.dispatchEvent(new InputEvent('input', { bubbles: true })); await wait(750);
        return observe({ before: 240, after: canvas.scrollTop, saved: payload(2)?.html });
      }
      case 'pagination.manual_break_only_addition':
        selectText('[data-block-id="2"] .cpb-paragraph', 5, 5); click('#cpbInsertPageBreak'); await wait(80);
        return observe({ sourceBlocks: qa('[data-blocks-root] > .cpb-block').length, breakRequest: window.__requests.find((r) => String(r.action).includes('split') || String(r.action).includes('create')), oneEditedSource: qa('[data-block-id="2"]').length });
      case 'pagination.page_furniture_only_addition':
        await wait(80);
        return observe({ sourceHeaders: qa('.cpb-sheet > .cpb-page-header').length, sourceFooters: qa('.cpb-sheet > .cpb-page-footer').length, furnitureLayers: qa('.cpb-print-furniture-layer').length, editableFurniture: qa('.cpb-print-furniture-layer [contenteditable="true"]').length });
      case 'pagination.presentation_copies_excluded':
        await wait(80);
        return observe({ semanticBlocks: qa('[data-blocks-root] > .cpb-block').length, duplicateSemanticIds: [...new Set(qa('.cpb-block[data-block-id]').map((b) => b.dataset.blockId).filter((id, i, all) => all.indexOf(id) !== i))], presentationCopiesInPayload: JSON.stringify(payload(2) || {}).includes('presentation-copy') });
      default:
        throw new Error(`No executable scenario for ${name}`);
    }
  }, row);
}

function validateObservation(row, observation) {
  const v = observation?.value;
  if (!v || typeof v !== 'object') throw new Error('scenario produced no behavioral observation');
  const json = JSON.stringify(v);
  if (json.includes('undefined')) throw new Error('scenario produced an undefined observation');
  const hasRequest = (action) => observation.requests.some((request) => request.action === action);
  const rules = {
    'toolbar.structure_and_order': () => JSON.stringify(v.order) === JSON.stringify([
      'cpbUndo', 'cpbRedo', 'bold', 'italic', 'underline', 'left', 'center', 'right',
      'cpbParagraphStyleSelect', 'cpbRegulatoryRef', 'cpbCrossRefDoc', 'cpbCrossRefKey',
      'cpbCrossRefClear', 'cpbFontSelect', 'cpbFontSizeSelect', 'cpbTextColor',
      'insertUnorderedList', 'insertOrderedList', 'cpbListStart', 'cpbOutdent', 'cpbIndent',
      'cpbOpenStyleEditor', 'cpbOpenHeaderEditor', 'cpbInsertPageBreak', 'paragraph', 'table',
      'cpbPickImage', 'cpbCalloutSelect', 'cpbDetectSelect',
    ]) && v.rows === 2 && v.visibility.main === 'flex'
      && v.visibility.toc === 'none' && v.visibility.lep === 'none' && v.visibility.part0 === 'none'
      && v.layout.toolbarDisplay === 'flex' && v.layout.mainDirection === 'column'
      && v.layout.canvasOverflowY === 'auto' && v.layout.canvasPaddingTop === '28px'
      && v.layout.sheetWidth === '816px' && v.layout.rotatePosition === 'absolute'
      && v.layout.rotateWidth === '22px' && v.layout.resizePosition === 'absolute'
      && v.layout.resizeWidth === '14px' && v.layout.captionFontSize === '12px'
      && v.layout.captionAlign === 'center',
    'toolbar.all_commands_target_source': () => v.sourceCount === 1 && v.blockId === 2 && /<(b|strong)>Alpha<\/(b|strong)>/i.test(v.html),
    'toolbar.menus_and_popovers': () => v.dialog?.includes('Callout presets') && ['warning', 'note', 'caution', 'info', 'manage'].every((option) => v.options.includes(option)),
    'paragraph.typing_single_source_object': () => v.count === 1 && v.saved?.includes('Edited'),
    'paragraph.enter': () => v.oneField === 1 && /<(div|p|br)/i.test(v.html) && v.saved === v.html,
    'paragraph.shift_enter': () => v.br >= 1 && /<br/i.test(v.saved),
    'paragraph.cross_page_identity': () => v.semanticInstances === 1 && v.autoCopies === 0 && v.editable,
    'heading.edit_and_save': () => v.saved?.text === 'Updated heading',
    'heading.enter': () => v.sourceFields === 1 && /<(div|br)/i.test(v.html),
    'heading.numbering': () => v.style === 'title' && v.recompute && v.payload?.paragraph_style === 'title' && hasRequest('recompute_section_numbers'),
    'selection.cross_page_source_range': () => v.text === 'Alpha beta' && v.commonBlock === '2' && v.sourceCount === 1,
    'clipboard.copy': () => v.copiedText === 'Alpha beta' && v.sourceUnchanged === 'Alpha beta gamma',
    'clipboard.paste': () => v.text.includes('PASTED') && v.scriptCount === 0 && v.saved?.includes('PASTED'),
    'formatting.all_text_commands': () => /<(b|strong)>/i.test(v.html) && /<(i|em)>/i.test(v.html) && /<u>/i.test(v.html)
      && v.html.includes('font-family: system-ui') && v.html.includes('font-size: 14pt')
      && v.html.includes('color: rgb(17, 34, 51)') && v.saved?.text_align === 'center'
      && v.saved?.html === v.html,
    'history.undo': () => v.after === v.before,
    'history.redo': () => v.after === 'Changed',
    'list.bullet_visual_identity': () => v.blocks === 1 && v.lists === 1 && v.items.length === 2,
    'list.numbered_visual_identity': () => v.blocks === 1 && v.lists === 1 && v.start === 3,
    'list.enter': () => v.sameList && v.items.length === 4 && v.saved?.length === 4,
    'list.empty_item_exit': () => v.continuation && v.emptyItems === 0 && v.saved === 3,
    'list.shift_enter': () => typeof v.continuation === 'string' && v.listStillOneBlock === 1 && typeof v.saved === 'string',
    'list.indent': () => v.level === '1',
    'list.outdent': () => v.level === '1',
    'list.nested_structure': () => v.oneSourceList === 1 && JSON.stringify(v.hierarchy) === '[0,1,2]',
    'list.ordered_start': () => v.domStart === 5 && v.payloadStart === 5,
    'list.copy_paste_multiple_items': () => v.copied.includes('One') && v.copied.includes('Two') && v.sourceLists === 1 && v.savedItems?.[2]?.includes('One'),
    'list.single_block_actions': () => v.chrome === 1 && JSON.stringify(v.actions) === '["insert-paragraph","move-up","move-down","delete"]'
      && v.insertRequest?.insert_after_block_id === 4 && v.moveRequest?.block_id === 4
      && v.moveRequest?.direction === 'up' && v.deleteRequest?.block_id === 4 && v.deleted,
    'block.insert': () => v.create?.block_type === 'paragraph' && v.create?.insert_after_block_id === 2 && v.created === 'paragraph' && hasRequest('create_block'),
    'block.delete': () => v.deleted && v.request?.block_id === 3,
    'block.move_up': () => v.request?.block_id === 3 && v.request?.direction === 'up' && v.sourceBlocks === 9,
    'block.move_down': () => v.request?.block_id === 3 && v.request?.direction === 'down' && v.sourceBlocks === 9,
    'block.insert_paragraph_below': () => v.afterId === '100' && v.request?.insert_after_block_id === 2 && v.focusedBlock === '100',
    'table.single_source_object': () => v.blocks === 1 && v.tables === 1 && v.tools === 1,
    'table.cell_edit': () => v.focusedCell === '1' && v.saved === 'Edited A1',
    'table.add_row': () => v.rows === 3 && v.savedRows === 3,
    'table.delete_row': () => v.rows === 1 && v.savedRows === 1,
    'table.add_column': () => v.cols === 3 && v.headerCells === 3 && v.rowWidths.every((n) => n === 3),
    'table.delete_column': () => v.cols === 1 && v.rowWidths.every((n) => n === 1),
    'table.column_resize': () => JSON.stringify(v.widths) === '["170px","110px"]' && JSON.stringify(v.payloadWidths) === '[170,110]',
    'table.title_row': () => v.titleRows === 0 && v.persisted === false,
    'table.header_row': () => v.headerRows === 1 && v.header === 'Header changed' && v.persisted === true,
    'table.repeated_header_is_presentation_only': () => v.semanticHeaders === 1 && v.presentationCopiesInsideSource === 0 && v.payloadHeaders === 2,
    'table.merged_and_spanned_cells': () => v.cells === 1 && v.colspan === 2 && v.payloadSpan === 2,
    'table.formatting': () => v.align === 'center' && v.bg === '#abcdef' && v.payloadAlign === 'center',
    'table.cell_copy_paste': () => JSON.stringify(v.pastedRow) === '["Copied A","Copied B"]' && JSON.stringify(v.payloadRow) === '["Copied A","Copied B"]',
    'table.undo_redo': () => v.undone === 2 && v.redone === 3,
    'callout.single_source_object': () => v.blocks === 1 && v.callouts === 1,
    'callout.edit_and_save': () => v.oneCallout === 1 && v.saved?.title === 'NOTICE' && v.saved?.text === 'Updated text',
    'callout.type_switch': () => v.type === 'note' && v.savedType === 'note',
    'image.upload': () => v.upload?.image?.name === 'fixture.png' && v.upload?.image?.type === 'image/png' && v.inserted === '/uploaded.png' && hasRequest('upload_image'),
    'image.resize': () => v.width === '51' && v.payloadWidth === 51,
    'image.rotate': () => v.rotation === '90' && v.payloadRotation === 90,
    'image.caption': () => v.savedAlt === 'Updated caption',
    'figure.all_controls': () => v.figureCount === 1 && v.rotation === '90' && v.width === '51'
      && v.caption === 'Combined figure caption' && v.saved?.rotation_deg === 90
      && v.saved?.width_pct === 51 && v.saved?.alt === 'Combined figure caption',
    'field.all_controls': () => v.label === 'Pilot' && v.variable === 'student.full_name' && v.saved?.placeholder === '{{student.full_name}}',
    'special.cover': () => v.shell === 1 && v.dropTools === 1 && v.saved?.company_name === 'EuroPilot Center' && hasRequest('save_cover_page'),
    'special.toc': () => v.shell === 1 && v.rows === 1 && v.request?.section_id === 11 && hasRequest('regenerate_toc'),
    'special.lep': () => v.shell === 1 && v.approvalEnabled && v.saved?.certification_text === 'Updated certification' && hasRequest('save_lep_page'),
    'special.part0': () => v.shell === 1 && v.saved?.entries?.[0]?.definition === 'Updated meaning' && v.heading?.text === 'Definitions' && hasRequest('save_part0_page'),
    'special.annex': () => v.shell === 1 && v.loadSection12 && v.sourceRootCount === 1,
    'save.identical_payloads': () => v.action === 'update_block' && v.envelope?.block_id === 2 && v.envelope?.payload?.html === '<em>Canonical</em> payload' && v.sourceBlockCount === 1,
    'save.timing_and_status': () => v.before === 0 && v.after === 1 && v.editingStatus === 'Editing…',
    'editing.focus_retention': () => v.activeBlock === '2' && v.activeField === 'html' && v.saved === 'Focused',
    'editing.scroll_retention': () => v.before === 240 && v.after === 240 && v.saved === 'Scroll stable',
    'pagination.manual_break_only_addition': () => v.sourceBlocks === 9 && v.oneEditedSource === 1 && !!v.breakRequest,
    'pagination.page_furniture_only_addition': () => v.sourceHeaders === 1 && v.sourceFooters === 1 && v.furnitureLayers >= 1 && v.editableFurniture === 0,
    'pagination.presentation_copies_excluded': () => v.duplicateSemanticIds.length === 0 && !v.presentationCopiesInPayload,
  };
  if (Object.keys(rules).length !== expectedRowCount) {
    throw new Error(`harness assertion registry has ${Object.keys(rules).length} rows, expected ${expectedRowCount}`);
  }
  if (!rules[row]) throw new Error('missing explicit expected behavioral assertion');
  if (!rules[row]()) throw new Error(`behavior assertion failed: ${json}`);
}

function normalize(value) {
  if (Array.isArray(value)) return value.map(normalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, normalize(value[key])]));
  }
  return value;
}

// These command scenarios historically included an unrelated update_block request caused
// solely by blurring an unchanged source field. The final DOM and persisted command payloads
// remain frozen; only that redundant, focus-stealing autosave request is intentionally removed.
const noOpBlurSaveRows = new Set([
  'list.empty_item_exit',
  'list.shift_enter',
  'list.single_block_actions',
  'block.insert',
  'table.add_row',
  'table.delete_row',
  'table.title_row',
  'table.undo_redo',
  'pagination.manual_break_only_addition',
]);

function parityComparable(row, observation) {
  if (noOpBlurSaveRows.has(row)) return observation.value;
  const comparable = structuredClone(observation);
  for (const request of comparable.requests || []) {
    const payload = request?.payload?.payload;
    if (request?.action !== 'update_block' || Number(request?.payload?.block_id) !== 6 || !payload) {
      continue;
    }
    // The current Editor stores inherited table typography as unset values and
    // carries additive per-edge borders. Those schema extensions are covered
    // by their own contracts; normalize them out of this frozen source-behavior gate.
    for (const key of [
      'title_font_family', 'title_font_size', 'title_text_color', 'title_borders',
      'header_font_family', 'header_font_size', 'header_text_color', 'header_borders',
      'cell_font_family', 'cell_font_size', 'cell_text_color', 'cell_borders',
    ]) {
      delete payload[key];
    }
  }
  return comparable;
}

async function runVariant(page, assets, row) {
  await page.setContent(renderVariantDocument(assets), { waitUntil: 'domcontentloaded' });
  await installMock(page, modeFor(row));
  const errors = [];
  const onPageError = (error) => errors.push(error.message);
  page.on('pageerror', onPageError);
  await page.addScriptTag({ content: assets.js });
  await page.waitForFunction(() => document.querySelector('#cpbCanvas .cpb-sheet'), null, { timeout: 5000 });
  await page.waitForFunction(() => {
    const root = document.querySelector('#cpbEditorRoot');
    return !root.hasAttribute('aria-busy') || root.getAttribute('aria-busy') === 'false';
  }, null, { timeout: 8000 });
  const observation = normalize(await exerciseRow(page, row));
  page.off('pageerror', onPageError);
  if (errors.length) throw new Error(`browser error: ${errors.join('; ')}`);
  validateObservation(row, observation);
  return observation;
}

let browser;
let failures = 0;
const stats = {
  baseline: { executed: 0, passed: 0, failed: 0, skipped: 0, flaky: 0, retried: 0 },
  current: { executed: 0, passed: 0, failed: 0, skipped: 0, flaky: 0, retried: 0 },
};
try {
  try {
    browser = await chromium.launch({ headless: true });
  } catch (bundledError) {
    browser = await chromium.launch({ headless: true, channel: 'chrome' }).catch(() => {
      throw bundledError;
    });
  }
  for (const row of rows) {
    let baseline;
    let current;
    let baselineError;
    let currentError;
    for (const variant of ['baseline', 'current']) {
      stats[variant].executed += 1;
      const variantPage = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      try {
        const result = await runVariant(variantPage, variant === 'baseline' ? baselineAssets : currentAssets, row);
        stats[variant].passed += 1;
        if (variant === 'baseline') baseline = result;
        else current = result;
      } catch (error) {
        stats[variant].failed += 1;
        if (variant === 'baseline') baselineError = error;
        else currentError = error;
      } finally {
        await variantPage.close();
      }
    }
    if (baselineError || currentError) {
      failures += 1;
      const details = [
        baselineError ? `baseline: ${baselineError.message || baselineError}` : '',
        currentError ? `current: ${currentError.message || currentError}` : '',
      ].filter(Boolean).join('; ');
      console.log(`FAIL ${row} — ${details.replace(/\s+/g, ' ')}`);
    } else if (
      JSON.stringify(parityComparable(row, baseline))
      !== JSON.stringify(parityComparable(row, current))
    ) {
      failures += 1;
      console.log(`FAIL ${row} — baseline/current normalized behavior or API payload mismatch`);
      if (process.env.IPCA_EDITOR_PARITY_DEBUG === '1') {
        console.log(`  baseline=${JSON.stringify(parityComparable(row, baseline))}`);
        console.log(`  current=${JSON.stringify(parityComparable(row, current))}`);
      }
    } else {
      const detail = noOpBlurSaveRows.has(row)
        ? 'behavior and final payload match; redundant unchanged-field blur save suppressed'
        : 'behavior and API payloads match';
      console.log(`PASS ${row} — baseline/current ${detail}`);
    }
  }
} catch (error) {
  console.error(`FAIL parity.browser_harness — ${error.message || error}`);
  process.exitCode = 1;
} finally {
  if (browser) await browser.close();
}

console.log(`\nBehavioral parity rows: ${rows.length}`);
for (const variant of ['baseline', 'current']) {
  const s = stats[variant];
  console.log(`${variant[0].toUpperCase()}${variant.slice(1)}: executed=${s.executed} passed=${s.passed} failed=${s.failed} skipped=${s.skipped} flaky=${s.flaky} retried=${s.retried}`);
}
if (failures > 0 || process.exitCode) {
  console.log(`EDITOR_BEHAVIORAL_PARITY: BLOCKED (${failures || 1} failures)`);
  process.exit(1);
}
console.log('EDITOR_BEHAVIORAL_PARITY: PASS');
