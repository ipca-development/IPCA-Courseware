import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const requireFromGarmin = createRequire(path.join(root, 'scripts/garmin/package.json'));
const { chromium } = requireFromGarmin('playwright');
const editorSource = fs.readFileSync(
  path.join(root, 'public/assets/controlled_book_editor.js'),
  'utf8',
);

function extractFunction(name) {
  const marker = `function ${name}(`;
  const start = editorSource.indexOf(marker);
  if (start < 0) throw new Error(`Missing editor function: ${name}`);
  const braceStart = editorSource.indexOf('{', start);
  let depth = 0;
  for (let index = braceStart; index < editorSource.length; index += 1) {
    if (editorSource[index] === '{') depth += 1;
    if (editorSource[index] === '}') {
      depth -= 1;
      if (depth === 0) return editorSource.slice(start, index + 1);
    }
  }
  throw new Error(`Unterminated editor function: ${name}`);
}

const functionNames = [
  'extractCellBorders',
  'oppositeBorderSide',
  'adjacentTableCells',
  'setCellBorderSide',
  'tableCellImage',
  'tableCellImageRatio',
  'syncTableCellImageSizeControls',
  'updateTableCellImageSize',
  'stepTableCellImageSize',
  'wireTableCellImages',
];
const sources = Object.fromEntries(functionNames.map((name) => [name, extractFunction(name)]));

let browser;
try {
  browser = await chromium.launch({ headless: true });
} catch (bundledError) {
  browser = await chromium.launch({ headless: true, channel: 'chrome' }).catch(() => {
    throw bundledError;
  });
}

const page = await browser.newPage();
try {
  await page.setContent(`
    <style>
      table { border-collapse: collapse; }
      td { width: 180px; height: 80px; padding: 0; border: 1px solid #94a3b8; }
      .cpb-table-cell-image { display: block; }
      .cpb-table-cell-image img { display: block; width: 100%; height: var(--cpb-table-cell-image-height, auto); }
    </style>
    <input id="width" type="number">
    <input id="height" type="number">
    <input id="lock" type="checkbox">
    <div id="block">
      <table><tbody><tr>
        <td id="left">
          Text
          <span class="cpb-table-cell-image" data-width-pct="42" data-height-px="96" data-lock-ratio="0" data-align="right">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='100'%3E%3C/svg%3E" alt="Diagram">
          </span>
        </td>
        <td id="right">Right</td>
      </tr></tbody></table>
    </div>
  `);

  const result = await page.evaluate((functionSources) => {
    Object.entries(functionSources).forEach(([name, source]) => {
      window[name] = window.eval(`(${source})`);
    });
    window.state = { editable: true };
    window.cellImageWidthInput = document.getElementById('width');
    window.cellImageHeightInput = document.getElementById('height');
    window.cellImageLockRatioInput = document.getElementById('lock');
    window.tableToolbarEl = document.body;
    window.pushUndo = () => {};
    window.scheduleSave = () => {};
    window.flushSave = () => Promise.resolve();

    const block = document.getElementById('block');
    const left = document.getElementById('left');
    const right = document.getElementById('right');
    window.state.activeTableToolsBlock = block;
    window.resolveSelectedTableCell = () => left;
    window.setCellBorderSide(left, 'right', {
      style: 'none',
      width: 0,
      color: '#94a3b8',
    }, true);
    window.wireTableCellImages(block);

    const figure = left.querySelector('.cpb-table-cell-image');
    window.cellImageWidthInput.value = '60';
    window.cellImageHeightInput.value = '96';
    window.cellImageLockRatioInput.checked = true;
    window.updateTableCellImageSize('width');
    const lockedHeight = Number(figure.dataset.heightPx);
    window.cellImageHeightInput.value = '120';
    window.cellImageLockRatioInput.checked = false;
    window.updateTableCellImageSize('height');
    window.stepTableCellImageSize('width', 1);
    return {
      leftRight: window.extractCellBorders(left).right,
      rightLeft: window.extractCellBorders(right).left,
      figureWidth: figure.style.width,
      figureHeight: figure.style.getPropertyValue('--cpb-table-cell-image-height'),
      ratioLocked: figure.dataset.lockRatio,
      figureEditable: figure.getAttribute('contenteditable'),
      resizeHandles: figure.querySelectorAll('.cpb-table-cell-image-resize').length,
      lockedHeight,
      independentWidth: figure.dataset.widthPct,
      independentHeight: figure.dataset.heightPx,
      independentLock: figure.dataset.lockRatio,
    };
  }, sources);

  if (result.leftRight?.style !== 'none' || result.rightLeft?.style !== 'none') {
    throw new Error('Adjacent cell boundary was not synchronized.');
  }
  if (result.figureWidth !== '61%'
      || result.figureHeight !== '120px'
      || result.ratioLocked !== '0'
      || result.figureEditable !== 'false'
      || result.resizeHandles !== 1
      || result.lockedHeight < 40
      || result.lockedHeight > 70
      || result.independentWidth !== '61'
      || result.independentHeight !== '120'
      || result.independentLock !== '0') {
    throw new Error(`Cell image editor wiring failed: ${JSON.stringify(result)}`);
  }

  console.log('Controlled publishing table cell media and borders: PASS');
  console.log('Adjacent borders synchronize and cell images retain governed sizing');
} finally {
  await browser.close();
}
