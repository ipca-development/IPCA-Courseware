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

const rebuildSource = extractFunction('rebuildTableColumnResizeHandles');
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
    <div id="block">
      <table>
        <colgroup>${'<col style="width:100px">'.repeat(10)}</colgroup>
        <thead><tr class="cpb-table-header-row">
          <th colspan="4">Preceding merge</th>
          <th id="target" colspan="3">Target merge</th>
          <th colspan="3">Remainder</th>
        </tr></thead>
        <tbody data-table-part="body"><tr>${'<td></td>'.repeat(10)}</tr></tbody>
      </table>
    </div>
  `);

  const result = await page.evaluate((functionSource) => {
    window.tableHeaderRow = function tableHeaderRow(blockEl) {
      return blockEl.querySelector('thead tr.cpb-table-header-row');
    };
    const rebuild = window.eval(`(${functionSource})`);
    const block = document.getElementById('block');
    const row = block.querySelector('thead tr');

    rebuild(block);
    const mergedIndexes = Array.from(
      row.querySelectorAll('.cpb-col-resize'),
      (handle) => Number(handle.dataset.colIndex),
    );

    const target = document.getElementById('target');
    target.colSpan = 1;
    for (let offset = 0; offset < 2; offset += 1) {
      const cell = document.createElement('th');
      cell.textContent = `Split ${offset + 1}`;
      target.parentElement.insertBefore(cell, target.nextSibling);
    }
    rebuild(block);

    const splitIndexes = Array.from(
      row.querySelectorAll('.cpb-col-resize'),
      (handle) => Number(handle.dataset.colIndex),
    );
    const splitCells = Array.from(row.cells);
    const selectedCell = splitCells[3];
    const selectedIndex = Number(
      selectedCell.querySelector('.cpb-col-resize').dataset.colIndex,
    );
    const columns = block.querySelectorAll('colgroup col');

    return {
      mergedIndexes,
      splitIndexes,
      selectedIndex,
      selectedColumnIsLogicalColumn: columns[selectedIndex] === columns[6],
    };
  }, rebuildSource);

  const expectedMerged = JSON.stringify([3, 6, 9]);
  const expectedSplit = JSON.stringify([3, 4, 5, 6, 9]);
  if (JSON.stringify(result.mergedIndexes) !== expectedMerged) {
    throw new Error(`Merged resize indexes are wrong: ${JSON.stringify(result.mergedIndexes)}`);
  }
  if (JSON.stringify(result.splitIndexes) !== expectedSplit) {
    throw new Error(`Unmerged resize indexes are wrong: ${JSON.stringify(result.splitIndexes)}`);
  }
  if (result.selectedIndex !== 6 || !result.selectedColumnIsLogicalColumn) {
    throw new Error(`Selected boundary resolved to column ${result.selectedIndex}, expected 6`);
  }

  console.log('Controlled publishing merged-cell resize mapping: PASS');
  console.log('Selected logical column 6 maps to colgroup column 6, not physical cell index 3');
} finally {
  await browser.close();
}
