#!/usr/bin/env node
import http from 'node:http';
import fs from 'node:fs';
import { FlyGarminBrowserSession } from './FlyGarminBrowserSession.js';

const PROVIDER = 'flygarmin_web';
const PORT = Number.parseInt(process.env.GARMIN_WORKER_PORT || '8791', 10);
const HOST = process.env.GARMIN_WORKER_HOST || '127.0.0.1';
const TOKEN = process.env.GARMIN_WORKER_TOKEN || '';
const session = new FlyGarminBrowserSession();

function ok(operation, status, data = {}, warnings = []) {
  return { ok: true, operation, provider: PROVIDER, status, data, warnings };
}

function fail(operation, status, code, message, data = {}) {
  return { ok: false, operation, provider: PROVIDER, status, data, error: { code, message } };
}

async function login() {
  return fail('login', 'failed', 'GARMIN_LOGIN_VIA_WORKER_DISABLED', 'Use the Garmin Authentication Session UI to expose the existing worker browser through VNC.');
}

async function status() {
  return ok('status', session.statusSnapshot().authentication_state === 'authenticated' ? 'authenticated' : 'authentication_required', session.statusSnapshot());
}

async function browserStatus() {
  return session.browserStatus();
}

async function browserRecover() {
  return session.recover();
}

async function testConnection() {
  return session.testConnection();
}

async function verifyAuth() {
  return session.verifyAuth();
}

async function sync(operation, cursor = null) {
  return session.sync(operation, cursor);
}

async function downloadSource(flightDataLogUUID) {
  return session.downloadSource(flightDataLogUUID);
}

async function disconnect() {
  return fail('disconnect', 'failed', 'GARMIN_DISCONNECT_DISABLED', 'The persistent worker does not delete browser profiles.');
}

async function dispatch(payload) {
  const operation = payload.operation || payload._operation || process.argv[2] || 'status';
  switch (operation) {
    case 'login':
      return login();
    case 'status':
      return status();
    case 'browser-status':
      return browserStatus();
    case 'browser-recover':
      return browserRecover();
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
  session.start().catch((error) => {
    console.error(`FlyGarmin browser session startup warning: ${error?.code || 'GARMIN_WORKER_NOT_READY'}`);
  });
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

process.on('SIGTERM', () => {
  session.shutdown().finally(() => process.exit(0));
});
process.on('SIGINT', () => {
  session.shutdown().finally(() => process.exit(0));
});

if (process.argv[2] === 'serve') {
  await serve();
} else if (process.argv[2] === 'login') {
  console.log(JSON.stringify(await login()));
} else {
  const payload = await stdinPayload();
  console.log(JSON.stringify(await dispatch(payload)));
}
