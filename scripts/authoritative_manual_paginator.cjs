#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");

(function configurePlaywrightBrowsersPath() {
  const configured = String(process.env.CW_PAGINATION_PLAYWRIGHT_BROWSERS_PATH || "").trim();
  if (configured) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = configured;
  }
})();

const { chromium } = require("./garmin/node_modules/playwright");
const { launchChromium } = require("./playwright_chromium_launcher.cjs");

function argument(name) {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 ? process.argv[index + 1] || "" : "";
}

function fail(message) {
  process.stderr.write(`${message}\n`);
  process.exit(1);
}

async function remoteImageAssets(htmlValues) {
  const urls = new Set();
  htmlValues.forEach((html) => {
    for (const match of String(html).matchAll(/\bsrc=(["'])(https?:\/\/[^"']+)\1/gi)) {
      urls.add(match[2].replaceAll("&amp;", "&"));
    }
  });
  const assets = new Map();
  await Promise.all(Array.from(urls).map(async (url) => {
    const response = await fetch(url, { signal: AbortSignal.timeout(15000) });
    if (!response.ok) throw new Error(`Unable to load publication image ${url}: HTTP ${response.status}`);
    const body = Buffer.from(await response.arrayBuffer());
    if (body.length > 10 * 1024 * 1024) {
      throw new Error(`Publication image exceeds the 10 MB pagination limit: ${url}`);
    }
    assets.set(url, {
      body,
      contentType: response.headers.get("content-type") || "application/octet-stream",
      dataUrl: `data:${response.headers.get("content-type") || "application/octet-stream"};base64,${body.toString("base64")}`
    });
  }));
  return assets;
}

function replacePublicationImages(value, assets, restore = false) {
  if (Array.isArray(value)) {
    return value.map((item) => replacePublicationImages(item, assets, restore));
  }
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [
      key,
      replacePublicationImages(item, assets, restore)
    ]));
  }
  if (typeof value !== "string") return value;
  let output = value;
  assets.forEach((asset, url) => {
    output = restore
      ? output.split(asset.dataUrl).join(url)
      : output.split(url).join(asset.dataUrl);
  });
  return output;
}

async function main() {
  const inputPath = argument("input");
  const outputPath = argument("output");
  if (!inputPath || !outputPath) fail("Provide --input and --output.");

  const root = path.resolve(__dirname, "..");
  const input = JSON.parse(fs.readFileSync(inputPath, "utf8"));
  const source = input.source;
  const css = String(input.book_style_css || "");
  if (!source || !Array.isArray(source.sections) || !css) {
    fail("Authoritative pagination input is incomplete.");
  }

  const profile = source.layout || {};
  const pageWidth = Number(profile.page_width_px || 816);
  const pageHeight = Number(profile.page_height_px || 1056);
  const side = Number(profile.sheet_padding_x_px || 56);
  const top = Number(profile.sheet_padding_top_px || 48);
  const bottom = Number(profile.sheet_padding_bottom_px || 64);
  const headerHeight = Number(profile.header_band_px || 64);
  const headerGap = Number(profile.header_margin_bottom_px || 20);
  const footerGap = Number(profile.footer_margin_top_px || 24);
  const footerHeight = Number(profile.footer_band_px || 34);
  const contentY = top + headerHeight + headerGap;
  const contentHeight = Number(profile.body_capacity_px || (
    pageHeight - contentY - footerGap - footerHeight - bottom
  ));
  const layout = {
    viewportWidth: pageWidth,
    viewportHeight: pageHeight,
    safeAreaInsets: { top: 0, leading: 0, bottom: 0, trailing: 0 },
    pageWidth,
    pageHeight,
    canonicalPageWidth: pageWidth,
    canonicalPageHeight: pageHeight,
    headerFrame: { x: side, y: top, width: pageWidth - side * 2, height: headerHeight },
    contentFrame: {
      x: side,
      y: contentY,
      width: pageWidth - side * 2,
      height: contentHeight
    },
    footerFrame: {
      x: side,
      y: contentY + contentHeight + footerGap,
      width: pageWidth - side * 2,
      height: footerHeight
    },
    innerMargin: side,
    outerMargin: side,
    topMargin: top,
    bottomMargin: bottom,
    headerBodySpacing: headerGap,
    bodyFooterSpacing: footerGap,
    gutterWidth: 0,
    pageScale: 1,
    mode: "singlePage",
    fontScale: 1,
    layoutVersion: String(source.layout_profile || "AUTHORITATIVE_SERVER_v1")
  };

  const core = fs.readFileSync(
    path.join(
      root,
      "ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js"
    ),
    "utf8"
  );
  const headerTemplates = Array.from(new Set([
    String(source.header_template_html || ""),
    ...source.sections.map((section) => String(section?.header_template || ""))
  ].filter(Boolean)));
  const footerTemplates = Array.from(new Set([
    String(source.footer_template_html || ""),
    ...source.sections.map((section) => String(section?.footer_template || ""))
  ].filter(Boolean)));
  const publicationImages = await remoteImageAssets([...headerTemplates, ...footerTemplates]);
  const hydratedSource = replacePublicationImages(source, publicationImages);
  const hydratedHeaderTemplates = replacePublicationImages(headerTemplates, publicationImages);
  const hydratedFooterTemplates = replacePublicationImages(footerTemplates, publicationImages);
  const browser = await launchChromium(chromium);
  try {
    const page = await browser.newPage({
      viewport: { width: Math.ceil(pageWidth), height: Math.ceil(pageHeight) }
    });
    await page.route("**/*", async (route) => {
      const url = new URL(route.request().url());
      const cached = publicationImages.get(url.href);
      if (cached) {
        await route.fulfill({
          status: 200,
          body: cached.body,
          contentType: cached.contentType
        });
        return;
      }
      if (url.origin !== "http://authoritative.local") {
        await route.continue();
        return;
      }
      const relative = decodeURIComponent(url.pathname).replace(/^\/+/, "");
      const localPath = path.resolve(root, "public", relative);
      const publicRoot = path.resolve(root, "public") + path.sep;
      if (!localPath.startsWith(publicRoot) || !fs.existsSync(localPath)) {
        await route.abort();
        return;
      }
      await route.fulfill({ path: localPath });
    });
    await page.setContent(
      `<!doctype html>
      <html><head>
        <base href="http://authoritative.local/">
        <meta charset="utf-8">
        <style>${css.replace(/<\/style/gi, "<\\/style")}</style>
        <style>
          html,body{margin:0;padding:0;width:${pageWidth}px;min-height:${pageHeight}px}
          #pagination-measure-host{position:absolute;inset:0 auto auto 0;visibility:hidden;pointer-events:none}
          .reader-semantic-piece{break-inside:avoid;max-width:100%}
          .reader-semantic-piece table{width:100%;max-width:100%;table-layout:fixed}
          .reader-semantic-piece .cpb-table-wrap{
            width:calc(100% - var(--cpb-table-border-width,1px))!important;
            max-width:calc(100% - var(--cpb-table-border-width,1px))!important
          }
          .reader-semantic-piece img{max-width:100%;height:auto}
          .reader-canonical-page.cpb-sheet{
            padding:0!important;margin:0!important;zoom:1;max-width:none;
            min-height:0;box-shadow:none;border-radius:0
          }
          .reader-page-body .cpb-sheet:not(.reader-canonical-page){
            width:100%!important;max-width:100%!important;min-width:0!important;
            height:auto!important;min-height:0!important;padding:0!important;
            margin:0!important;box-shadow:none!important;position:static!important
          }
          .reader-page-header-region,
          .reader-page-footer-region,
          .reader-page-body:not(.reader-page-cover){overflow:hidden}
          .reader-page-header-region>.cpb-page-header,
          .reader-page-footer-region>.cpb-page-footer{
            position:static!important;inset:auto!important;width:100%!important;
            height:auto!important;margin:0!important;box-sizing:border-box!important
          }
        </style>
      </head><body><div id="pagination-measure-host"></div></body></html>`,
      { waitUntil: "domcontentloaded" }
    );
    const measuredBands = await page.evaluate(async ({
      headerTemplates,
      footerTemplates,
      width
    }) => {
      const templates = (kind) => kind === "header" ? headerTemplates : footerTemplates;
      const measure = async (html, kind) => {
        const region = document.createElement("div");
        region.className = `reader-page-${kind}-measurement`;
        region.style.cssText = `position:absolute;left:0;top:0;width:${width}px;`
          + "height:auto;visibility:hidden;overflow:visible;";
        region.innerHTML = html;
        const root = region.firstElementChild;
        if (root) {
          root.style.setProperty("position", "static", "important");
          root.style.setProperty("inset", "auto", "important");
          root.style.setProperty("width", "100%", "important");
          root.style.setProperty("height", "auto", "important");
          root.style.setProperty("margin", "0", "important");
          root.style.setProperty("box-sizing", "border-box", "important");
        }
        document.body.appendChild(region);
        await Promise.all(Array.from(region.querySelectorAll("img")).map(async (image) => {
          if (image.complete) return;
          try {
            await image.decode();
          } catch (_) {
            await new Promise((resolve) => {
              image.addEventListener("load", resolve, { once: true });
              image.addEventListener("error", resolve, { once: true });
            });
          }
        }));
        const height = Math.max(
          root ? root.getBoundingClientRect().height : 0,
          root ? root.scrollHeight : 0,
          region.scrollHeight
        );
        region.remove();
        return height;
      };
      const heights = async (kind) => Promise.all(
        templates(kind).map((template) => measure(template, kind))
      );
      const [headerHeights, footerHeights] = await Promise.all([
        heights("header"),
        heights("footer")
      ]);
      return {
        header: Math.max(0, ...headerHeights),
        footer: Math.max(0, ...footerHeights)
      };
    }, {
      headerTemplates: hydratedHeaderTemplates,
      footerTemplates: hydratedFooterTemplates,
      width: pageWidth - side * 2
    });
    const resolvedHeaderHeight = measuredBands.header || headerHeight;
    const resolvedFooterHeight = measuredBands.footer || footerHeight;
    const resolvedContentY = top + resolvedHeaderHeight + headerGap;
    const resolvedFooterY = pageHeight - bottom - resolvedFooterHeight;
    const resolvedContentHeight = resolvedFooterY - footerGap - resolvedContentY;
    if (resolvedContentHeight <= 0) {
      fail("Book Style header/footer bands leave no positive page body.");
    }
    layout.headerFrame.height = resolvedHeaderHeight;
    layout.contentFrame.y = resolvedContentY;
    layout.contentFrame.height = resolvedContentHeight;
    layout.footerFrame.y = resolvedFooterY;
    layout.footerFrame.height = resolvedFooterHeight;
    await page.evaluate(({ sourceValue, layoutValue, incrementalValue }) => {
      window.IPCAPaginationInput = {
        source: sourceValue,
        layout: layoutValue,
        incremental: incrementalValue,
        officialPageByAnchor: {},
        officialPageBySection: {},
        officialPageTotal: null
      };
      window.__authoritativePagination = new Promise((resolve) => {
        window.webkit = {
          messageHandlers: {
            pagination: { postMessage: resolve }
          }
        };
      });
    }, {
      sourceValue: hydratedSource,
      layoutValue: layout,
      incrementalValue: input.incremental && typeof input.incremental === "object"
        ? input.incremental
        : null
    });
    await page.addScriptTag({ content: core });
    const result = await page.evaluate(() => window.__authoritativePagination);
    if (result && Array.isArray(result.pages)) {
      result.pages = replacePublicationImages(result.pages, publicationImages, true);
    }
    if (
      result
      && result.error
      && ["MANUAL_BREAK_REQUIRED", "INCREMENTAL_PREFIX_MISMATCH"].includes(result.error.code)
    ) {
      fs.writeFileSync(outputPath, JSON.stringify({
        ok: false,
        error: result.error,
        pages: [],
        section_page_index: {},
        validation: result.validation || { is_valid: false },
        authoritative_layout: layout,
        engine_version: result.engine_version || null
      }));
      return;
    }
    if (result.error || result.validation?.is_valid !== true) {
      const failures = (result.validation?.diagnostics || [])
        .filter((item) => item.severity === "failure")
        .slice(0, 12);
      fail(JSON.stringify({ error: result.error || "validation failed", failures }));
    }
    fs.writeFileSync(outputPath, JSON.stringify({
      ok: true,
      ...result,
      authoritative_layout: layout
    }));
  } finally {
    await browser.close();
  }
}

main().catch((error) => fail(error && error.stack || String(error)));
