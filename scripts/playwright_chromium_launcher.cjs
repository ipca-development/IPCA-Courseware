"use strict";

const fs = require("fs");
const path = require("path");

function discoverChromiumExecutables(root, depth = 0) {
  if (!root || depth > 4 || !fs.existsSync(root)) return [];
  const matches = [];
  let entries = [];
  try {
    entries = fs.readdirSync(root, { withFileTypes: true });
  } catch (_) {
    return matches;
  }
  for (const entry of entries) {
    const candidate = path.join(root, entry.name);
    if (entry.isDirectory()) {
      matches.push(...discoverChromiumExecutables(candidate, depth + 1));
    } else if (
      ["chrome-headless-shell", "chrome", "chromium"].includes(entry.name)
      && candidate.toLowerCase().includes("chrom")
    ) {
      matches.push(candidate);
    }
  }
  return matches;
}

async function launchChromium(chromium) {
  const configured = String(process.env.CW_PAGINATION_CHROMIUM_EXECUTABLE || "").trim();
  const configuredRoot = configured.includes("/chromium_")
    ? configured.slice(0, configured.indexOf("/chromium_"))
    : "";
  const discovered = [
    process.env.PLAYWRIGHT_BROWSERS_PATH,
    configuredRoot,
    "/var/lib/ipca/garmin/playwright-browsers",
  ].flatMap(root => discoverChromiumExecutables(String(root || "").trim()));
  const candidates = Array.from(new Set([
    configured,
    ...discovered,
    "/usr/bin/chromium",
    "/usr/bin/chromium-browser",
    "/usr/bin/google-chrome",
    "/usr/bin/google-chrome-stable",
  ].filter(Boolean)));
  const failures = [];

  for (const executablePath of candidates) {
    try {
      fs.accessSync(executablePath, fs.constants.X_OK);
    } catch (_) {
      failures.push(`${executablePath}: missing or not executable`);
      continue;
    }
    try {
      return await chromium.launch({ headless: true, executablePath });
    } catch (error) {
      failures.push(`${executablePath}: ${error.message || error}`);
    }
  }

  try {
    return await chromium.launch({ headless: true });
  } catch (error) {
    failures.push(`bundled Chromium: ${error.message || error}`);
  }

  try {
    return await chromium.launch({ headless: true, channel: "chrome" });
  } catch (error) {
    failures.push(`Chrome channel: ${error.message || error}`);
  }

  throw new Error(`No usable Chromium executable was found.\n${failures.join("\n")}`);
}

module.exports = { launchChromium };
