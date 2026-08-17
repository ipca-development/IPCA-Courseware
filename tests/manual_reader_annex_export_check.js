"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const root = path.resolve(__dirname, "..");
const tempDirectory = fs.mkdtempSync(path.join(os.tmpdir(), "ipca-annex-export-"));
const inputPath = path.join(tempDirectory, "input.json");
const pdfPath = path.join(tempDirectory, "annex.pdf");
const pngPath = path.join(tempDirectory, "cover.png");

try {
  fs.writeFileSync(inputPath, JSON.stringify({
    base_url: "https://ipca.training/",
    width: 320,
    height: 450,
    css: ".cpb-sheet{width:320px;height:450px;background:#fff}",
    pages: [
      "<div class=\"cpb-sheet\"><h1>Annex 01</h1><p>Authoritative annex page.</p></div>",
      "<div class=\"cpb-sheet\"><h1>Annex 01 continued</h1></div>",
    ],
  }));

  for (const outputPath of [pdfPath, pngPath]) {
    const result = spawnSync(
      process.execPath,
      [path.join(root, "scripts/render_annex_pdf.cjs"), inputPath, outputPath],
      {
        cwd: root,
        encoding: "utf8",
        timeout: 60000,
        env: {
          ...process.env,
          CW_PAGINATION_CHROMIUM_EXECUTABLE: "/definitely/missing/chromium",
        },
      }
    );
    assert.strictEqual(result.status, 0, result.stderr || result.stdout);
    assert.ok(fs.statSync(outputPath).size > 100, `${path.basename(outputPath)} is empty`);
  }

  assert.strictEqual(fs.readFileSync(pdfPath, { encoding: "ascii", flag: "r" }).slice(0, 4), "%PDF");
  assert.deepStrictEqual(
    Array.from(fs.readFileSync(pngPath).subarray(0, 8)),
    [137, 80, 78, 71, 13, 10, 26, 10]
  );
  process.stdout.write("Manual reader Annex export check: PASS\n");
} finally {
  fs.rmSync(tempDirectory, { recursive: true, force: true });
}
