#!/usr/bin/env node
import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const source = fs.readFileSync(new URL('./FlyGarminBrowserSession.js', import.meta.url), 'utf8');
const workerSource = fs.readFileSync(new URL('./flygarmin-worker.js', import.meta.url), 'utf8');
const helperSource = fs.readFileSync(new URL('./garmin-auth-session.sh', import.meta.url), 'utf8');
assert(source.includes("credentials: 'include'"), 'page-context fetch must include credentials.');
assert(source.includes('page.evaluate'), 'Garmin requests must use page.evaluate.');
assert(source.includes('ensureFlyGarminLoaded'), 'Garmin origin must be restored before requests.');
assert(!source.includes(['context', 'request', 'fetch'].join('.')), 'Garmin session manager must not use APIRequestContext.');
for (const code of [
  'GARMIN_AUTHENTICATION_REQUIRED',
  'GARMIN_LOGIN_HTML_RECEIVED',
  'GARMIN_HUMAN_VERIFICATION_REQUIRED',
  'GARMIN_MFA_REQUIRED',
  'GARMIN_RATE_LIMITED',
  'GARMIN_AUTH_INTERACTION_ACTIVE',
  'GARMIN_AUTH_BACKOFF_ACTIVE',
  'GARMIN_BROWSER_CHANNEL_UNAVAILABLE',
  'GARMIN_WEB_ENDPOINT_ERROR',
  'GARMIN_UNEXPECTED_CONTENT_TYPE',
  'GARMIN_UNEXPECTED_JSON',
  'GARMIN_BROWSER_FETCH_FAILED',
  'GARMIN_BROWSER_FETCH_TIMEOUT',
  'GARMIN_PROFILE_LOCKED',
  'GARMIN_BROWSER_RECOVERY_FAILED',
  'GARMIN_DOWNLOAD_TOO_LARGE',
  'GARMIN_DOWNLOAD_FAILED',
]) {
  assert(source.includes(code), `Expected classifier code missing: ${code}`);
}
assert(!source.includes('cookies()'), 'Diagnostics must not read cookies.');
assert(!source.includes('localStorage'), 'Diagnostics must not read localStorage.');
assert(!source.includes('sessionStorage'), 'Diagnostics must not read sessionStorage.');
assert(!source.includes('addInitScript'), 'Do not add fingerprint-masking init scripts.');
assert(!source.includes('AutomationControlled'), 'Do not disable Blink automation signals.');
assert(!source.includes('puppeteer-extra'), 'Do not add stealth plugins.');
assert(!source.includes('stealth'), 'Do not add stealth plugin behavior.');
assert(!source.includes('userAgent:'), 'Do not spoof user-agent.');
assert(source.includes("launchOptions.channel = 'chrome'"), 'channel=chrome must set Playwright channel chrome.');
assert(source.includes("this.browserChannel === 'chrome'"), 'Chrome channel selection must be explicit.');
assert(source.includes("['chrome', 'chromium']"), 'Browser channel validation must reject invalid values.');
assert(source.includes('chromeStableAvailable'), 'Chrome availability must be checked.');
assert(source.includes('GARMIN_BROWSER_CHANNEL_UNAVAILABLE'), 'Missing Chrome must have a clear error code.');
assert(!source.includes('startup-probe'), 'Startup must not automatically call the Garmin logbook API.');
assert(source.includes('authInteractionActive'), 'Auth interaction state must pause automated requests.');
assert(source.includes("operationName: 'verify-auth'"), 'Complete Authentication must use explicit verify-auth.');
assert(source.includes('applyBackoff'), 'Backoff must activate after auth/web failures.');
assert(source.includes('manualBackoffOverride'), 'Manual Test Connection must support bounded backoff override.');
assert(workerSource.includes("case 'browser-status'"), 'Worker must expose browser-status.');
assert(workerSource.includes("case 'browser-recover'"), 'Worker must expose browser-recover.');
assert(workerSource.includes("case 'auth-interaction-start'"), 'Worker must expose auth-interaction-start.');
assert(workerSource.includes("case 'auth-interaction-stop'"), 'Worker must expose auth-interaction-stop.');
assert(helperSource.includes('worker_browser_status'), 'Auth helper must query worker browser-status.');
assert(helperSource.includes('auth-interaction-start'), 'Auth helper must pause worker Garmin requests before VNC.');
assert(helperSource.includes('auth-interaction-stop'), 'Auth helper must unpause worker Garmin requests after verification/cancel.');
assert(!helperSource.includes(['browser', 'pid'].join('.')), 'Auth helper must not inspect browser.pid.');
assert(!helperSource.includes(['browser-ready', 'json'].join('.')), 'Auth helper must not inspect browser-ready.json.');
assert(!helperSource.includes(['browser-error', 'json'].join('.')), 'Auth helper must not inspect browser-error.json.');
assert(!helperSource.includes('for _ in $(seq 1 20); do\n    worker_response="$(worker_request "verify-auth"'), 'Complete Authentication must not loop verify-auth.');

console.log(JSON.stringify({ ok: true, flygarmin_browser_session: 'passed' }));
