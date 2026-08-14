#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const root = path.resolve(__dirname, "..");
const playwrightPath = path.join(__dirname, "garmin/node_modules/playwright");
const fixturePath = path.join(root, "tests/fixtures/publication_geometry_golden.json");
const policyPath = path.join(root, "tests/fixtures/publication_geometry_golden_policy.json");
const corePath = path.join(
  root,
  "ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js"
);
const editorCSSPath = path.join(root, "public/assets/controlled_book_editor.css");
const artifactsDirectory = path.join(root, "tests/artifacts/publication-geometry");

function argument(name, fallback = "") {
  const prefix = `--${name}=`;
  const value = process.argv.find((item) => item.startsWith(prefix));
  return value ? value.slice(prefix.length) : fallback;
}

function stable(value) {
  if (Array.isArray(value)) return value.map(stable);
  if (!value || typeof value !== "object") return value;
  return Object.keys(value).sort().reduce((output, key) => {
    output[key] = stable(value[key]);
    return output;
  }, {});
}

function sha256(value) {
  return crypto.createHash("sha256").update(value).digest("hex");
}

function loadPlaywright() {
  try {
    return require(playwrightPath);
  } catch (error) {
    console.error(`Playwright is unavailable at ${playwrightPath}.`);
    console.error("Install the existing QA runtime, then install WebKit:");
    console.error("  cd scripts/garmin && npm install && npx playwright install webkit");
    throw error;
  }
}

const measurements = [
  ["header", ".cpb-page-header"],
  ["header.logo", ".cpb-page-header-logo"],
  ["header.title", ".cpb-page-header-cell--center"],
  ["header.metadata", ".cpb-page-header-cell--right"],
  ["footer", ".cpb-page-footer"],
  ["footer.left", ".cpb-page-footer .cpb-page-header-cell--left"],
  ["footer.center", ".cpb-page-footer .cpb-page-header-cell--center"],
  ["footer.right", ".cpb-page-footer .cpb-page-header-cell--right"],
  ["heading", "#golden-heading-1 .cpb-heading"],
  ["paragraph", "#golden-paragraph-2 .cpb-paragraph"],
  ["ordered_list", "#golden-list-3 ol"],
  ["unordered_list", "#golden-list-4 ul"],
  ["note", "#golden-callout-5 .cpb-callout"],
  ["note.title", "#golden-callout-5 .cpb-callout-title"],
  ["note.text", "#golden-callout-5 .cpb-callout-text"],
  ["table", ".cpb-table"],
  ["table.title", ".cpb-table-title-row td"],
  ["table.header", ".cpb-table-header-row th"],
  ["table.cell", ".cpb-table tbody td"],
  ["figure", "#golden-image-7 figure"],
  ["figure.image", "#golden-image-7 img"],
  ["figure.caption", "#golden-image-7 figcaption"]
];
const styleProperties = [
  "fontFamily", "fontSize", "fontWeight", "fontStyle", "lineHeight",
  "marginTop", "marginBottom", "paddingTop", "paddingRight", "paddingBottom",
  "paddingLeft", "textAlign", "color", "backgroundColor", "borderTopWidth",
  "borderTopColor", "listStyleType"
];

const harnessCSS = `
  html,body{margin:0!important;padding:0!important;width:816px;height:1056px;background:#fff;overflow:hidden}
  .parity-page{position:relative!important;box-sizing:border-box!important;width:816px!important;height:1056px!important;
    min-height:1056px!important;max-width:none!important;margin:0!important;padding:0!important;box-shadow:none!important;
    border-radius:0!important;background:#fff!important}
  .parity-page>.cpb-page-header{position:absolute!important;left:56px!important;top:48px!important;
    width:704px!important;height:84px!important;margin:0!important}
  .parity-page>.cpb-sheet-body{position:absolute!important;left:56px!important;top:152px!important;
    width:704px!important;height:744px!important;margin:0!important;padding:0!important}
  .parity-page>.cpb-page-footer{position:absolute!important;left:56px!important;top:920px!important;
    width:704px!important;height:72px!important;margin:0!important}
  .reader-generated-page{margin:0!important;background:#fff!important}
`;

async function waitForStablePage(page) {
  await page.evaluate(async () => {
    if (document.fonts?.ready) await document.fonts.ready;
    await Promise.all(Array.from(document.images).map((image) => {
      if (image.complete) return Promise.resolve();
      return new Promise((resolve) => {
        image.addEventListener("load", resolve, { once: true });
        image.addEventListener("error", resolve, { once: true });
      });
    }));
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
}

async function createOnlinePage(browser, fixture, editorCSS) {
  const page = await browser.newPage({ viewport: { width: 816, height: 1056 }, deviceScaleFactor: 1 });
  await page.setContent(
    `<!doctype html><html><head><meta charset="utf-8"><style>${editorCSS}\n${harnessCSS}</style></head>`
      + `<body>${fixture.online_page_html}</body></html>`,
    { waitUntil: "domcontentloaded" }
  );
  await page.evaluate(() => document.querySelector(".cpb-sheet")?.classList.add("parity-page"));
  await waitForStablePage(page);
  return page;
}

async function paginate(browser, fixture, core, readerCSS) {
  const page = await browser.newPage({ viewport: { width: 816, height: 1056 }, deviceScaleFactor: 1 });
  const engineHarnessCSS = `
    html,body{margin:0;padding:0;width:816px;min-height:1056px}
    #pagination-measure-host{position:absolute;inset:0 auto auto 0;visibility:hidden;pointer-events:none}
    .reader-semantic-piece{break-inside:avoid;max-width:100%}
    .reader-semantic-piece table{width:100%;max-width:100%;table-layout:fixed}
    .reader-semantic-piece img{max-width:100%;height:auto}
  `;
  await page.setContent(
    `<!doctype html><html><head><meta charset="utf-8"><style>${fixture.book_style_css}\n`
      + `${readerCSS}\n${engineHarnessCSS}</style></head>`
      + '<body><div id="pagination-measure-host"></div></body></html>',
    { waitUntil: "domcontentloaded" }
  );
  await page.evaluate(({ source, layout }) => {
    window.IPCAPaginationInput = { source, layout };
    window.__paginationResult = new Promise((resolve) => {
      window.webkit = { messageHandlers: { pagination: { postMessage: resolve } } };
    });
  }, { source: fixture.source, layout: fixture.layout });
  await page.addScriptTag({ content: core });
  const result = await page.evaluate(() => window.__paginationResult);
  await page.close();
  if (result.error || result.validation?.is_valid !== true) {
    const failures = (result.validation?.diagnostics || []).filter((item) => item.severity === "failure");
    throw new Error(`ReaderPaginationCore failed: ${result.error || JSON.stringify(failures, null, 2)}`);
  }
  if (result.pages.length !== 1) {
    throw new Error(`Representative fixture must produce exactly one canonical page; got ${result.pages.length}.`);
  }
  return result.pages[0].page_html;
}

async function createIOSPage(browser, fixture, pageHTML, readerCSS) {
  const page = await browser.newPage({ viewport: { width: 816, height: 1056 }, deviceScaleFactor: 1 });
  await page.setContent(
    `<!doctype html><html><head><meta charset="utf-8"><style>${fixture.book_style_css}\n`
      + `${readerCSS}\n${harnessCSS}</style></head><body>${pageHTML}</body></html>`,
    { waitUntil: "domcontentloaded" }
  );
  await waitForStablePage(page);
  return page;
}

async function collect(page) {
  return page.evaluate(({ entries, properties }) => {
    const pageRoot = document.querySelector(".parity-page,.reader-generated-page");
    const pageRect = pageRoot.getBoundingClientRect();
    const round = (value) => Math.round(value * 1000) / 1000;
    return Object.fromEntries(entries.map(([name, selector]) => {
      const element = pageRoot.querySelector(selector);
      if (!element) throw new Error(`Missing required parity selector: ${name} (${selector})`);
      const rect = element.getBoundingClientRect();
      const computed = getComputedStyle(element);
      return [name, {
        selector,
        rect: {
          x: round(rect.left - pageRect.left),
          y: round(rect.top - pageRect.top),
          width: round(rect.width),
          height: round(rect.height)
        },
        style: Object.fromEntries(properties.map((property) => [property, computed[property]]))
      }];
    }));
  }, { entries: measurements, properties: styleProperties });
}

function numericCSS(value) {
  const match = /^(-?\d+(?:\.\d+)?)px$/.exec(String(value));
  return match ? Number(match[1]) : null;
}

function compareMetrics(expected, actual, policy, label) {
  const failures = [];
  for (const [name, expectedMetric] of Object.entries(expected)) {
    const actualMetric = actual[name];
    if (!actualMetric) {
      failures.push(`${label} ${name}: element is missing`);
      continue;
    }
    for (const key of ["x", "y", "width", "height"]) {
      const delta = Math.abs(expectedMetric.rect[key] - actualMetric.rect[key]);
      if (delta > policy.geometry_tolerance_px) {
        failures.push(
          `${label} ${name} geometry.${key}: expected ${expectedMetric.rect[key]}px, `
            + `got ${actualMetric.rect[key]}px (delta ${delta.toFixed(3)}px, `
            + `tolerance ${policy.geometry_tolerance_px}px)`
        );
      }
    }
    for (const property of styleProperties) {
      const expectedValue = expectedMetric.style[property];
      const actualValue = actualMetric.style[property];
      const expectedNumber = numericCSS(expectedValue);
      const actualNumber = numericCSS(actualValue);
      if (expectedNumber !== null && actualNumber !== null) {
        const delta = Math.abs(expectedNumber - actualNumber);
        if (delta > policy.style_numeric_tolerance_px) {
          failures.push(
            `${label} ${name} style.${property}: expected ${expectedValue}, got ${actualValue} `
              + `(delta ${delta.toFixed(3)}px)`
          );
        }
      } else if (expectedValue !== actualValue) {
        failures.push(`${label} ${name} style.${property}: expected ${expectedValue}, got ${actualValue}`);
      }
    }
  }
  return failures;
}

async function pixelComparison(browser, onlinePNG, iosPNG, policy) {
  const page = await browser.newPage({ viewport: { width: 32, height: 32 } });
  try {
    return await page.evaluate(async ({ online, ios, pixelPolicy }) => {
      const load = (source) => new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = `data:image/png;base64,${source}`;
      });
      const [onlineImage, iosImage] = await Promise.all([load(online), load(ios)]);
      if (onlineImage.width !== iosImage.width || onlineImage.height !== iosImage.height) {
        throw new Error(`Screenshot dimensions differ: ${onlineImage.width}x${onlineImage.height} `
          + `versus ${iosImage.width}x${iosImage.height}`);
      }
      const canvas = document.createElement("canvas");
      canvas.width = onlineImage.width;
      canvas.height = onlineImage.height;
      const context = canvas.getContext("2d", { willReadFrequently: true });
      context.drawImage(onlineImage, 0, 0);
      const left = context.getImageData(0, 0, canvas.width, canvas.height).data;
      context.clearRect(0, 0, canvas.width, canvas.height);
      context.drawImage(iosImage, 0, 0);
      const right = context.getImageData(0, 0, canvas.width, canvas.height).data;
      let differentPixels = 0;
      let totalDelta = 0;
      for (let offset = 0; offset < left.length; offset += 4) {
        let pixelDifferent = false;
        for (let channel = 0; channel < 3; channel++) {
          const delta = Math.abs(left[offset + channel] - right[offset + channel]);
          totalDelta += delta;
          if (delta > pixelPolicy.per_channel_antialias_tolerance) pixelDifferent = true;
        }
        if (pixelDifferent) differentPixels++;
      }
      const pixels = canvas.width * canvas.height;
      return {
        width: canvas.width,
        height: canvas.height,
        different_pixel_ratio: differentPixels / pixels,
        mean_channel_delta: totalDelta / (pixels * 3),
        different_pixels: differentPixels
      };
    }, {
      online: onlinePNG.toString("base64"),
      ios: iosPNG.toString("base64"),
      pixelPolicy: policy.pixel
    });
  } finally {
    await page.close();
  }
}

async function main() {
  const update = process.argv.includes("--update");
  const browserName = argument("browser", "webkit");
  if (!["webkit", "chromium"].includes(browserName)) {
    throw new Error("--browser must be webkit or chromium.");
  }
  if (browserName === "chromium") {
    console.warn("EXPLICIT FALLBACK: running Chromium, not the required WebKit regression target.");
  }
  const fixtureBytes = fs.readFileSync(fixturePath);
  const fixture = JSON.parse(fixtureBytes);
  const policy = JSON.parse(fs.readFileSync(policyPath, "utf8"));
  const missingCategories = policy.required_categories.filter(
    (category) => !fixture.categories.includes(category)
  );
  if (missingCategories.length) {
    throw new Error(`Fixture lacks required categories: ${missingCategories.join(", ")}`);
  }
  const playwright = loadPlaywright();
  let browser;
  try {
    browser = await playwright[browserName].launch({ headless: true });
  } catch (error) {
    if (browserName === "webkit") {
      console.error("WebKit browser runtime is unavailable.");
      console.error("Install it with: cd scripts/garmin && npx playwright install webkit");
    }
    throw error;
  }
  try {
    const editorCSS = fs.readFileSync(editorCSSPath, "utf8");
    const core = fs.readFileSync(corePath, "utf8");
    const onlinePage = await createOnlinePage(browser, fixture, editorCSS);
    const generatedPageHTML = await paginate(browser, fixture, core, "");
    const iosPage = await createIOSPage(browser, fixture, generatedPageHTML, "");
    const [onlineMetrics, iosMetrics] = await Promise.all([collect(onlinePage), collect(iosPage)]);
    fs.mkdirSync(artifactsDirectory, { recursive: true });
    const [onlinePNG, iosPNG] = await Promise.all([
      onlinePage.screenshot({ path: path.join(artifactsDirectory, `${browserName}-online.png`) }),
      iosPage.screenshot({ path: path.join(artifactsDirectory, `${browserName}-ios.png`) })
    ]);
    const pixel = await pixelComparison(browser, onlinePNG, iosPNG, policy);
    await Promise.all([onlinePage.close(), iosPage.close()]);

    let failures = compareMetrics(onlineMetrics, iosMetrics, policy, "renderer→iOS");
    if (policy.baseline?.metrics && !update && policy.baseline.browser === browserName) {
      failures = failures.concat(compareMetrics(policy.baseline.metrics, onlineMetrics, policy, "golden→renderer"));
    }
    if (pixel.different_pixel_ratio > policy.pixel.maximum_different_pixel_ratio) {
      failures.push(
        `pixel different ratio ${(pixel.different_pixel_ratio * 100).toFixed(3)}% exceeds `
          + `${(policy.pixel.maximum_different_pixel_ratio * 100).toFixed(3)}%`
      );
    }
    if (pixel.mean_channel_delta > policy.pixel.maximum_mean_channel_delta) {
      failures.push(
        `pixel mean channel delta ${pixel.mean_channel_delta.toFixed(3)} exceeds `
          + `${policy.pixel.maximum_mean_channel_delta}`
      );
    }
    if (update) {
      policy.baseline = {
        browser: browserName,
        fixture_sha256: sha256(fixtureBytes),
        metrics: stable(onlineMetrics)
      };
      fs.writeFileSync(policyPath, `${JSON.stringify(policy, null, 2)}\n`);
      console.log(`Updated ${path.relative(root, policyPath)} from ${browserName} renderer metrics.`);
    }
    if (failures.length) {
      console.error("Publication DOM geometry/visual parity: FAIL");
      failures.slice(0, 80).forEach((failure) => console.error(` - ${failure}`));
      if (failures.length > 80) console.error(` - … ${failures.length - 80} additional failures`);
      process.exitCode = 1;
      return;
    }
    console.log(JSON.stringify({
      ok: true,
      browser: browserName,
      fixture_sha256: sha256(fixtureBytes),
      compared_elements: measurements.length,
      pixel,
      screenshots: [
        path.relative(root, path.join(artifactsDirectory, `${browserName}-online.png`)),
        path.relative(root, path.join(artifactsDirectory, `${browserName}-ios.png`))
      ]
    }, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error?.stack || String(error));
  process.exit(1);
});
