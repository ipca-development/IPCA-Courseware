#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const BASE_URL = process.env.GARMIN_BASE_URL || 'https://fly.garmin.com';
const LOGBOOK_API = `${BASE_URL}/fly-garmin/api/logbook/`;
const PROFILE_DIR = process.env.GARMIN_BROWSER_PROFILE_DIR || '/var/lib/ipca/garmin/browser-profile';
const DOWNLOAD_DIR = process.env.GARMIN_PRIVATE_DOWNLOAD_DIR || '/var/lib/ipca/garmin/downloads';
const RUNTIME_DIR = process.env.GARMIN_AUTH_RUNTIME_DIR || '/run/ipca/garmin-auth';
const COMMAND_FILE = path.join(RUNTIME_DIR, 'command.json');
const RESULT_FILE = path.join(RUNTIME_DIR, 'verify-result.json');

let context = null;
let closed = false;

function writeJson(file, payload) {
  fs.writeFileSync(file, `${JSON.stringify(payload, null, 2)}\n`, { mode: 0o600 });
}

async function verifyContext() {
  const response = await context.request.fetch(LOGBOOK_API, {
    method: 'GET',
    headers: { accept: 'application/json,text/plain,*/*' },
  });
  const contentType = response.headers()['content-type'] || '';
  const bodyText = await response.text();
  if (response.status() !== 200) {
    return {
      ok: false,
      status: 'failed',
      error_code: 'GARMIN_AUTH_HTTP_STATUS',
      error_summary: `Garmin logbook returned HTTP ${response.status()}.`,
      response_status: response.status(),
      response_content_type: contentType,
    };
  }
  if (!contentType.includes('json')) {
    return {
      ok: false,
      status: 'failed',
      error_code: 'GARMIN_AUTH_NON_JSON',
      error_summary: 'Garmin logbook returned non-JSON content.',
      response_status: response.status(),
      response_content_type: contentType,
    };
  }
  let json;
  try {
    json = JSON.parse(bodyText);
  } catch {
    return {
      ok: false,
      status: 'failed',
      error_code: 'GARMIN_AUTH_JSON_PARSE',
      error_summary: 'Garmin logbook JSON could not be parsed.',
      response_status: response.status(),
      response_content_type: contentType,
    };
  }
  const missing = ['version', 'entries', 'settings'].filter((key) => !Object.prototype.hasOwnProperty.call(json, key));
  if (missing.length > 0) {
    return {
      ok: false,
      status: 'failed',
      error_code: 'GARMIN_AUTH_UNEXPECTED_JSON',
      error_summary: `Garmin logbook JSON is missing expected field(s): ${missing.join(', ')}.`,
      response_status: response.status(),
      response_content_type: contentType,
      top_level_keys: Object.keys(json).slice(0, 20),
    };
  }
  return {
    ok: true,
    status: 'authenticated',
    response_status: response.status(),
    response_content_type: contentType,
    top_level_keys: Object.keys(json).slice(0, 20),
  };
}

async function closeContext() {
  if (closed) {
    return;
  }
  closed = true;
  if (context !== null) {
    await context.close();
  }
}

async function main() {
  fs.mkdirSync(PROFILE_DIR, { recursive: true });
  fs.mkdirSync(DOWNLOAD_DIR, { recursive: true });
  fs.mkdirSync(RUNTIME_DIR, { recursive: true });
  context = await chromium.launchPersistentContext(PROFILE_DIR, {
    headless: false,
    acceptDownloads: true,
    downloadsPath: DOWNLOAD_DIR,
  });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/fly-garmin/`, { waitUntil: 'domcontentloaded' });
  writeJson(path.join(RUNTIME_DIR, 'browser-ready.json'), {
    ok: true,
    status: 'awaiting_admin_login',
    ready_at: new Date().toISOString(),
  });

  process.on('SIGTERM', () => {
    closeContext().finally(() => process.exit(0));
  });
  process.on('SIGINT', () => {
    closeContext().finally(() => process.exit(0));
  });

  let lastCommandId = '';
  while (!closed) {
    if (fs.existsSync(COMMAND_FILE)) {
      try {
        const command = JSON.parse(fs.readFileSync(COMMAND_FILE, 'utf8'));
        const commandId = String(command.command_id || '');
        const action = String(command.action || '');
        if (commandId !== '' && commandId !== lastCommandId) {
          lastCommandId = commandId;
          if (action === 'verify') {
            const result = await verifyContext();
            writeJson(RESULT_FILE, {
              ...result,
              command_id: commandId,
              verified_at: new Date().toISOString(),
            });
            if (result.ok) {
              await closeContext();
              process.exit(0);
            }
          } else if (action === 'stop') {
            await closeContext();
            process.exit(0);
          }
        }
      } catch (error) {
        writeJson(RESULT_FILE, {
          ok: false,
          status: 'failed',
          error_code: 'GARMIN_AUTH_COMMAND_ERROR',
          error_summary: error instanceof Error ? error.message : 'Could not process Garmin auth command.',
          verified_at: new Date().toISOString(),
        });
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
}

main().catch((error) => {
  writeJson(path.join(RUNTIME_DIR, 'browser-error.json'), {
    ok: false,
    status: 'failed',
    error_code: 'GARMIN_AUTH_BROWSER_ERROR',
    error_summary: error instanceof Error ? error.message : 'Garmin auth browser failed.',
    failed_at: new Date().toISOString(),
  });
  process.exit(1);
});
