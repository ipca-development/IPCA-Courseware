"use strict";

const assert = require("assert");
const fs = require("fs");
const path = require("path");
let chromium;
try {
  ({ chromium } = require("playwright"));
} catch (_) {
  ({ chromium } = require("../scripts/garmin/node_modules/playwright"));
}

const root = path.resolve(__dirname, "..");
const annotationSource = fs.readFileSync(
  path.join(
    root,
    "ipca-manual-reader-ios/IPCAManualReader/Services/ManualReaderAPIClient.swift"
  ),
  "utf8"
);
const selectionSource = fs.readFileSync(
  path.join(
    root,
    "ipca-manual-reader-ios/IPCAManualReader/Views/ManualPageWebView.swift"
  ),
  "utf8"
);

function extract(source, startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert(start >= 0 && end > start, `Unable to extract ${startMarker}`);
  return source.slice(start, end).replace(/^ {10}/gm, "");
}

const annotationHelpers = extract(
  annotationSource,
  "          function textNodes(scope) {",
  "          function highlightExact(item, className) {"
);
const annotationFunctions = extract(
  annotationSource,
  "          function textNodes(scope) {",
  "          highlights.forEach(item => highlightExact(item, 'mr-user-highlight'));"
);
const selectionHelpers = extract(
  selectionSource,
  "      function annotationTextNodes(scope) {",
  "      document.addEventListener('touchend'"
).replace(/^ {6}/gm, "");
const readinessScript = extract(
  selectionSource,
  "        (function() {\n          const revision = \\(revision);",
  "        \"\"\"\n        context.coordinator.pendingScaleJS"
)
  .replace(/^ {8}/gm, "")
  .replace("\\(revision)", "7")
  .replace("\\(scaleExpression)", "1");

assert(!annotationHelpers.includes("surroundContents"));
assert(!annotationHelpers.includes("extractContents"));

(async () => {
  let browser;
  try {
    browser = await chromium.launch({ headless: true });
  } catch (error) {
    browser = await chromium.launch({ headless: true, channel: "chrome" }).catch(() => {
      throw error;
    });
  }
  try {
    const page = await browser.newPage();
    await page.setContent(`
      <div class="mr-ios-frame" id="root">
        <p id="inline"><span>Hello </span><strong>brave</strong><a> new world</a></p>
        <p id="first">Alpha</p><p id="second">Beta</p>
        <p id="capture"><span>Select </span><strong>several</strong><a> words here</a></p>
        <p data-source-fragment-id="moved-fragment">Durable note text</p>
        <p id="wrong-page">Fallback note text</p>
      </div>
    `);

    const inline = await page.evaluate(({ helpers }) => {
      // Execute the exact JavaScript embedded in the iOS reader.
      window.blocked = new Set(["SCRIPT", "STYLE", "BUTTON"]);
      eval(helpers);
      const scope = document.getElementById("inline");
      const nodes = textNodes(scope);
      const before = nodes.map(node => node.nodeValue).join("");
      const ok = wrapGlobal(
        nodes,
        3,
        12,
        "mr-user-highlight",
        "#fff34d",
        { id: "personal-1", noted: true }
      );
      const marks = Array.from(scope.querySelectorAll("mark"));
      return {
        ok,
        before,
        after: scope.textContent,
        markedText: marks.map(mark => mark.textContent).join(""),
        count: marks.length,
        firstCount: scope.querySelectorAll("mark.is-first").length,
        lastCount: scope.querySelectorAll("mark.is-last").length,
        blockInsideMark: marks.some(mark => mark.querySelector("p,div,ul,li,table"))
      };
    }, { helpers: annotationHelpers });

    assert.strictEqual(inline.ok, true);
    assert.strictEqual(inline.after, inline.before);
    assert.strictEqual(inline.markedText, inline.before.slice(3, 15));
    assert(inline.count >= 3, "Cross-node selection must create multiple fragments");
    assert.strictEqual(inline.firstCount, 1);
    assert.strictEqual(inline.lastCount, 1);
    assert.strictEqual(inline.blockInsideMark, false);

    const structural = await page.evaluate(({ helpers }) => {
      window.blocked = new Set(["SCRIPT", "STYLE", "BUTTON"]);
      eval(helpers);
      const scope = document.getElementById("root");
      const nodes = textNodes(scope).filter(node =>
        ["first", "second"].includes(node.parentElement.id)
      );
      const before = nodes.map(node => node.nodeValue).join("");
      const ok = wrapGlobal(
        nodes,
        0,
        before.length,
        "mr-review-highlight",
        "#65dfff",
        { id: "review-1" }
      );
      return {
        ok,
        paragraphCount: scope.querySelectorAll(":scope > p").length,
        firstParent: document.querySelector("#first mark")?.parentElement.id,
        secondParent: document.querySelector("#second mark")?.parentElement.id,
        firstFragments: scope.querySelectorAll(
          'mark.is-first[data-highlight-id="review-1"]'
        ).length,
        lastFragments: scope.querySelectorAll(
          'mark.is-last[data-highlight-id="review-1"]'
        ).length,
        text: nodes.map(node => node.nodeValue).join("")
      };
    }, { helpers: annotationHelpers });

    assert.strictEqual(structural.ok, true);
    assert.strictEqual(structural.paragraphCount, 6);
    assert.strictEqual(structural.firstParent, "first");
    assert.strictEqual(structural.secondParent, "second");
    assert.strictEqual(structural.firstFragments, 1);
    assert.strictEqual(structural.lastFragments, 1);
    assert.strictEqual(structural.text, "AlphaBeta");

    const repaginatedReviewNotes = await page.evaluate(({ helpers }) => {
      window.blocked = new Set(["SCRIPT", "STYLE", "BUTTON"]);
      window.root = document.getElementById("root");
      eval(helpers);
      highlightExact(
        {
          id: "moved-review",
          fragment: "moved-fragment",
          anchor: "",
          text: "Durable note text",
          start: 0,
          prefix: "",
          suffix: ""
        },
        "mr-review-highlight"
      );
      highlightExact(
        {
          id: "missing-review",
          fragment: "fragment-on-another-page",
          anchor: "",
          text: "Fallback note text",
          start: 0,
          prefix: "",
          suffix: ""
        },
        "mr-review-highlight"
      );
      return {
        moved: document.querySelectorAll(
          '[data-source-fragment-id="moved-fragment"] mark.mr-review-highlight'
        ).length,
        wrongPage: document.querySelectorAll("#wrong-page mark.mr-review-highlight").length
      };
    }, { helpers: annotationFunctions });

    assert.strictEqual(
      repaginatedReviewNotes.moved,
      1,
      "Durably anchored review note must follow content to its repaginated page"
    );
    assert.strictEqual(
      repaginatedReviewNotes.wrongPage,
      0,
      "Missing durable anchor must not fall back to matching text on the wrong page"
    );

    const offsets = await page.evaluate(({ helpers }) => {
      eval(helpers);
      const scope = document.getElementById("capture");
      const nodes = annotationTextNodes(scope);
      const range = document.createRange();
      const start = scope.querySelector("span").firstChild;
      const end = scope.querySelector("a").firstChild;
      range.setStart(start, 2);
      range.setEnd(end, 4);
      const fullText = nodes.map(node => node.nodeValue).join("");
      const startOffset = boundaryOffset(nodes, range.startContainer, range.startOffset);
      const endOffset = boundaryOffset(nodes, range.endContainer, range.endOffset);
      return {
        selected: fullText.substring(startOffset, endOffset),
        expected: range.toString()
      };
    }, { helpers: selectionHelpers });

    assert.strictEqual(offsets.selected, offsets.expected);

    await page.setContent(`
      <div class="mr-ios-frame" data-layout-bound="1"
           style="width:816px;height:1056px">Stable page</div>
    `);
    await page.evaluate(({ script }) => {
      window.__readyMessages = [];
      window.webkit = {
        messageHandlers: {
          readerPageReady: {
            postMessage(message) {
              window.__readyMessages.push(message);
            }
          }
        }
      };
      eval(script);
    }, { script: readinessScript });
    await page.waitForFunction(() => window.__readyMessages.length === 1);
    const readiness = await page.evaluate(() => ({
      message: window.__readyMessages[0],
      transform: document.querySelector(".mr-ios-frame").style.transform
    }));
    assert.deepStrictEqual(readiness.message, { revision: 7, stable: true });
    assert.strictEqual(readiness.transform, "scale(1)");

    console.log("Manual reader annotation DOM check: PASS");
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exit(1);
});
