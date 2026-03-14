import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

loadDotEnv(path.join(__dirname, '.env'));

const siteUrl = normalizeBaseUrl(process.env.SITE_URL ?? '');
const baseUrl = normalizeBaseUrl(process.env.BASE_URL ?? '') || (siteUrl ? `${siteUrl}/agents/v1` : '');
const token = String(process.env.AGENTS_TOKEN ?? '').trim();
const timeoutMs = parsePositiveInteger(process.env.REQUEST_TIMEOUT_MS, 15000);
const printJson = parseBoolean(process.env.PRINT_JSON, true);

if (!baseUrl) {
  fail('Missing BASE_URL or SITE_URL. Set BASE_URL to the full /agents/v1 base or SITE_URL to the site root.');
}

if (!token) {
  fail('Missing AGENTS_TOKEN. Create a managed account and place its one-time token into .env or the process environment.');
}

const steps = [
  { label: 'Health', path: '/health' },
  { label: 'Auth / Whoami', path: '/auth/whoami' },
  { label: 'Readiness', path: '/readiness' },
];

console.log(`Agents worker bootstrap`);
console.log(`Base URL: ${baseUrl}`);
console.log(`Steps: ${steps.map((step) => step.path).join(', ')}`);
console.log('');

for (const step of steps) {
  const payload = await requestJson(step.path, timeoutMs, token, baseUrl);
  printStep(step.label, payload, printJson);
}

function loadDotEnv(filename) {
  if (!fs.existsSync(filename)) {
    return;
  }

  const contents = fs.readFileSync(filename, 'utf8');
  for (const rawLine of contents.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) {
      continue;
    }

    const separatorIndex = line.indexOf('=');
    if (separatorIndex === -1) {
      continue;
    }

    const key = line.slice(0, separatorIndex).trim();
    let value = line.slice(separatorIndex + 1).trim();
    if (!key || process.env[key] !== undefined) {
      continue;
    }

    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }

    process.env[key] = value;
  }
}

function normalizeBaseUrl(value) {
  return String(value).trim().replace(/\/+$/, '');
}

function parsePositiveInteger(value, fallback) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function parseBoolean(value, fallback) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (!normalized) {
    return fallback;
  }

  if (['1', 'true', 'yes', 'on'].includes(normalized)) {
    return true;
  }

  if (['0', 'false', 'no', 'off'].includes(normalized)) {
    return false;
  }

  return fallback;
}

async function requestJson(relativePath, timeout, authToken, rootUrl) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);

  try {
    const response = await fetch(`${rootUrl}${relativePath}`, {
      method: 'GET',
      headers: {
        Authorization: `Bearer ${authToken}`,
        Accept: 'application/json',
      },
      signal: controller.signal,
    });

    const text = await response.text();
    const json = parseJson(text);

    if (!response.ok) {
      const detail = json ? JSON.stringify(json, null, 2) : text;
      fail(`${relativePath} failed (${response.status} ${response.statusText})\n${detail}`);
    }

    if (!json) {
      fail(`${relativePath} returned non-JSON content.`);
    }

    return json;
  } catch (error) {
    if (error?.name === 'AbortError') {
      fail(`${relativePath} timed out after ${timeout}ms.`);
    }

    fail(`${relativePath} failed: ${error instanceof Error ? error.message : String(error)}`);
  } finally {
    clearTimeout(timeoutId);
  }
}

function parseJson(value) {
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

function printStep(label, payload, showJson) {
  console.log(`=== ${label} ===`);

  if (label === 'Health') {
    console.log(`Status: ${String(payload.status ?? 'unknown')}`);
    console.log(`Environment: ${String(payload.environment ?? 'n/a')}`);
  } else if (label === 'Auth / Whoami') {
    const auth = payload.authentication ?? {};
    const authorization = payload.authorization ?? {};
    console.log(`Credential: ${String(auth.credentialId ?? 'unknown')}`);
    console.log(`Auth method: ${String(auth.authMethod ?? 'unknown')}`);
    console.log(`Scopes: ${Array.isArray(authorization.scopes) ? authorization.scopes.join(', ') : 'n/a'}`);
  } else if (label === 'Readiness') {
    console.log(`Status: ${String(payload.status ?? 'unknown')}`);
    console.log(`Readiness score: ${String(payload.readinessScore ?? 'n/a')}`);
    console.log(`Warnings: ${Array.isArray(payload.warnings) ? payload.warnings.length : 0}`);
  }

  if (showJson) {
    console.log(JSON.stringify(payload, null, 2));
  }

  console.log('');
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
