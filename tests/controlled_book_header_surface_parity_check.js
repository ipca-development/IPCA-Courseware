"use strict";

const fs = require("fs");
const path = require("path");
const { chromium } = require("../scripts/garmin/node_modules/playwright");
const { launchChromium } = require("../scripts/playwright_chromium_launcher.cjs");

function sliceBetween(source, startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  if (start < 0 || end < 0) {
    throw new Error(`Unable to extract ${startMarker}`);
  }
  return source.slice(start, end);
}

(async () => {
  const root = path.resolve(__dirname, "..");
  const editorJS = fs.readFileSync(
    path.join(root, "public/assets/controlled_book_editor.js"),
    "utf8"
  );
  const editorCSS = fs.readFileSync(
    path.join(root, "public/assets/controlled_book_editor.css"),
    "utf8"
  );
  const previewFunctions = sliceBetween(
    editorJS,
    "function headerColumnFromBand(",
    "function defaultTableStyleDef("
  );
  const measureFunction = sliceBetween(
    editorJS,
    "function measurePrintFurnitureGeometry(",
    "function applyUnifiedPrintLayout("
  );

  const browser = await launchChromium(chromium);
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 1200 } });
    await page.setContent('<div id="test-root"></div>');
    await page.addStyleTag({ content: editorCSS });
    await page.addScriptTag({
      content: `
        var FONT_STACKS = {
          serif: "Georgia, 'Times New Roman', serif",
          sans: "system-ui, -apple-system, 'Segoe UI', sans-serif",
          mono: "'Courier New', Courier, monospace",
          arial: "Arial, Helvetica, sans-serif"
        };
        var state = {
          pageLayout: {},
          headerPreviewTokens: {
            page: "1",
            page_total: "10",
            revision: "6.0",
            date: "15/06/2026",
            manual_code: "OM",
            book_title: "Operations Manual",
            part_title: "PART 1 – MAIN CONTENT"
          },
          versionInfo: {}
        };
        var PRINT_PAGE = {
          width: 816, height: 1056, gap: 28, side: 56,
          contentTop: 152, contentHeight: 744,
          headerTop: 48, headerHeight: 84, headerGap: 20,
          footerTop: 920, footerHeight: 72, footerGap: 24, bottomMargin: 64
        };
        function escapeHtml(value) {
          return String(value == null ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;")
            .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }
        function defaultPageHeader() { return {}; }
        function defaultPageFooter() { return {}; }
        ${previewFunctions}
        ${measureFunction}
      `,
    });

    const result = await page.evaluate(() => {
      const logo = "data:image/svg+xml;base64,"
        + btoa('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="80">'
          + '<rect width="320" height="80" fill="#fff"/>'
          + '<path d="M0 20h320v20H0z" fill="#0f2744"/></svg>');
      const header = {
        enabled: true,
        logo_url: logo,
        logo_alt: "EuroPilot Center",
        logo_max_height: 72,
        row_height: 24,
        center_text: "{book_title} ({manual_code})\\n{part_title}",
        center_font_family: "sans",
        center_font_size: 12,
        center_font_bold: true,
        right_text: "Page: {page}\\nRevision: {revision}\\nDate: {date}",
        right_font_family: "sans",
        right_font_size: 9,
      };
      const footer = {
        enabled: true,
        row_height: 26,
        left_text: "Copyright – EuroPilot Center",
        center_text: "",
        right_text: "Page: {page}",
        left_font_family: "sans",
        left_font_size: 9,
        center_font_family: "sans",
        center_font_size: 9,
        right_font_family: "sans",
        right_font_size: 9,
      };
      state.pageHeader = header;
      state.pageFooter = footer;

      const sheet = document.createElement("div");
      sheet.className = "cpb-sheet cpb-print-layout";
      sheet.innerHTML = '<div class="cpb-sheet-body" data-blocks-root="1"></div>';
      document.body.appendChild(sheet);
      measurePrintFurnitureGeometry(sheet);

      const modal = document.createElement("div");
      modal.className = "cpb-header-preview";
      modal.innerHTML = previewHeaderHtml(header, footer, 1, 10);
      document.body.appendChild(modal);

      const editorPage = document.createElement("div");
      editorPage.className = "cpb-print-page";
      editorPage.innerHTML = previewHeaderHtml(header, footer, 1, 10);
      const editorHeader = editorPage.querySelector(".cpb-page-header");
      const editorFooter = editorPage.querySelector(".cpb-page-footer");
      editorHeader.classList.add("cpb-print-page-header");
      editorFooter.classList.add("cpb-print-page-footer");
      sheet.appendChild(editorPage);

      const iosHeaderRegion = document.createElement("div");
      iosHeaderRegion.style.cssText = `width:704px;height:${PRINT_PAGE.headerHeight}px`;
      iosHeaderRegion.innerHTML = previewHeaderHtml(header, { enabled: false }, 1, 10);
      const iosHeader = iosHeaderRegion.querySelector(".cpb-page-header");
      iosHeader.style.cssText =
        "position:static;width:100%;height:100%;margin:0;box-sizing:border-box";
      document.body.appendChild(iosHeaderRegion);

      const iosFooterRegion = document.createElement("div");
      iosFooterRegion.style.cssText = `width:704px;height:${PRINT_PAGE.footerHeight}px`;
      iosFooterRegion.innerHTML = previewHeaderHtml({ enabled: false }, footer, 1, 10);
      const iosFooter = iosFooterRegion.querySelector(".cpb-page-footer");
      iosFooter.style.cssText =
        "position:static;width:100%;height:100%;margin:0;box-sizing:border-box";
      document.body.appendChild(iosFooterRegion);

      const disabledHTML = previewHeaderHtml(
        { ...header, enabled: false },
        { ...footer, enabled: false },
        1,
        10
      );
      return {
        measuredHeader: PRINT_PAGE.headerHeight,
        measuredFooter: PRINT_PAGE.footerHeight,
        modalHeader: modal.querySelector(".cpb-page-header").getBoundingClientRect().height,
        modalFooter: modal.querySelector(".cpb-page-footer").getBoundingClientRect().height,
        editorHeader: editorHeader.getBoundingClientRect().height,
        editorFooter: editorFooter.getBoundingClientRect().height,
        iosHeader: iosHeader.getBoundingClientRect().height,
        iosFooter: iosFooter.getBoundingClientRect().height,
        modalWidth: modal.querySelector(".cpb-page-header").getBoundingClientRect().width,
        disabledHTML,
      };
    });

    const close = (a, b) => Math.abs(a - b) <= 0.1;
    for (const key of ["modalHeader", "editorHeader", "iosHeader"]) {
      if (!close(result[key], result.measuredHeader)) {
        throw new Error(
          `${key}=${result[key]} does not match measured header=${result.measuredHeader}.`
        );
      }
    }
    for (const key of ["modalFooter", "editorFooter", "iosFooter"]) {
      if (!close(result[key], result.measuredFooter)) {
        throw new Error(
          `${key}=${result[key]} does not match measured footer=${result.measuredFooter}.`
        );
      }
    }
    if (!close(result.modalWidth, 704)) {
      throw new Error(`Header preview width is ${result.modalWidth}, expected 704.`);
    }
    if (result.disabledHTML !== "") {
      throw new Error("Disabled page furniture is still rendered.");
    }
    process.stdout.write("Controlled book header surface parity check: PASS\n");
  } finally {
    await browser.close();
  }
})().catch(error => {
  process.stderr.write(`${error && error.stack ? error.stack : error}\n`);
  process.exit(1);
});
