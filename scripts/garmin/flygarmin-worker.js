#!/usr/bin/env node
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const PROVIDER = 'flygarmin_web';
const BASE_URL = process.env.GARMIN_BASE_URL || 'https://fly.garmin.com';
const LOGBOOK_API = `${BASE_URL}/fly-garmin/api/logbook/`;
const PROFILE_DIR = process.env.GARMIN_BROWSER_PROFILE_DIR || path.resolve(process.cwd(), 'storage/garmin-browser-profile');
const DOWNLOAD_DIR = process.env.GARMIN_PRIVATE_DOWNLOAD_DIR || path.resolve(process.cwd(), 'storage/garmin-downloads');
const PORT = Number.parseInt(process.env.GARMIN_WORKER_PORT || '8791', 10);
const HOST = process.env.GARMIN_WORKER_HOST || '127.0.0.1';
const TOKEN = process.env.GARMIN_WORKER_TOKEN || '';

function ok(operation, status, data = {}, warnings = []) {
  return { ok: true, operation, provider: PROVIDER, status, data, warnings };
}

function fail(operation, status, code, message, data = {}) {
  return { ok: false, operation, provider: PROVIDER, status, data, error: { code, message } };
}

async function withContext(headless, fn) {
  fs.mkdirSync(PROFILE_DIR, { recursive: true });
  fs.mkdirSync(DOWNLOAD_DIR, { recursive: true });
  const context = await chromium.launchPersistentContext(PROFILE_DIR, {
    headless,
    acceptDownloads: true,
    downloadsPath: DOWNLOAD_DIR,
  });
  try {
    return await fn(context);
  } finally {
    await context.close();
  }
}

async function responseJsonFromContext(context, url, options = {}) {
  const response = await context.request.fetch(url, {
    method: options.method || 'GET',
    headers: {
      accept: 'application/json,text/plain,*/*',
      ...(options.headers || {}),
    },
    data: options.data,
  });
  const contentType = response.headers()['content-type'] || '';
  const text = await response.text();
  if (!response.ok()) {
    return {
      ok: false,
      statusCode: response.status(),
      contentType,
      text: sanitizeText(text),
    };
  }
  if (!contentType.includes('json')) {
    return {
      ok: false,
      statusCode: response.status(),
      contentType,
      text: sanitizeText(text),
    };
  }
  return {
    ok: true,
    statusCode: response.status(),
    contentType,
    json: JSON.parse(text),
  };
}

async function login() {
  fs.mkdirSync(PROFILE_DIR, { recursive: true });
  const context = await chromium.launchPersistentContext(PROFILE_DIR, {
    headless: false,
    acceptDownloads: true,
    downloadsPath: DOWNLOAD_DIR,
  });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/fly-garmin/`, { waitUntil: 'domcontentloaded' });
  console.error('Complete Garmin username/password, MFA, and trusted-device prompts in the browser.');
  console.error('Press Enter here after Garmin FlyGarmin is authenticated.');
  await waitForEnter();
  const test = await responseJsonFromContext(context, LOGBOOK_API);
  await context.close();
  if (!test.ok) {
    return fail('login', 'authentication_required', 'GARMIN_LOGIN_NOT_VERIFIED', `Logbook API did not return JSON (${test.statusCode || 'no status'} ${test.contentType || ''}).`, {
      browser_profile_present: browserProfilePresent(),
    });
  }
  return ok('login', 'authenticated', {
    browser_profile_present: browserProfilePresent(),
    authentication_status: 'authenticated',
  });
}

async function status() {
  if (!browserProfilePresent()) {
    return ok('status', 'authentication_required', {
      browser_profile_present: false,
      authentication_status: 'authentication_required',
      reauthentication_required: true,
    });
  }
  return withContext(true, async (context) => {
    const test = await responseJsonFromContext(context, LOGBOOK_API);
    if (!test.ok) {
      return ok('status', 'authentication_required', {
        browser_profile_present: true,
        authentication_status: 'authentication_required',
        reauthentication_required: true,
        response_status: test.statusCode || null,
        response_content_type: test.contentType || null,
      });
    }
    return ok('status', 'authenticated', {
      browser_profile_present: true,
      authentication_status: 'authenticated',
      reauthentication_required: false,
    });
  });
}

async function testConnection() {
  return withContext(true, async (context) => {
    const test = await responseJsonFromContext(context, LOGBOOK_API);
    if (!test.ok) {
      return fail('test-connection', 'authentication_required', 'GARMIN_AUTHENTICATION_REQUIRED', 'Garmin logbook API did not return authenticated JSON.', {
        browser_profile_present: browserProfilePresent(),
        authentication_status: 'authentication_required',
        response_status: test.statusCode || null,
        response_content_type: test.contentType || null,
      });
    }
    return ok('test-connection', 'authenticated', {
      browser_profile_present: browserProfilePresent(),
      authentication_status: 'authenticated',
      sample_shape: summarizeShape(test.json),
    });
  });
}

async function verifyAuth() {
  return withContext(true, async (context) => {
    const test = await responseJsonFromContext(context, LOGBOOK_API);
    if (!test.ok) {
      return fail('verify-auth', 'authentication_required', 'GARMIN_AUTHENTICATION_REQUIRED', 'Garmin logbook API did not return authenticated JSON.', {
        browser_profile_present: browserProfilePresent(),
        authentication_status: 'authentication_required',
        response_status: test.statusCode || null,
        response_content_type: test.contentType || null,
      });
    }
    const missing = ['version', 'entries', 'settings'].filter((key) => !Object.prototype.hasOwnProperty.call(test.json, key));
    if (missing.length > 0) {
      return fail('verify-auth', 'authentication_required', 'GARMIN_UNEXPECTED_LOGBOOK_JSON', `Garmin logbook JSON is missing expected field(s): ${missing.join(', ')}.`, {
        browser_profile_present: browserProfilePresent(),
        authentication_status: 'authentication_required',
        response_status: test.statusCode,
        response_content_type: test.contentType,
        top_level_keys: Object.keys(test.json).slice(0, 20),
      });
    }
    return ok('verify-auth', 'authenticated', {
      browser_profile_present: browserProfilePresent(),
      authentication_status: 'authenticated',
      response_status: test.statusCode,
      response_content_type: test.contentType,
      top_level_keys: Object.keys(test.json).slice(0, 20),
    });
  });
}

async function sync(operation, cursor = null) {
  return withContext(true, async (context) => {
    const url = cursor ? `${LOGBOOK_API}?version=${encodeURIComponent(cursor)}` : LOGBOOK_API;
    const result = await responseJsonFromContext(context, url);
    if (!result.ok) {
      return fail(operation, 'authentication_required', 'GARMIN_LOGBOOK_SYNC_FAILED', 'Garmin logbook API did not return authenticated JSON.', {
        response_status: result.statusCode || null,
        response_content_type: result.contentType || null,
      });
    }
    const entries = normalizeEntries(result.json);
    const nextCursor = findCursor(result.json);
    return ok(operation, 'succeeded', {
      entries,
      cursor: nextCursor,
      raw_count: entries.length,
    });
  });
}

async function downloadSource(flightDataLogUUID) {
  if (!flightDataLogUUID || typeof flightDataLogUUID !== 'string') {
    return fail('download-source', 'failed', 'GARMIN_SOURCE_UUID_MISSING', 'flightDataLogUUID is required.');
  }
  return withContext(true, async (context) => {
    const candidates = [
      `${BASE_URL}/fly-garmin/api/logbook/flight-data/${encodeURIComponent(flightDataLogUUID)}`,
      `${BASE_URL}/fly-garmin/api/logbook/flight-data/${encodeURIComponent(flightDataLogUUID)}/download`,
      `${BASE_URL}/fly-garmin/api/flight-data/${encodeURIComponent(flightDataLogUUID)}`,
    ];
    const errors = [];
    for (const url of candidates) {
      const response = await context.request.fetch(url, { method: 'GET', headers: { accept: 'text/csv,application/octet-stream,*/*' } });
      const contentType = response.headers()['content-type'] || 'application/octet-stream';
      if (!response.ok()) {
        errors.push(`${response.status()} ${url}`);
        continue;
      }
      const body = await response.body();
      if (body.length === 0) {
        errors.push(`empty ${url}`);
        continue;
      }
      const filename = `${flightDataLogUUID}.csv`;
      const localPath = path.join(DOWNLOAD_DIR, filename);
      fs.writeFileSync(localPath, body);
      return ok('download-source', 'downloaded', {
        flightDataLogUUID,
        filename,
        contentType,
        localPath,
        fileSizeBytes: body.length,
      });
    }
    return fail('download-source', 'failed', 'GARMIN_SOURCE_DOWNLOAD_FAILED', `No candidate Garmin download endpoint succeeded: ${errors.join('; ')}`);
  });
}

async function disconnect() {
  fs.rmSync(PROFILE_DIR, { recursive: true, force: true });
  return ok('disconnect', 'disconnected', { browser_profile_present: false });
}

function normalizeEntries(payload) {
  const candidates = [
    payload?.entries,
    payload?.logbookEntries,
    payload?.flights,
    payload?.items,
    Array.isArray(payload) ? payload : null,
  ].filter(Array.isArray);
  const source = candidates[0] || [];
  return source.map((entry) => {
    const flightDataLogUUIDs = collectFlightDataLogUUIDs(entry);
    return {
      uuid: entry.uuid || entry.id || entry.logbookEntryUUID || entry.logbookEntryUuid || null,
      version: entry.version || entry.versionId || entry.modifiedVersion || null,
      entryDate: entry.entryDate || entry.date || entry.startDate || null,
      aircraftRegistration: entry.aircraftRegistration || entry.aircraftTailNumber || entry.aircraftIdent || entry.tailNumber || null,
      aircraftTypeUUID: entry.aircraftTypeUUID || entry.aircraftTypeUuid || null,
      generatedTrackStart: entry.generatedTrackStart || entry.trackStart || entry.startTime || entry.departureTime || null,
      generatedTrackStop: entry.generatedTrackStop || entry.trackStop || entry.endTime || entry.arrivalTime || null,
      generatingDeviceName: entry.generatingDeviceName || entry.deviceName || null,
      canonicalTrackUUID: entry.canonicalTrackUUID || entry.canonicalTrackUuid || entry.canonicalTrackId || null,
      provisional: Boolean(entry.provisional),
      lockedAt: entry.lockedAt || null,
      deletedAt: entry.deletedAt || null,
      flightDataLogUUIDs,
      raw: entry,
    };
  }).filter((entry) => entry.uuid && entry.flightDataLogUUIDs.length > 0);
}

function collectFlightDataLogUUIDs(entry) {
  const values = [];
  const directKeys = ['flightDataLogUUID', 'flightDataLogUuid', 'flightDataLogId'];
  for (const key of directKeys) {
    if (typeof entry?.[key] === 'string') values.push(entry[key]);
  }
  const arrayKeys = ['flightDataLogUUIDs', 'flightDataLogUuids', 'flightDataLogs', 'dataLogs', 'trackLogs'];
  for (const key of arrayKeys) {
    const list = entry?.[key];
    if (!Array.isArray(list)) continue;
    for (const item of list) {
      if (typeof item === 'string') values.push(item);
      if (item && typeof item === 'object') {
        for (const nestedKey of ['uuid', 'id', 'flightDataLogUUID', 'flightDataLogUuid']) {
          if (typeof item[nestedKey] === 'string') values.push(item[nestedKey]);
        }
      }
    }
  }
  return [...new Set(values.map((value) => value.toLowerCase()).filter((value) => /^[a-f0-9-]{36}$/.test(value)))];
}

function findCursor(payload) {
  return payload?.version || payload?.cursor || payload?.nextCursor || payload?.metadata?.version || null;
}

function browserProfilePresent() {
  return fs.existsSync(PROFILE_DIR) && fs.readdirSync(PROFILE_DIR).length > 0;
}

function summarizeShape(value) {
  if (Array.isArray(value)) return { type: 'array', count: value.length };
  if (value && typeof value === 'object') return { type: 'object', keys: Object.keys(value).slice(0, 20) };
  return { type: typeof value };
}

function sanitizeText(text) {
  return String(text || '').replace(/\s+/g, ' ').slice(0, 200);
}

function waitForEnter() {
  return new Promise((resolve) => {
    process.stdin.resume();
    process.stdin.once('data', () => resolve());
  });
}

async function dispatch(payload) {
  const operation = payload.operation || payload._operation || process.argv[2] || 'status';
  switch (operation) {
    case 'login':
      return login();
    case 'status':
      return status();
    case 'test-connection':
      return testConnection();
    case 'verify-auth':
      return verifyAuth();
    case 'sync-initial':
      return sync('sync-initial');
    case 'sync-incremental':
      return sync('sync-incremental', payload.cursor || null);
    case 'sync-reconcile':
      return sync('sync-reconcile');
    case 'download-source':
      return downloadSource(payload.flightDataLogUUID || payload.flightDataLogUuid || payload.uuid);
    case 'disconnect':
      return disconnect();
    default:
      return fail(operation, 'failed', 'GARMIN_UNKNOWN_OPERATION', `Unknown Garmin worker operation: ${operation}`);
  }
}

async function stdinPayload() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  const body = Buffer.concat(chunks).toString('utf8').trim();
  return body ? JSON.parse(body) : {};
}

async function serve() {
  if (HOST !== '127.0.0.1') {
    throw new Error('GARMIN_WORKER_HOST must be 127.0.0.1; public bind addresses are not allowed.');
  }
  const server = http.createServer(async (req, res) => {
    if (req.method !== 'POST' || req.url !== '/garmin-worker') {
      res.writeHead(404, { 'content-type': 'application/json' });
      res.end(JSON.stringify(fail('http', 'failed', 'GARMIN_WORKER_NOT_FOUND', 'Not found.')));
      return;
    }
    if (TOKEN && req.headers.authorization !== `Bearer ${TOKEN}`) {
      res.writeHead(401, { 'content-type': 'application/json' });
      res.end(JSON.stringify(fail('http', 'failed', 'GARMIN_WORKER_UNAUTHORIZED', 'Unauthorized.')));
      return;
    }
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    try {
      const payload = JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}');
      const result = await dispatch(payload);
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify(result));
    } catch (error) {
      res.writeHead(500, { 'content-type': 'application/json' });
      res.end(JSON.stringify(fail('http', 'sync_error', 'GARMIN_WORKER_EXCEPTION', error.message)));
    }
  });
  server.listen(PORT, HOST, () => {
    console.error(`FlyGarmin worker listening on ${HOST}:${PORT}`);
  });
}

if (process.argv[2] === 'serve') {
  await serve();
} else if (process.argv[2] === 'login') {
  console.log(JSON.stringify(await login()));
} else {
  const payload = await stdinPayload();
  console.log(JSON.stringify(await dispatch(payload)));
}
