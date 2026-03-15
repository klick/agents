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
const mode = normalizeMode(process.env.MODE ?? 'request');
const entryId = requirePositiveInteger(process.env.ENTRY_ID, 'ENTRY_ID');
const siteId = requirePositiveInteger(process.env.SITE_ID, 'SITE_ID');
const draftId = parseOptionalPositiveInteger(process.env.DRAFT_ID);
const approvalId = parseOptionalPositiveInteger(process.env.APPROVAL_ID);
const title = normalizeOptionalString(process.env.TITLE);
const slug = normalizeOptionalString(process.env.SLUG);
const draftName = normalizeOptionalString(process.env.DRAFT_NAME) ?? 'Agent update draft';
const draftNotes = normalizeOptionalString(process.env.DRAFT_NOTES) ?? 'Prepared by governed automation for editorial review.';
const requestReason = normalizeOptionalString(process.env.REQUEST_REASON) ?? 'Prepare entry draft for editorial review';
const metadataSource = normalizeOptionalString(process.env.METADATA_SOURCE) ?? 'worker-example';
const metadataAgentId = normalizeOptionalString(process.env.METADATA_AGENT_ID) ?? 'entry-draft-governed';
const metadataTraceId = normalizeOptionalString(process.env.METADATA_TRACE_ID) ?? `entry-draft-${entryId}-${siteId}-${Date.now()}`;
const actionRef = normalizeOptionalString(process.env.ACTION_REF) ?? `ENTRY-DRAFT-${entryId}-${siteId}`;
const requestIdempotencyKey = normalizeOptionalString(process.env.REQUEST_IDEMPOTENCY_KEY) ?? `entry-draft-request-${entryId}-${siteId}`;
const executeIdempotencyKey = normalizeOptionalString(process.env.EXECUTE_IDEMPOTENCY_KEY)
  ?? (approvalId ? `approval-${approvalId}-exec-v1` : `entry-draft-exec-${entryId}-${siteId}-v1`);
const fieldValues = resolveFieldValues(process.env.FIELDS_JSON ?? '');

if (!baseUrl) {
  fail('Missing BASE_URL or SITE_URL. Set BASE_URL to the full /agents/v1 base or SITE_URL to the site root.');
}

if (!token) {
  fail('Missing AGENTS_TOKEN. Create a managed account and place its one-time token into .env or the process environment.');
}

const payload = buildPayload({ entryId, siteId, draftId, draftName, draftNotes, title, slug, fieldValues });

console.log('Governed entry draft worker');
console.log(`Mode: ${mode}`);
console.log(`Base URL: ${baseUrl}`);
console.log(`Entry: ${entryId}`);
console.log(`Site: ${siteId}`);
console.log(`Action ref: ${actionRef}`);
console.log('');

if (mode === 'request') {
  const response = await requestJson('/control/approvals/request', {
    method: 'POST',
    timeout: timeoutMs,
    authToken: token,
    rootUrl: baseUrl,
    body: {
      actionType: 'entry.updateDraft',
      actionRef,
      reason: requestReason,
      idempotencyKey: requestIdempotencyKey,
      metadata: {
        source: metadataSource,
        agentId: metadataAgentId,
        traceId: metadataTraceId,
      },
      payload,
    },
  });

  printApprovalResult(response, printJson, executeIdempotencyKey);
  process.exit(0);
}

if (!approvalId) {
  fail('APPROVAL_ID is required when MODE=execute-approved.');
}

const response = await requestJson('/control/actions/execute', {
  method: 'POST',
  timeout: timeoutMs,
  authToken: token,
  rootUrl: baseUrl,
  headers: {
    'X-Idempotency-Key': executeIdempotencyKey,
  },
  body: {
    actionType: 'entry.updateDraft',
    actionRef,
    approvalId,
    idempotencyKey: executeIdempotencyKey,
    payload,
  },
});

printExecutionResult(response, printJson);

const executionStatus = normalizeOptionalString(response?.data?.status)?.toLowerCase() ?? 'unknown';
if (executionStatus !== 'succeeded') {
  fail(`Execution finished as ${executionStatus}.`);
}

function buildPayload({ entryId: canonicalEntryId, siteId: targetSiteId, draftId: targetDraftId, draftName: draftLabel, draftNotes: draftSummary, title: nextTitle, slug: nextSlug, fieldValues: nextFieldValues }) {
  const resolvedPayload = {
    entryId: canonicalEntryId,
    siteId: targetSiteId,
  };

  if (targetDraftId) {
    resolvedPayload.draftId = targetDraftId;
  }

  if (draftLabel) {
    resolvedPayload.draftName = draftLabel;
  }

  if (draftSummary) {
    resolvedPayload.draftNotes = draftSummary;
  }

  if (nextTitle !== null) {
    resolvedPayload.title = nextTitle;
  }

  if (nextSlug !== null) {
    resolvedPayload.slug = nextSlug;
  }

  if (Object.keys(nextFieldValues).length > 0) {
    resolvedPayload.fields = nextFieldValues;
  }

  return resolvedPayload;
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

function normalizeMode(value) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === 'request' || normalized === 'execute-approved') {
    return normalized;
  }

  fail('MODE must be `request` or `execute-approved`.');
}

function normalizeOptionalString(value) {
  const normalized = String(value ?? '').trim();
  return normalized === '' ? null : normalized;
}

function parsePositiveInteger(value, fallback) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function requirePositiveInteger(value, envName) {
  const parsed = parsePositiveInteger(value, 0);
  if (parsed <= 0) {
    fail(`${envName} must be a positive integer.`);
  }

  return parsed;
}

function parseOptionalPositiveInteger(value) {
  const normalized = String(value ?? '').trim();
  if (!normalized) {
    return null;
  }

  const parsed = Number.parseInt(normalized, 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    fail(`Expected a positive integer, received: ${normalized}`);
  }

  return parsed;
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

function resolveFieldValues(rawValue) {
  const normalized = String(rawValue ?? '').trim();
  if (!normalized) {
    return {};
  }

  const parsed = parseJson(normalized);
  if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') {
    fail('FIELDS_JSON must be a JSON object, for example {"summary":"Draft prepared by the worker."}.');
  }

  return parsed;
}

async function requestJson(relativePath, { method, timeout, authToken, rootUrl, body = null, headers = {} }) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);

  try {
    const response = await fetch(`${rootUrl}${relativePath}`, {
      method,
      headers: {
        Authorization: `Bearer ${authToken}`,
        Accept: 'application/json',
        ...(body ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
      body: body ? JSON.stringify(body) : undefined,
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

function printApprovalResult(response, showJson, nextExecuteIdempotencyKey) {
  const approval = response?.data ?? {};
  console.log('=== Approval Request ===');
  console.log(`Request: #${String(approval.id ?? 'unknown')}`);
  console.log(`Status: ${String(approval.status ?? 'unknown')}`);
  console.log(`Required approvals: ${String(approval.requiredApprovals ?? 'n/a')}`);
  console.log(`Action type: ${String(approval.actionType ?? 'unknown')}`);
  console.log(`Action ref: ${String(approval.actionRef ?? 'n/a')}`);
  console.log('');
  console.log('Next steps:');
  console.log(`1. Approve request #${String(approval.id ?? '...')} in Agents -> Approvals.`);
  console.log(`2. Re-run with MODE=execute-approved APPROVAL_ID=${String(approval.id ?? '...')}.`);
  console.log(`3. Keep EXECUTE_IDEMPOTENCY_KEY=${nextExecuteIdempotencyKey} if you want deterministic reruns.`);

  if (showJson) {
    console.log('');
    console.log(JSON.stringify(response, null, 2));
  }
}

function printExecutionResult(response, showJson) {
  const execution = response?.data ?? {};
  const resultPayload = execution.resultPayload ?? {};
  console.log('=== Draft Execution ===');
  console.log(`Execution: #${String(execution.id ?? 'unknown')}`);
  console.log(`Status: ${String(execution.status ?? 'unknown')}`);
  console.log(`Approval: ${String(execution.approvalId ?? 'n/a')}`);
  console.log(`Draft entry: ${String(resultPayload.draftId ?? 'n/a')}`);
  console.log(`Saved draft record: ${String(resultPayload.savedDraftRecordId ?? 'n/a')}`);
  console.log(`Created draft: ${String(resultPayload.createdDraft ?? 'n/a')}`);

  if (resultPayload.message) {
    console.log(`Message: ${String(resultPayload.message)}`);
  }

  if (showJson) {
    console.log('');
    console.log(JSON.stringify(response, null, 2));
  }
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
