#!/usr/bin/env node
import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const source = fs.readFileSync(new URL('./FlyGarminBrowserSession.js', import.meta.url), 'utf8');
assert(source.includes("credentials: 'include'"), 'page-context fetch must include credentials.');
assert(source.includes('page.evaluate'), 'Garmin requests must use page.evaluate.');
assert(source.includes('ensureFlyGarminLoaded'), 'Garmin origin must be restored before requests.');
assert(!source.includes(['context', 'request', 'fetch'].join('.')), 'Garmin session manager must not use APIRequestContext.');
for (const code of [
  'GARMIN_AUTHENTICATION_REQUIRED',
  'GARMIN_LOGIN_HTML_RECEIVED',
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

console.log(JSON.stringify({ ok: true, flygarmin_browser_session: 'passed' }));
