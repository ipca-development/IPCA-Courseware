#!/usr/bin/env node
"use strict";

const fs = require("fs");
const { chromium } = require("./garmin/node_modules/playwright");

async function launchChromium() {
  const executablePath = String(process.env.CW_PAGINATION_CHROMIUM_EXECUTABLE || "").trim();
  if (executablePath) {
    return chromium.launch({ headless: true, executablePath });
  }
  try {
    return await chromium.launch({ headless: true });
  } catch (bundledError) {
    try {
      return await chromium.launch({ headless: true, channel: "chrome" });
    } catch (_) {
      throw bundledError;
    }
  }
}

async function main() {
  const inputPath = process.argv[2];
  const outputPath = process.argv[3];
  if (!inputPath || !outputPath) {
    throw new Error("Usage: render_annex_pdf.cjs input.json output.pdf");
  }

  const input = JSON.parse(fs.readFileSync(inputPath, "utf8"));
  const width = Math.max(100, Number(input.width) || 794);
  const height = Math.max(100, Number(input.height) || 1123);
  const pages = Array.isArray(input.pages) ? input.pages : [];
  if (!pages.length) throw new Error("No annex pages were supplied.");

  const baseURL = String(input.base_url || "https://ipca.training/");
  const css = String(input.css || "");
  const html = `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <base href="${baseURL.replace(/"/g, "&quot;")}">
  <style>
    @page { size: ${width}px ${height}px; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff; }
    ${css}
    .annex-pdf-page {
      position: relative;
      width: ${width}px;
      height: ${height}px;
      margin: 0;
      overflow: hidden;
      break-after: page;
      page-break-after: always;
    }
    .annex-pdf-page:last-child { break-after: auto; page-break-after: auto; }
  </style>
</head>
<body>${pages.map(page => `<div class="annex-pdf-page">${page}</div>`).join("")}</body>
</html>`;

  const browser = await launchChromium();
  try {
    const page = await browser.newPage({ viewport: { width: Math.ceil(width), height: Math.ceil(height) } });
    await page.setContent(html, { waitUntil: "networkidle", timeout: 30000 });
    await page.evaluate(async () => {
      if (document.fonts && document.fonts.ready) await document.fonts.ready;
      const images = Array.from(document.images || []);
      await Promise.all(images.map(image => image.complete
        ? Promise.resolve()
        : new Promise(resolve => {
            image.addEventListener("load", resolve, { once: true });
            image.addEventListener("error", resolve, { once: true });
          })));
    });
    if (outputPath.toLowerCase().endsWith(".png")) {
      await page.screenshot({
        path: outputPath,
        type: "png",
        clip: { x: 0, y: 0, width, height },
      });
    } else {
      await page.pdf({
        path: outputPath,
        width: `${width}px`,
        height: `${height}px`,
        printBackground: true,
        margin: { top: "0px", right: "0px", bottom: "0px", left: "0px" },
        preferCSSPageSize: true,
      });
    }
  } finally {
    await browser.close();
  }
}

main().catch(error => {
  process.stderr.write(`${error && error.stack ? error.stack : error}\n`);
  process.exit(1);
});
