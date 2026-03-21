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
const dryRun = parseBoolean(process.env.DRY_RUN, false);
const entryId = requirePositiveInteger(process.env.ENTRY_ID, 'ENTRY_ID');
const siteId = requirePositiveInteger(process.env.SITE_ID, 'SITE_ID');
const requestReason = normalizeOptionalString(process.env.REQUEST_REASON) ?? 'Prepare scheduled content refresh draft for editorial review';
const metadataSource = normalizeOptionalString(process.env.METADATA_SOURCE) ?? 'cron-worker';
const metadataAgentId = normalizeOptionalString(process.env.METADATA_AGENT_ID) ?? 'governed-content-refresh';
const metadataTraceId = normalizeOptionalString(process.env.METADATA_TRACE_ID) ?? `content-refresh-${entryId}-${siteId}-${Date.now()}`;
const actionRef = normalizeOptionalString(process.env.ACTION_REF) ?? `CONTENT-REFRESH-${entryId}-${siteId}`;
const requestIdempotencyKey = normalizeOptionalString(process.env.REQUEST_IDEMPOTENCY_KEY) ?? `content-refresh-${entryId}-${siteId}-request`;
const titlePrefix = normalizeOptionalString(process.env.TITLE_PREFIX) ?? '';
const titleSuffix = normalizeOptionalString(process.env.TITLE_SUFFIX) ?? '';
const slugOverride = normalizeOptionalString(process.env.SLUG_OVERRIDE);
const draftName = normalizeOptionalString(process.env.DRAFT_NAME) ?? 'Scheduled content refresh draft';
const draftNotesPrefix = normalizeOptionalString(process.env.DRAFT_NOTES_PREFIX) ?? 'Prepared by scheduled governed automation for editorial review.';
const summaryFieldHandle = normalizeOptionalString(process.env.SUMMARY_FIELD_HANDLE);
const summaryPrefix = normalizeOptionalString(process.env.SUMMARY_PREFIX) ?? 'Draft summary prepared by scheduled automation:';
const promptContext = normalizeOptionalString(process.env.PROMPT_CONTEXT) ?? '';
const extraFieldValues = resolveFieldValues(process.env.FIELDS_JSON ?? '');

if (!baseUrl) {
  fail('Missing BASE_URL or SITE_URL. Set BASE_URL to the full /agents/v1 base or SITE_URL to the site root.');
}

if (!token) {
  fail('Missing AGENTS_TOKEN. Create a managed account and place its one-time token into .env or the process environment.');
}

console.log('Governed content refresh worker');
console.log(`Base URL: ${baseUrl}`);
console.log(`Entry: ${entryId}`);
console.log(`Site: ${siteId}`);
console.log(`Action ref: ${actionRef}`);
console.log(`Dry run: ${dryRun ? 'yes' : 'no'}`);
console.log('');

const entryResponse = await requestJson(`/entries/show?id=${encodeURIComponent(String(entryId))}`, {
  method: 'GET',
  timeout: timeoutMs,
  authToken: token,
  rootUrl: baseUrl,
});

const entry = entryResponse?.data ?? null;
if (!entry || typeof entry !== 'object') {
  fail(`Entry ${entryId} could not be loaded from /entries/show.`);
}

const proposal = buildDraftProposal(entry);
const approvalRequest = {
  actionType: 'entry.updateDraft',
  actionRef,
  reason: requestReason,
  idempotencyKey: requestIdempotencyKey,
  metadata: {
    source: metadataSource,
    agentId: metadataAgentId,
    traceId: metadataTraceId,
  },
  payload: proposal,
};

console.log('=== Proposed Draft Payload ===');
console.log(JSON.stringify(approvalRequest, null, 2));

if (dryRun) {
  console.log('');
  console.log('Dry run only. No approval request was submitted.');
  process.exit(0);
}

const approvalResponse = await requestJson('/control/approvals/request', {
  method: 'POST',
  timeout: timeoutMs,
  authToken: token,
  rootUrl: baseUrl,
  body: approvalRequest,
});

printApprovalResult(approvalResponse, printJson);

function buildDraftProposal(entryData) {
  const sourceTitle = normalizeOptionalString(entryData.title) ?? `Entry ${entryId}`;
  const sourceSection = normalizeOptionalString(entryData.section) ?? 'unknown';
  const sourceUri = normalizeOptionalString(entryData.uri) ?? '';
  const sourceUrl = normalizeOptionalString(entryData.url) ?? '';
  const sourceUpdatedAt = normalizeOptionalString(entryData.updatedAt) ?? 'unknown';

  const fields = { ...extraFieldValues };
  if (summaryFieldHandle) {
    fields[summaryFieldHandle] = buildSummaryValue({
      title: sourceTitle,
      section: sourceSection,
      uri: sourceUri,
      url: sourceUrl,
      updatedAt: sourceUpdatedAt,
      promptContext,
    });
  }

  const payload = {
    entryId,
    siteId,
    draftName,
    draftNotes: [
      draftNotesPrefix,
      `Source entry: ${sourceTitle}.`,
      `Section: ${sourceSection}.`,
      sourceUrl ? `URL: ${sourceUrl}.` : sourceUri ? `URI: ${sourceUri}.` : null,
      `Source updated: ${sourceUpdatedAt}.`,
      promptContext ? `Context: ${promptContext}.` : null,
    ].filter(Boolean).join(' '),
  };

  const proposedTitle = `${titlePrefix}${sourceTitle}${titleSuffix}`.trim();
  if (proposedTitle && proposedTitle !== sourceTitle) {
    payload.title = proposedTitle;
  }

  if (slugOverride) {
    payload.slug = slugOverride;
  }

  if (Object.keys(fields).length > 0) {
    payload.fields = fields;
  }

  return payload;
}

function buildSummaryValue({ title, section, uri, url, updatedAt, promptContext: context }) {
  const summaryParts = [
    `${summaryPrefix} ${title} is being refreshed for editorial review.`,
    `Section: ${section}.`,
    url ? `Live URL: ${url}.` : uri ? `URI: ${uri}.` : null,
    `Last updated: ${updatedAt}.`,
    context ? `Operator context: ${context}.` : null,
    'Review tone, claims, and freshness before publishing.',
  ];

  return summaryParts.filter(Boolean).join(' ');
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

function printApprovalResult(response, showJson) {
  const approval = response?.data ?? {};
  console.log('');
  console.log('=== Approval Request ===');
  console.log(`Request: #${String(approval.id ?? 'unknown')}`);
  console.log(`Status: ${String(approval.status ?? 'unknown')}`);
  console.log(`Required approvals: ${String(approval.requiredApprovals ?? 'n/a')}`);
  console.log(`Action type: ${String(approval.actionType ?? 'unknown')}`);
  console.log(`Action ref: ${String(approval.actionRef ?? 'n/a')}`);
  console.log('');
  console.log('Next steps:');
  console.log(`1. Open Agents -> Approvals and review request #${String(approval.id ?? '...')}.`);
  console.log('2. Final approval in the CP will execute the stored draft payload if the approver has execute permission.');
  console.log('3. Review the saved draft on the target entry before publishing.');

  if (showJson) {
    console.log('');
    console.log(JSON.stringify(response, null, 2));
  }
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
