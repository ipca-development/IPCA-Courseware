#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");
const { chromium } = require("./garmin/node_modules/playwright");

function argument(name, fallback = "") {
  const prefix = `--${name}=`;
  const value = process.argv.find((item) => item.startsWith(prefix));
  return value ? value.slice(prefix.length) : fallback;
}

function layout(viewportWidth, viewportHeight, landscape, fontScale) {
  const canonicalPageWidth = 816;
  const canonicalPageHeight = 1056;
  const gutterWidth = 0;
  const pageCount = landscape ? 2 : 1;
  const scale = Math.min(
    (viewportWidth - gutterWidth) / (canonicalPageWidth * pageCount),
    viewportHeight / canonicalPageHeight
  );
  const pageWidth = canonicalPageWidth * scale;
  const pageHeight = canonicalPageHeight * scale;
  const sideMargin = 56 * scale;
  const topMargin = 48 * scale;
  const bottomMargin = 64 * scale;
  const headerHeight = 84 * scale;
  const headerGap = 20 * scale;
  const footerGap = 24 * scale;
  const footerHeight = 72 * scale;
  const contentY = topMargin + headerHeight + headerGap;
  const contentHeight = 744 * scale;
  return {
    viewportWidth,
    viewportHeight,
    safeAreaInsets: { top: 0, leading: 0, bottom: 0, trailing: 0 },
    pageWidth,
    pageHeight,
    canonicalPageWidth,
    canonicalPageHeight,
    headerFrame: {
      x: sideMargin,
      y: topMargin,
      width: pageWidth - sideMargin * 2,
      height: headerHeight
    },
    contentFrame: {
      x: sideMargin,
      y: contentY,
      width: pageWidth - sideMargin * 2,
      height: contentHeight
    },
    footerFrame: {
      x: sideMargin,
      y: contentY + contentHeight + footerGap,
      width: pageWidth - sideMargin * 2,
      height: footerHeight
    },
    innerMargin: sideMargin,
    outerMargin: sideMargin,
    topMargin,
    bottomMargin,
    gutterWidth,
    pageScale: scale,
    mode: landscape ? "twoPageSpread" : "singlePage",
    fontScale,
    layoutVersion: "reader-page-frame-v3"
  };
}

async function paginate(browser, source, css, core, configuration, baseURL) {
  const page = await browser.newPage({
    viewport: {
      width: Math.ceil(configuration.pageWidth),
      height: Math.ceil(configuration.pageHeight)
    }
  });
  try {
    await page.setContent(
      `<!doctype html><html><head><base href="${baseURL}"><style>${css}</style></head>`
        + `<style>
          .reader-page-header-region > .cpb-page-header,
          .reader-page-footer-region > .cpb-page-footer {
            position: static !important; inset: auto !important;
            width: 100% !important; height: 100% !important;
            margin: 0 !important; box-sizing: border-box !important;
          }
        </style><body><div id="pagination-measure-host"></div></body></html>`,
      { waitUntil: "domcontentloaded" }
    );
    await page.evaluate(({ sourceValue, layoutValue }) => {
      window.IPCAPaginationInput = { source: sourceValue, layout: layoutValue };
      window.__paginationResult = new Promise((resolve) => {
        window.webkit = {
          messageHandlers: {
            pagination: { postMessage: resolve }
          }
        };
      });
    }, { sourceValue: source, layoutValue: configuration });
    await page.addScriptTag({ content: core });
    return await page.evaluate(() => window.__paginationResult);
  } finally {
    await page.close();
  }
}

async function main() {
  const root = path.resolve(__dirname, "..");
  const sourcePath = argument("source");
  if (!sourcePath) throw new Error("Provide --source=/path/to/paginate-source.json");
  const baseURL = argument("base-url", "http://127.0.0.1/");
  const source = JSON.parse(fs.readFileSync(sourcePath, "utf8"));
  const css = [
    fs.readFileSync(path.join(root, "public/assets/manual_reader_content.css"), "utf8"),
    fs.readFileSync(path.join(root, "public/assets/manual_reader.css"), "utf8")
  ].join("\n");
  const core = fs.readFileSync(
    path.join(
      root,
      "ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js"
    ),
    "utf8"
  );
  const browser = await chromium.launch({ headless: true });
  try {
    const matrix = [
      ["ipad-landscape", layout(1366, 1024, true, 1)],
      ["ipad-portrait", layout(1024, 1366, false, 1)],
      ["iphone-portrait", layout(393, 852, false, 1)]
    ];
    const summary = [];
    for (const [name, configuration] of matrix) {
      const result = await paginate(browser, source, css, core, configuration, baseURL);
      const failures = (result.validation?.diagnostics || []).filter(
        (item) => item.severity === "failure"
      );
      if (result.error || result.validation?.is_valid !== true || failures.length) {
        console.error(JSON.stringify({
          configuration: name,
          error: result.error || null,
          failures: failures.slice(0, 20)
        }, null, 2));
        process.exitCode = 1;
        return;
      }
      summary.push({
        configuration: name,
        pages: result.pages.length,
        source_fragments: result.validation.source_fragment_count,
        covered_fragments: result.validation.covered_fragment_count,
        warnings: (result.validation.diagnostics || []).filter(
          (item) => item.severity === "warning"
        ).length
      });
    }
    console.log(JSON.stringify({
      ok: true,
      book_key: source.book_key,
      version_id: source.version_id,
      normalizer_version: "reader-normalizer-v1",
      pagination_engine_version: "semantic-paginator-v2",
      exactly_once_source_coverage: true,
      results: summary
    }, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error && error.stack || String(error));
  process.exit(1);
});
