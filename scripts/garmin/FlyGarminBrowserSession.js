import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const DEFAULT_MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

export class FlyGarminBrowserSession {
  constructor(options = {}) {
    this.provider = 'flygarmin_web';
    this.baseUrl = options.baseUrl || process.env.GARMIN_BASE_URL || 'https://fly.garmin.com';
    this.logbookApi = `${this.baseUrl}/fly-garmin/api/logbook/`;
    this.profileDir = options.profileDir || process.env.GARMIN_BROWSER_PROFILE_DIR || path.resolve(process.cwd(), 'storage/garmin-browser-profile');
    this.downloadDir = options.downloadDir || process.env.GARMIN_PRIVATE_DOWNLOAD_DIR || path.resolve(process.cwd(), 'storage/garmin-downloads');
    this.maxDownloadBytes = Number.parseInt(process.env.GARMIN_DOWNLOAD_MAX_BYTES || String(DEFAULT_MAX_DOWNLOAD_BYTES), 10);
    this.display = process.env.DISPLAY || '';

    this.context = null;
    this.page = null;
    this.startupPromise = null;
    this.shutdownRequested = false;
    this.queueTail = Promise.resolve();
    this.activeOperation = null;
    this.dedupe = new Map();
    this.operationCounter = 0;

    this.browserState = 'UNINITIALIZED';
    this.authenticationState = 'unknown';
    this.lastSuccessfulRequestAt = null;
    this.lastError = null;
    this.consecutiveFailureCount = 0;
    this.nextHeartbeatAt = null;
    this.lastHeartbeatAt = null;
  }

  statusSnapshot() {
    return {
      ok: true,
      provider: this.provider,
      status: this.authenticationState === 'authenticated' ? 'authenticated' : 'authentication_required',
      browser_state: this.browserState,
      authentication_state: this.authenticationState,
      browser_profile_present: this.browserProfilePresent(),
      browser_context_present: this.context !== null,
      page_present: this.page !== null && !this.page.isClosed(),
      display: this.display,
      last_successful_request_at: this.lastSuccessfulRequestAt,
      last_heartbeat_at: this.lastHeartbeatAt,
      consecutive_failure_count: this.consecutiveFailureCount,
      active_operation: this.activeOperation,
      queued_operations: Math.max(0, this.dedupe.size - (this.activeOperation ? 1 : 0)),
      last_error: this.lastError,
      reauthentication_required: this.authenticationState !== 'authenticated',
    };
  }

  async start() {
    if (this.startupPromise) {
      return this.startupPromise;
    }
    this.startupPromise = this.ensureContext()
      .then(async () => {
        await this.ensureFlyGarminLoaded();
        await this.probeAuthentication({ operationName: 'startup-probe', timeoutMs: 15000 });
        return this.statusSnapshot();
      })
      .finally(() => {
        this.startupPromise = null;
      });
    return this.startupPromise;
  }

  async ensureContext() {
    if (this.context !== null) {
      return this.context;
    }
    if (this.shutdownRequested) {
      throw this.error('GARMIN_WORKER_NOT_READY', 'Garmin worker is shutting down.');
    }
    if (!this.display) {
      throw this.error('GARMIN_WORKER_NOT_READY', 'DISPLAY is not set for headed Garmin worker Chromium.');
    }
    fs.mkdirSync(this.profileDir, { recursive: true });
    fs.mkdirSync(this.downloadDir, { recursive: true });
    try {
      this.browserState = 'STARTING';
      this.context = await chromium.launchPersistentContext(this.profileDir, {
        headless: false,
        acceptDownloads: true,
        downloadsPath: this.downloadDir,
        viewport: null,
      });
      this.context.on('close', () => {
        this.context = null;
        this.page = null;
        if (!this.shutdownRequested) {
          this.browserState = 'RECOVERING';
        }
      });
      this.browserState = 'READY';
      return this.context;
    } catch (error) {
      this.context = null;
      this.page = null;
      const message = error instanceof Error ? error.message : String(error);
      if (message.toLowerCase().includes('lock') || message.toLowerCase().includes('singleton')) {
        this.browserState = 'FAILED';
        throw this.error('GARMIN_PROFILE_LOCKED', 'Garmin browser profile is locked by another Chromium process.', { detail: this.safeText(message) });
      }
      this.browserState = 'FAILED';
      throw this.error('GARMIN_BROWSER_RECOVERY_FAILED', 'Could not launch Garmin persistent browser context.', { detail: this.safeText(message) });
    }
  }

  async ensurePage() {
    const context = await this.ensureContext();
    if (this.page !== null && !this.page.isClosed()) {
      return this.page;
    }
    const pages = context.pages().filter((candidate) => !candidate.isClosed());
    this.page = pages.find((candidate) => candidate.url().startsWith(`${this.baseUrl}/fly-garmin/`)) || pages[0] || await context.newPage();
    this.page.on('close', () => {
      if (this.page?.isClosed()) {
        this.page = null;
      }
    });
    return this.page;
  }

  async ensureFlyGarminLoaded() {
    const page = await this.ensurePage();
    if (!page.url().startsWith(`${this.baseUrl}/fly-garmin/`)) {
      await page.goto(`${this.baseUrl}/fly-garmin/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    }
    return page;
  }

  async enqueue(operationName, dedupeKey, fn) {
    if (this.dedupe.has(dedupeKey)) {
      return this.dedupe.get(dedupeKey);
    }
    const operationId = `${Date.now()}-${++this.operationCounter}-${operationName}`;
    const run = async () => {
      this.activeOperation = { operation_id: operationId, operation: operationName, started_at: new Date().toISOString() };
      try {
        return await fn(operationId);
      } finally {
        this.activeOperation = null;
        this.dedupe.delete(dedupeKey);
      }
    };
    const promise = this.queueTail.then(run, run);
    this.queueTail = promise.catch(() => {});
    this.dedupe.set(dedupeKey, promise);
    return promise;
  }

  async testConnection() {
    return this.enqueue('test-connection', 'test-connection', async () => this.probeAuthentication({ operationName: 'test-connection' }));
  }

  async verifyAuth() {
    return this.enqueue('verify-auth', 'verify-auth', async () => this.probeAuthentication({ operationName: 'verify-auth' }));
  }

  async probeAuthentication({ operationName = 'status', timeoutMs = 20000 } = {}) {
    const result = await this.fetchJson(this.logbookApi, { timeoutMs, expectedKeys: ['version', 'entries', 'settings'] });
    if (!result.ok) {
      this.authenticationState = this.authStateFromCode(result.error?.code);
      this.lastError = result.error;
      this.consecutiveFailureCount += 1;
      return {
        ok: false,
        operation: operationName,
        provider: this.provider,
        status: this.authenticationState,
        data: {
          browser_profile_present: this.browserProfilePresent(),
          authentication_status: this.authenticationState,
          reauthentication_required: this.authenticationState !== 'authenticated',
          response_status: result.response_status || null,
          response_content_type: result.response_content_type || null,
          final_url: result.final_url || null,
        },
        error: result.error,
      };
    }
    this.authenticationState = 'authenticated';
    this.browserState = 'AUTHENTICATED';
    this.lastSuccessfulRequestAt = new Date().toISOString();
    this.lastError = null;
    this.consecutiveFailureCount = 0;
    return {
      ok: true,
      operation: operationName,
      provider: this.provider,
      status: 'authenticated',
      data: {
        browser_profile_present: this.browserProfilePresent(),
        authentication_status: 'authenticated',
        reauthentication_required: false,
        response_status: result.response_status,
        response_content_type: result.response_content_type,
        top_level_keys: result.top_level_keys,
      },
    };
  }

  async sync(operation, cursor = null) {
    return this.enqueue(operation, `${operation}:${cursor || ''}`, async () => {
      this.browserState = 'SYNCING';
      const url = cursor ? `${this.logbookApi}?version=${encodeURIComponent(cursor)}` : this.logbookApi;
      const result = await this.fetchJson(url, { expectedKeys: ['version', 'entries', 'settings'] });
      if (!result.ok) {
        this.browserState = 'READY';
        return this.workerFailure(operation, result);
      }
      this.browserState = 'AUTHENTICATED';
      this.authenticationState = 'authenticated';
      const entries = this.normalizeEntries(result.json);
      return this.workerOk(operation, 'succeeded', {
        entries,
        cursor: this.findCursor(result.json),
        raw_count: entries.length,
      });
    });
  }

  async downloadSource(flightDataLogUUID) {
    if (!flightDataLogUUID || typeof flightDataLogUUID !== 'string') {
      return this.workerFailure('download-source', {
        error: { code: 'GARMIN_SOURCE_UUID_MISSING', message: 'flightDataLogUUID is required.' },
      }, 'failed');
    }
    return this.enqueue('download-source', `download-source:${flightDataLogUUID}`, async () => {
      this.browserState = 'DOWNLOADING';
      const candidates = [
        `${this.baseUrl}/fly-garmin/api/logbook/flight-data/${encodeURIComponent(flightDataLogUUID)}`,
        `${this.baseUrl}/fly-garmin/api/logbook/flight-data/${encodeURIComponent(flightDataLogUUID)}/download`,
        `${this.baseUrl}/fly-garmin/api/flight-data/${encodeURIComponent(flightDataLogUUID)}`,
      ];
      const errors = [];
      for (const url of candidates) {
        const result = await this.fetchBinary(url, { maxBytes: this.maxDownloadBytes, timeoutMs: 60000 });
        if (!result.ok) {
          errors.push({ url: this.safeUrl(url), error: result.error, response_status: result.response_status || null });
          continue;
        }
        const filename = this.safeFilename(result.filename || `${flightDataLogUUID}.csv`);
        const localPath = path.join(this.downloadDir, `${flightDataLogUUID}-${filename}`);
        const tmpPath = `${localPath}.${process.pid}.${Date.now()}.tmp`;
        fs.writeFileSync(tmpPath, result.bytes);
        fs.renameSync(tmpPath, localPath);
        const sha256 = crypto.createHash('sha256').update(result.bytes).digest('hex');
        this.browserState = 'AUTHENTICATED';
        this.authenticationState = 'authenticated';
        return this.workerOk('download-source', 'downloaded', {
          flightDataLogUUID,
          filename,
          contentType: result.response_content_type || 'application/octet-stream',
          contentDisposition: result.content_disposition || '',
          localPath,
          fileSizeBytes: result.bytes.length,
          sha256,
          finalUrl: result.final_url || null,
        });
      }
      this.browserState = 'READY';
      return this.workerFailure('download-source', {
        error: { code: 'GARMIN_DOWNLOAD_FAILED', message: 'No candidate Garmin download endpoint succeeded.' },
        data: { attempts: errors },
      }, 'failed');
    });
  }

  async fetchJson(url, options = {}) {
    const response = await this.browserFetch(url, {
      method: options.method || 'GET',
      headers: { Accept: 'application/json, text/plain, */*', ...(options.headers || {}) },
      body: options.body || null,
      timeoutMs: options.timeoutMs || 30000,
    });
    const classified = this.classifyResponse(response, 'json');
    if (!classified.ok) {
      return classified;
    }
    let json;
    try {
      json = JSON.parse(response.text || '');
    } catch {
      return this.failureFromResponse('GARMIN_UNEXPECTED_JSON', 'Garmin response JSON could not be parsed.', response);
    }
    const missing = (options.expectedKeys || []).filter((key) => !Object.prototype.hasOwnProperty.call(json, key));
    if (missing.length > 0) {
      return this.failureFromResponse('GARMIN_UNEXPECTED_JSON', `Garmin JSON is missing expected field(s): ${missing.join(', ')}.`, response, {
        top_level_keys: Object.keys(json).slice(0, 20),
      });
    }
    return {
      ok: true,
      json,
      response_status: response.status,
      response_content_type: response.contentType,
      final_url: this.safeUrl(response.finalUrl),
      top_level_keys: Object.keys(json).slice(0, 20),
    };
  }

  async fetchBinary(url, options = {}) {
    const response = await this.browserFetch(url, {
      method: 'GET',
      headers: { Accept: 'text/csv, application/octet-stream, */*' },
      binary: true,
      maxBytes: options.maxBytes || this.maxDownloadBytes,
      timeoutMs: options.timeoutMs || 60000,
    });
    const classified = this.classifyResponse(response, 'binary');
    if (!classified.ok) {
      return classified;
    }
    if (response.byteLength > (options.maxBytes || this.maxDownloadBytes)) {
      return this.failureFromResponse('GARMIN_DOWNLOAD_TOO_LARGE', 'Garmin download exceeds configured maximum size.', response);
    }
    return {
      ok: true,
      bytes: Buffer.from(response.base64 || '', 'base64'),
      response_status: response.status,
      response_content_type: response.contentType,
      final_url: this.safeUrl(response.finalUrl),
      content_disposition: response.contentDisposition || '',
      filename: this.filenameFromDisposition(response.contentDisposition || ''),
    };
  }

  async browserFetch(url, options = {}) {
    await this.ensureFlyGarminLoaded();
    const page = await this.ensurePage();
    try {
      return await page.evaluate(async (payload) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), payload.timeoutMs);
        try {
          const response = await fetch(payload.url, {
            method: payload.method,
            headers: payload.headers,
            body: payload.body,
            credentials: 'include',
            cache: 'no-store',
            redirect: 'follow',
            signal: controller.signal,
          });
          const contentType = response.headers.get('content-type') || '';
          const contentDisposition = response.headers.get('content-disposition') || '';
          if (payload.binary) {
            const buffer = await response.arrayBuffer();
            const bytes = new Uint8Array(buffer);
            if (bytes.byteLength > payload.maxBytes) {
              return {
                transportOk: true,
                ok: response.ok,
                status: response.status,
                contentType,
                contentDisposition,
                finalUrl: response.url || '',
                byteLength: bytes.byteLength,
                tooLarge: true,
              };
            }
            let binary = '';
            const chunkSize = 0x8000;
            for (let i = 0; i < bytes.length; i += chunkSize) {
              binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
            }
            return {
              transportOk: true,
              ok: response.ok,
              status: response.status,
              contentType,
              contentDisposition,
              finalUrl: response.url || '',
              byteLength: bytes.byteLength,
              base64: btoa(binary),
            };
          }
          return {
            transportOk: true,
            ok: response.ok,
            status: response.status,
            contentType,
            contentDisposition,
            finalUrl: response.url || '',
            text: await response.text(),
          };
        } catch (error) {
          return {
            transportOk: false,
            errorName: error?.name || 'Error',
            errorMessage: String(error?.message || 'browser_fetch_failed'),
          };
        } finally {
          clearTimeout(timeout);
        }
      }, {
        url,
        method: options.method || 'GET',
        headers: options.headers || {},
        body: options.body || null,
        binary: Boolean(options.binary),
        maxBytes: options.maxBytes || this.maxDownloadBytes,
        timeoutMs: options.timeoutMs || 30000,
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      this.page = null;
      return { transportOk: false, errorName: 'BrowserFailure', errorMessage: this.safeText(message) };
    }
  }

  classifyResponse(response, expectedType) {
    if (!response.transportOk) {
      const code = response.errorName === 'AbortError' ? 'GARMIN_BROWSER_FETCH_TIMEOUT' : 'GARMIN_BROWSER_FETCH_FAILED';
      return {
        ok: false,
        error: { code, message: this.safeText(response.errorMessage || 'Garmin browser fetch failed.') },
      };
    }
    const finalUrl = this.safeUrl(response.finalUrl || '');
    const contentType = this.safeText(response.contentType || '');
    const bodyShape = this.bodyShape(response.text || '');
    if (response.tooLarge) {
      return this.failureFromResponse('GARMIN_DOWNLOAD_TOO_LARGE', 'Garmin download exceeds configured maximum size.', response);
    }
    if (response.status === 401 || response.status === 403) {
      return this.failureFromResponse('GARMIN_AUTHENTICATION_REQUIRED', 'Garmin returned an authentication status.', response, { body_shape: bodyShape });
    }
    if (response.status === 429) {
      return this.failureFromResponse('GARMIN_WEB_ENDPOINT_ERROR', 'Garmin rate limited the request.', response, { body_shape: bodyShape });
    }
    if (response.status >= 500) {
      return this.failureFromResponse('GARMIN_WEB_ENDPOINT_ERROR', 'Garmin web endpoint returned a server error.', response, { body_shape: bodyShape });
    }
    if (this.looksLikeLogin(response)) {
      return this.failureFromResponse('GARMIN_LOGIN_HTML_RECEIVED', 'Garmin returned a login or MFA page.', response, { body_shape: bodyShape });
    }
    if (!response.ok) {
      return this.failureFromResponse('GARMIN_WEB_ENDPOINT_ERROR', 'Garmin web endpoint returned an unexpected status.', response, { body_shape: bodyShape });
    }
    if (expectedType === 'json' && !contentType.toLowerCase().includes('json')) {
      return this.failureFromResponse('GARMIN_UNEXPECTED_CONTENT_TYPE', 'Garmin returned non-JSON content for a JSON endpoint.', response, { body_shape: bodyShape });
    }
    return { ok: true };
  }

  looksLikeLogin(response) {
    const finalUrl = String(response.finalUrl || '').toLowerCase();
    const contentType = String(response.contentType || '').toLowerCase();
    const sample = String(response.text || '').slice(0, 500).toLowerCase();
    return finalUrl.includes('login') || finalUrl.includes('sso') || finalUrl.includes('signin') || (
      contentType.includes('html') && (sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa'))
    );
  }

  failureFromResponse(code, message, response, extra = {}) {
    return {
      ok: false,
      response_status: response.status || null,
      response_content_type: this.safeText(response.contentType || ''),
      final_url: this.safeUrl(response.finalUrl || ''),
      error: { code, message },
      ...extra,
    };
  }

  workerOk(operation, status, data = {}) {
    this.lastSuccessfulRequestAt = new Date().toISOString();
    this.lastError = null;
    this.consecutiveFailureCount = 0;
    return { ok: true, operation, provider: this.provider, status, data };
  }

  workerFailure(operation, result, status = 'authentication_required') {
    const error = result.error || { code: 'GARMIN_WEB_ENDPOINT_ERROR', message: 'Garmin operation failed.' };
    this.lastError = error;
    this.consecutiveFailureCount += 1;
    this.authenticationState = this.authStateFromCode(error.code);
    return {
      ok: false,
      operation,
      provider: this.provider,
      status: status === 'failed' ? 'failed' : this.authenticationState,
      data: result.data || {
        response_status: result.response_status || null,
        response_content_type: result.response_content_type || null,
        final_url: result.final_url || null,
      },
      error,
    };
  }

  async recover() {
    this.page = null;
    await this.ensureFlyGarminLoaded();
    return this.probeAuthentication({ operationName: 'browser-recover' });
  }

  async shutdown() {
    this.shutdownRequested = true;
    this.browserState = 'STOPPING';
    if (this.context) {
      await this.context.close();
    }
    this.context = null;
    this.page = null;
    this.browserState = 'STOPPING';
  }

  authStateFromCode(code) {
    if (code === 'GARMIN_AUTHENTICATION_REQUIRED' || code === 'GARMIN_LOGIN_HTML_RECEIVED') {
      return 'authentication_required';
    }
    return this.authenticationState === 'authenticated' ? 'authenticated' : 'unknown';
  }

  normalizeEntries(payload) {
    const candidates = [
      payload?.entries,
      payload?.logbookEntries,
      payload?.flights,
      payload?.items,
      Array.isArray(payload) ? payload : null,
    ].filter(Array.isArray);
    const source = candidates[0] || [];
    return source.map((entry) => {
      const flightDataLogUUIDs = this.collectFlightDataLogUUIDs(entry);
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

  collectFlightDataLogUuidsFromList(list) {
    const values = [];
    for (const item of list) {
      if (typeof item === 'string') values.push(item);
      if (item && typeof item === 'object') {
        for (const nestedKey of ['uuid', 'id', 'flightDataLogUUID', 'flightDataLogUuid']) {
          if (typeof item[nestedKey] === 'string') values.push(item[nestedKey]);
        }
      }
    }
    return values;
  }

  collectFlightDataLogUUIDs(entry) {
    const values = [];
    for (const key of ['flightDataLogUUID', 'flightDataLogUuid', 'flightDataLogId']) {
      if (typeof entry?.[key] === 'string') values.push(entry[key]);
    }
    for (const key of ['flightDataLogUUIDs', 'flightDataLogUuids', 'flightDataLogs', 'dataLogs', 'trackLogs']) {
      const list = entry?.[key];
      if (Array.isArray(list)) values.push(...this.collectFlightDataLogUuidsFromList(list));
    }
    return [...new Set(values.map((value) => value.toLowerCase()).filter((value) => /^[a-f0-9-]{36}$/.test(value)))];
  }

  findCursor(payload) {
    return payload?.version || payload?.cursor || payload?.nextCursor || payload?.metadata?.version || null;
  }

  browserProfilePresent() {
    return fs.existsSync(this.profileDir) && fs.readdirSync(this.profileDir).length > 0;
  }

  bodyShape(text) {
    const sample = String(text || '').slice(0, 300).toLowerCase();
    return {
      length: String(text || '').length,
      has_html: sample.includes('<html') || sample.includes('<!doctype'),
      has_login_terms: sample.includes('password') || sample.includes('sign in') || sample.includes('mfa') || sample.includes('multi-factor'),
    };
  }

  safeUrl(value) {
    try {
      const url = new URL(value);
      return `${url.origin}${url.pathname}`;
    } catch {
      return '';
    }
  }

  safeText(value) {
    return String(value || '').replace(/\s+/g, ' ').slice(0, 300);
  }

  safeFilename(value) {
    return String(value || 'garmin.csv').split('/').pop().replace(/[^A-Za-z0-9._-]+/g, '-') || 'garmin.csv';
  }

  filenameFromDisposition(value) {
    const match = String(value || '').match(/filename\\*?=(?:UTF-8'')?\"?([^\";]+)\"?/i);
    return match ? decodeURIComponent(match[1]) : '';
  }

  error(code, message, data = {}) {
    const error = new Error(message);
    error.code = code;
    error.data = data;
    return error;
  }
}
