"use strict";

const fs = require("fs");
const path = require("path");
const { chromium } = require("../scripts/garmin/node_modules/playwright");
const { launchChromium } = require("../scripts/playwright_chromium_launcher.cjs");

function extractFunction(source, name, nextName) {
  const start = source.indexOf(`function ${name}(`);
  const end = source.indexOf(`function ${nextName}(`, start);
  if (start < 0 || end < 0) {
    throw new Error(`Unable to extract ${name} from the editor source.`);
  }
  return source.slice(start, end);
}

(async () => {
  const root = path.resolve(__dirname, "..");
  const editorSource = fs.readFileSync(
    path.join(root, "public/assets/controlled_book_editor.js"),
    "utf8"
  );
  const rangeFunction = extractFunction(
    editorSource,
    "reviewThreadTextRange",
    "loadReviewThreadMarkers"
  );

  const browser = await launchChromium(chromium);
  try {
    const page = await browser.newPage();
    await page.setContent(
      '<div id="target"><p>His/Her <strong>responsibilities</strong> are:</p></div>'
    );
    await page.addScriptTag({ content: rangeFunction });
    const result = await page.evaluate(() => {
      const target = document.getElementById("target");
      const originalHTML = target.innerHTML;
      const range = reviewThreadTextRange(target, {
        selected_text: "His/Her responsibilities are:",
        start_offset: 999,
        end_offset: 1000,
      });
      if (!range) throw new Error("Reviewer range was not resolved.");
      CSS.highlights.set("cpb-review-remarks", new Highlight(range));
      return {
        text: range.toString(),
        htmlUnchanged: target.innerHTML === originalHTML,
        highlightCount: CSS.highlights.get("cpb-review-remarks").size,
      };
    });

    if (result.text !== "His/Her responsibilities are:") {
      throw new Error(`Unexpected reviewer range text: ${result.text}`);
    }
    if (!result.htmlUnchanged) {
      throw new Error("Reviewer highlighting mutated the editable source DOM.");
    }
    if (result.highlightCount !== 1) {
      throw new Error("Reviewer CSS highlight was not registered.");
    }
    process.stdout.write("Controlled book editor reviewer highlight check: PASS\n");
  } finally {
    await browser.close();
  }
})().catch(error => {
  process.stderr.write(`${error && error.stack ? error.stack : error}\n`);
  process.exit(1);
});
