<?php

namespace Klick\Agents\services;

use craft\base\Component;
use Klick\Agents\Plugin;

class StarterPackService extends Component
{
    public function getCatalog(string $basePath = '/agents/v1'): array
    {
        $normalizedBasePath = $this->normalizeBasePath($basePath);
        $templateCatalog = Plugin::getInstance()->getTemplateCatalogService()->getCatalog($normalizedBasePath);
        $templates = (array)($templateCatalog['templates'] ?? []);
        $templatesById = [];
        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }
            $id = strtolower(trim((string)($template['id'] ?? '')));
            if ($id === '') {
                continue;
            }
            $templatesById[$id] = $template;
        }

        $orderedTemplateIds = [
            'catalog-sync-loop',
            'support-context-lookup',
            'governed-return-approval-run',
            'governed-entry-draft-update',
        ];
        $starterPacks = [];
        foreach ($orderedTemplateIds as $templateId) {
            $template = $templatesById[$templateId] ?? null;
            if (!is_array($template)) {
                continue;
            }
            $starterPacks[] = $this->buildStarterPack($templateId, $template, $normalizedBasePath);
        }

        return [
            'service' => 'agents',
            'version' => $this->resolvePluginVersion(),
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'basePath' => $normalizedBasePath,
            'contracts' => [
                'templates' => $normalizedBasePath . '/templates',
                'openapi' => $normalizedBasePath . '/openapi.json',
                'schema' => $normalizedBasePath . '/schema',
            ],
            'count' => count($starterPacks),
            'starterPacks' => $starterPacks,
        ];
    }

    public function getStarterPackById(string $templateId, string $basePath = '/agents/v1'): ?array
    {
        $normalizedId = strtolower(trim($templateId));
        if ($normalizedId === '') {
            return null;
        }

        $catalog = $this->getCatalog($basePath);
        foreach ((array)($catalog['starterPacks'] ?? []) as $starterPack) {
            if (!is_array($starterPack)) {
                continue;
            }
            if (strtolower((string)($starterPack['id'] ?? '')) === $normalizedId) {
                return $starterPack;
            }
        }

        return null;
    }

    private function buildStarterPack(string $templateId, array $template, string $basePath): array
    {
        return [
            'id' => $templateId,
            'displayName' => (string)($template['displayName'] ?? $templateId),
            'intent' => (string)($template['intent'] ?? ''),
            'requiredScopes' => array_values(array_map('strval', (array)($template['requiredScopes'] ?? []))),
            'optionalScopes' => array_values(array_map('strval', (array)($template['optionalScopes'] ?? []))),
            'requiresExperimental' => (bool)($template['requiresExperimental'] ?? false),
            'endpointSequence' => (array)($template['endpointSequence'] ?? []),
            'contracts' => [
                'templateRef' => $basePath . '/templates?id=' . rawurlencode($templateId),
                'schemaRef' => $basePath . '/schema',
                'openapiRef' => $basePath . '/openapi.json',
            ],
            'env' => $this->buildEnvContract($templateId, $basePath),
            'runtimes' => $this->buildRuntimeSnippets($templateId, $basePath),
        ];
    }

    private function buildEnvContract(string $templateId, string $basePath): array
    {
        $env = [
            ['name' => 'SITE_URL', 'required' => false, 'default' => 'https://example.test'],
            ['name' => 'BASE_URL', 'required' => false, 'default' => '$SITE_URL' . $basePath],
            ['name' => 'AGENTS_TOKEN', 'required' => true, 'default' => 'replace-with-token'],
        ];

        if ($templateId === 'support-context-lookup') {
            $env[] = ['name' => 'ORDER_NUMBER', 'required' => false, 'default' => 'A1B2C3D4'];
            $env[] = ['name' => 'SUPPORT_SECTION', 'required' => false, 'default' => 'support'];
        } elseif ($templateId === 'governed-return-approval-run') {
            $env[] = ['name' => 'RETURN_REF', 'required' => false, 'default' => 'RET-100045'];
            $env[] = ['name' => 'ORDER_NUMBER', 'required' => false, 'default' => 'A1B2C3D4'];
        } elseif ($templateId === 'governed-entry-draft-update') {
            $env[] = ['name' => 'ENTRY_ID', 'required' => true, 'default' => '1001'];
            $env[] = ['name' => 'SITE_ID', 'required' => false, 'default' => '1'];
        }

        return $env;
    }

    private function buildRuntimeSnippets(string $templateId, string $basePath): array
    {
        $snippets = match ($templateId) {
            'catalog-sync-loop' => [
                'curl' => $this->catalogSyncCurlSnippet($basePath),
                'javascript' => $this->catalogSyncJavascriptSnippet($basePath),
                'python' => $this->catalogSyncPythonSnippet($basePath),
            ],
            'support-context-lookup' => [
                'curl' => $this->supportLookupCurlSnippet($basePath),
                'javascript' => $this->supportLookupJavascriptSnippet($basePath),
                'python' => $this->supportLookupPythonSnippet($basePath),
            ],
            'governed-return-approval-run' => [
                'curl' => $this->governedReturnCurlSnippet($basePath),
                'javascript' => $this->governedReturnJavascriptSnippet($basePath),
                'python' => $this->governedReturnPythonSnippet($basePath),
            ],
            'governed-entry-draft-update' => [
                'curl' => $this->governedEntryDraftUpdateCurlSnippet($basePath),
                'javascript' => $this->governedEntryDraftUpdateJavascriptSnippet($basePath),
                'python' => $this->governedEntryDraftUpdatePythonSnippet($basePath),
            ],
            default => [],
        };

        return [
            'curl' => [
                'label' => 'curl',
                'filename' => $templateId . '.sh',
                'snippet' => $snippets['curl'] ?? '',
            ],
            'javascript' => [
                'label' => 'JavaScript (Node 18+)',
                'filename' => $templateId . '.mjs',
                'snippet' => $snippets['javascript'] ?? '',
            ],
            'python' => [
                'label' => 'Python (requests)',
                'filename' => $templateId . '.py',
                'snippet' => $snippets['python'] ?? '',
            ],
        ];
    }

    private function catalogSyncCurlSnippet(string $basePath): string
    {
        $snippet = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

: "${SITE_URL:=https://example.test}"
: "${BASE_URL:=$SITE_URL__BASE_PATH__}"
: "${AGENTS_TOKEN:=replace-with-token}"

curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/auth/whoami"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/products?status=live&limit=100"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?status=live&limit=100"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/changes?types=products,entries&limit=100"
curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/sync-state/checkpoint" \
  -d '{"integrationKey":"catalog-sync","resourceType":"changes","cursor":"opaque-cursor-token","updatedSince":"2026-03-05T00:00:00Z","checkpointAt":"2026-03-05T12:00:00Z","metadata":{"worker":"sync-a","version":"1"}}'
BASH;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function catalogSyncJavascriptSnippet(string $basePath): string
    {
        $snippet = <<<'JS'
const SITE_URL = process.env.SITE_URL ?? "https://example.test";
const BASE_URL = process.env.BASE_URL ?? `${SITE_URL}__BASE_PATH__`;
const AGENTS_TOKEN = process.env.AGENTS_TOKEN ?? "replace-with-token";

async function request(path, init = {}) {
  const response = await fetch(`${BASE_URL}${path}`, {
    ...init,
    headers: {
      Authorization: `Bearer ${AGENTS_TOKEN}`,
      "Content-Type": "application/json",
      ...(init.headers ?? {}),
    },
  });

  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}: ${await response.text()}`);
  }

  return response.json();
}

await request("/auth/whoami");
await request("/products?status=live&limit=100");
await request("/entries?status=live&limit=100");
await request("/changes?types=products,entries&limit=100");
await request("/sync-state/checkpoint", {
  method: "POST",
  body: JSON.stringify({
    integrationKey: "catalog-sync",
    resourceType: "changes",
    cursor: "opaque-cursor-token",
    updatedSince: "2026-03-05T00:00:00Z",
    checkpointAt: "2026-03-05T12:00:00Z",
    metadata: {
      worker: "sync-a",
      version: "1",
    },
  }),
});
JS;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function catalogSyncPythonSnippet(string $basePath): string
    {
        $snippet = <<<'PY'
import os
import requests

SITE_URL = os.getenv("SITE_URL", "https://example.test")
BASE_URL = os.getenv("BASE_URL", f"{SITE_URL}__BASE_PATH__")
AGENTS_TOKEN = os.getenv("AGENTS_TOKEN", "replace-with-token")

headers = {
    "Authorization": f"Bearer {AGENTS_TOKEN}",
    "Content-Type": "application/json",
}

def request(method: str, path: str, **kwargs):
    response = requests.request(method, f"{BASE_URL}{path}", headers=headers, timeout=20, **kwargs)
    response.raise_for_status()
    return response.json()

request("GET", "/auth/whoami")
request("GET", "/products?status=live&limit=100")
request("GET", "/entries?status=live&limit=100")
request("GET", "/changes?types=products,entries&limit=100")
request("POST", "/sync-state/checkpoint", json={
    "integrationKey": "catalog-sync",
    "resourceType": "changes",
    "cursor": "opaque-cursor-token",
    "updatedSince": "2026-03-05T00:00:00Z",
    "checkpointAt": "2026-03-05T12:00:00Z",
    "metadata": {
        "worker": "sync-a",
        "version": "1",
    },
})
PY;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function supportLookupCurlSnippet(string $basePath): string
    {
        $snippet = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

: "${SITE_URL:=https://example.test}"
: "${BASE_URL:=$SITE_URL__BASE_PATH__}"
: "${AGENTS_TOKEN:=replace-with-token}"
: "${ORDER_NUMBER:=A1B2C3D4}"
: "${SUPPORT_SECTION:=support}"

curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders?status=all&lastDays=14&limit=20"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders/show?number=$ORDER_NUMBER"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?section=$SUPPORT_SECTION&status=live&limit=20"
BASH;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function supportLookupJavascriptSnippet(string $basePath): string
    {
        $snippet = <<<'JS'
const SITE_URL = process.env.SITE_URL ?? "https://example.test";
const BASE_URL = process.env.BASE_URL ?? `${SITE_URL}__BASE_PATH__`;
const AGENTS_TOKEN = process.env.AGENTS_TOKEN ?? "replace-with-token";
const ORDER_NUMBER = process.env.ORDER_NUMBER ?? "A1B2C3D4";
const SUPPORT_SECTION = process.env.SUPPORT_SECTION ?? "support";

async function request(path, init = {}) {
  const response = await fetch(`${BASE_URL}${path}`, {
    ...init,
    headers: {
      Authorization: `Bearer ${AGENTS_TOKEN}`,
      "Content-Type": "application/json",
      ...(init.headers ?? {}),
    },
  });

  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}: ${await response.text()}`);
  }

  return response.json();
}

await request("/orders?status=all&lastDays=14&limit=20");
await request(`/orders/show?number=${encodeURIComponent(ORDER_NUMBER)}`);
await request(`/entries?section=${encodeURIComponent(SUPPORT_SECTION)}&status=live&limit=20`);
JS;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function supportLookupPythonSnippet(string $basePath): string
    {
        $snippet = <<<'PY'
import os
import requests
from urllib.parse import quote

SITE_URL = os.getenv("SITE_URL", "https://example.test")
BASE_URL = os.getenv("BASE_URL", f"{SITE_URL}__BASE_PATH__")
AGENTS_TOKEN = os.getenv("AGENTS_TOKEN", "replace-with-token")
ORDER_NUMBER = os.getenv("ORDER_NUMBER", "A1B2C3D4")
SUPPORT_SECTION = os.getenv("SUPPORT_SECTION", "support")

headers = {
    "Authorization": f"Bearer {AGENTS_TOKEN}",
    "Content-Type": "application/json",
}

def request(method: str, path: str, **kwargs):
    response = requests.request(method, f"{BASE_URL}{path}", headers=headers, timeout=20, **kwargs)
    response.raise_for_status()
    return response.json()

request("GET", "/orders?status=all&lastDays=14&limit=20")
request("GET", f"/orders/show?number={quote(ORDER_NUMBER)}")
request("GET", f"/entries?section={quote(SUPPORT_SECTION)}&status=live&limit=20")
PY;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function governedReturnCurlSnippet(string $basePath): string
    {
        $snippet = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

: "${SITE_URL:=https://example.test}"
: "${BASE_URL:=$SITE_URL__BASE_PATH__}"
: "${AGENTS_TOKEN:=replace-with-token}"
: "${RETURN_REF:=RET-100045}"
: "${ORDER_NUMBER:=A1B2C3D4}"

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/request" \
  -d "{\"actionType\":\"return.request\",\"actionRef\":\"$RETURN_REF\",\"reason\":\"Customer control requested\",\"metadata\":{\"source\":\"agent-runtime\",\"agentId\":\"returns-orchestrator\",\"traceId\":\"trace-100045\"},\"payload\":{\"orderNumber\":\"$ORDER_NUMBER\",\"amount\":49.9,\"currency\":\"GBP\",\"lineItems\":[{\"sku\":\"SKU-123\",\"qty\":1}]}}"

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/decide" \
  -d '{"approvalId":123,"decision":"approved","decisionReason":"Policy and amount threshold satisfied"}'

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" -H "X-Idempotency-Key: return-ret-100045-v1" "$BASE_URL/control/actions/execute" \
  -d "{\"actionType\":\"return.request\",\"actionRef\":\"$RETURN_REF\",\"approvalId\":123,\"idempotencyKey\":\"return-ret-100045-v1\",\"payload\":{\"orderNumber\":\"$ORDER_NUMBER\",\"amount\":49.9,\"currency\":\"GBP\"}}"
BASH;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function governedReturnJavascriptSnippet(string $basePath): string
    {
        $snippet = <<<'JS'
const SITE_URL = process.env.SITE_URL ?? "https://example.test";
const BASE_URL = process.env.BASE_URL ?? `${SITE_URL}__BASE_PATH__`;
const AGENTS_TOKEN = process.env.AGENTS_TOKEN ?? "replace-with-token";
const RETURN_REF = process.env.RETURN_REF ?? "RET-100045";
const ORDER_NUMBER = process.env.ORDER_NUMBER ?? "A1B2C3D4";

async function request(path, init = {}) {
  const response = await fetch(`${BASE_URL}${path}`, {
    ...init,
    headers: {
      Authorization: `Bearer ${AGENTS_TOKEN}`,
      "Content-Type": "application/json",
      ...(init.headers ?? {}),
    },
  });

  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}: ${await response.text()}`);
  }

  return response.json();
}

const approval = await request("/control/approvals/request", {
  method: "POST",
  body: JSON.stringify({
    actionType: "return.request",
    actionRef: RETURN_REF,
    reason: "Customer control requested",
    metadata: {
      source: "agent-runtime",
      agentId: "returns-orchestrator",
      traceId: "trace-100045",
    },
    payload: {
      orderNumber: ORDER_NUMBER,
      amount: 49.9,
      currency: "GBP",
      lineItems: [{ sku: "SKU-123", qty: 1 }],
    },
  }),
});

const approvalId = approval?.data?.id ?? 123;

await request("/control/approvals/decide", {
  method: "POST",
  body: JSON.stringify({
    approvalId,
    decision: "approved",
    decisionReason: "Policy and amount threshold satisfied",
  }),
});

await request("/control/actions/execute", {
  method: "POST",
  headers: { "X-Idempotency-Key": `return-${RETURN_REF.toLowerCase()}-v1` },
  body: JSON.stringify({
    actionType: "return.request",
    actionRef: RETURN_REF,
    approvalId,
    idempotencyKey: `return-${RETURN_REF.toLowerCase()}-v1`,
    payload: {
      orderNumber: ORDER_NUMBER,
      amount: 49.9,
      currency: "GBP",
    },
  }),
});
JS;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function governedReturnPythonSnippet(string $basePath): string
    {
        $snippet = <<<'PY'
import os
import requests

SITE_URL = os.getenv("SITE_URL", "https://example.test")
BASE_URL = os.getenv("BASE_URL", f"{SITE_URL}__BASE_PATH__")
AGENTS_TOKEN = os.getenv("AGENTS_TOKEN", "replace-with-token")
RETURN_REF = os.getenv("RETURN_REF", "RET-100045")
ORDER_NUMBER = os.getenv("ORDER_NUMBER", "A1B2C3D4")

headers = {
    "Authorization": f"Bearer {AGENTS_TOKEN}",
    "Content-Type": "application/json",
}

def request(method: str, path: str, **kwargs):
    response = requests.request(method, f"{BASE_URL}{path}", headers=headers, timeout=20, **kwargs)
    response.raise_for_status()
    return response.json()

approval = request("POST", "/control/approvals/request", json={
    "actionType": "return.request",
    "actionRef": RETURN_REF,
    "reason": "Customer control requested",
    "metadata": {
        "source": "agent-runtime",
        "agentId": "returns-orchestrator",
        "traceId": "trace-100045",
    },
    "payload": {
        "orderNumber": ORDER_NUMBER,
        "amount": 49.9,
        "currency": "GBP",
        "lineItems": [{"sku": "SKU-123", "qty": 1}],
    },
})

approval_id = approval.get("data", {}).get("id", 123)

request("POST", "/control/approvals/decide", json={
    "approvalId": approval_id,
    "decision": "approved",
    "decisionReason": "Policy and amount threshold satisfied",
})

idempotency_key = f"return-{RETURN_REF.lower()}-v1"
request("POST", "/control/actions/execute", json={
    "actionType": "return.request",
    "actionRef": RETURN_REF,
    "approvalId": approval_id,
    "idempotencyKey": idempotency_key,
    "payload": {
        "orderNumber": ORDER_NUMBER,
        "amount": 49.9,
        "currency": "GBP",
    },
}, headers={**headers, "X-Idempotency-Key": idempotency_key})
PY;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function governedEntryDraftUpdateCurlSnippet(string $basePath): string
    {
        $snippet = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

: "${SITE_URL:=https://example.test}"
: "${BASE_URL:=$SITE_URL__BASE_PATH__}"
: "${AGENTS_TOKEN:=replace-with-token}"
: "${ENTRY_ID:=1001}"
: "${SITE_ID:=1}"

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/request" \
  -d "{\"actionType\":\"entry.updateDraft\",\"actionRef\":\"ENTRY-OPS-$ENTRY_ID\",\"reason\":\"Prepare draft update for editorial review\",\"metadata\":{\"source\":\"agent-runtime\",\"agentId\":\"content-ops\",\"traceId\":\"trace-entry-$ENTRY_ID\"},\"payload\":{\"entryId\":$ENTRY_ID,\"siteId\":$SITE_ID}}"

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" -H "X-Idempotency-Key: entry-ops-$ENTRY_ID-v1" "$BASE_URL/control/actions/execute" \
  -d "{\"actionType\":\"entry.updateDraft\",\"actionRef\":\"ENTRY-OPS-$ENTRY_ID\",\"approvalId\":123,\"idempotencyKey\":\"entry-ops-$ENTRY_ID-v1\",\"payload\":{\"entryId\":$ENTRY_ID,\"siteId\":$SITE_ID,\"draftName\":\"Agent update draft\",\"draftNotes\":\"Prepared by governed automation for editorial review.\",\"title\":\"Updated Draft Title\",\"slug\":\"updated-draft-title\",\"fields\":{\"summary\":\"Short summary prepared by the agent.\"}}}"
BASH;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function governedEntryDraftUpdateJavascriptSnippet(string $basePath): string
    {
        $snippet = <<<'JS'
const SITE_URL = process.env.SITE_URL ?? "https://example.test";
const BASE_URL = process.env.BASE_URL ?? `${SITE_URL}__BASE_PATH__`;
const AGENTS_TOKEN = process.env.AGENTS_TOKEN ?? "replace-with-token";
const ENTRY_ID = Number(process.env.ENTRY_ID ?? 1001);
const SITE_ID = Number(process.env.SITE_ID ?? 1);

async function request(path, init = {}) {
  const response = await fetch(`${BASE_URL}${path}`, {
    ...init,
    headers: {
      Authorization: `Bearer ${AGENTS_TOKEN}`,
      "Content-Type": "application/json",
      ...(init.headers ?? {}),
    },
  });

  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}: ${await response.text()}`);
  }

  return response.json();
}

await request("/control/approvals/request", {
  method: "POST",
  body: JSON.stringify({
    actionType: "entry.updateDraft",
    actionRef: `ENTRY-OPS-${ENTRY_ID}`,
    reason: "Prepare draft update for editorial review",
    metadata: {
      source: "agent-runtime",
      agentId: "content-ops",
      traceId: `trace-entry-${ENTRY_ID}`,
    },
    payload: {
      entryId: ENTRY_ID,
      siteId: SITE_ID,
    },
  }),
});

await request("/control/actions/execute", {
  method: "POST",
  headers: { "X-Idempotency-Key": `entry-ops-${ENTRY_ID}-v1` },
  body: JSON.stringify({
    actionType: "entry.updateDraft",
    actionRef: `ENTRY-OPS-${ENTRY_ID}`,
    approvalId: 123,
    idempotencyKey: `entry-ops-${ENTRY_ID}-v1`,
    payload: {
      entryId: ENTRY_ID,
      siteId: SITE_ID,
      draftName: "Agent update draft",
      draftNotes: "Prepared by governed automation for editorial review.",
      title: "Updated Draft Title",
      slug: "updated-draft-title",
      fields: {
        summary: "Short summary prepared by the agent.",
      },
    },
  }),
});
JS;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function governedEntryDraftUpdatePythonSnippet(string $basePath): string
    {
        $snippet = <<<'PY'
import os
import requests

SITE_URL = os.getenv("SITE_URL", "https://example.test")
BASE_URL = os.getenv("BASE_URL", f"{SITE_URL}__BASE_PATH__")
AGENTS_TOKEN = os.getenv("AGENTS_TOKEN", "replace-with-token")
ENTRY_ID = int(os.getenv("ENTRY_ID", "1001"))
SITE_ID = int(os.getenv("SITE_ID", "1"))

headers = {
    "Authorization": f"Bearer {AGENTS_TOKEN}",
    "Content-Type": "application/json",
}

def request(method: str, path: str, **kwargs):
    response = requests.request(method, f"{BASE_URL}{path}", headers=headers, timeout=20, **kwargs)
    response.raise_for_status()
    return response.json()

request("POST", "/control/approvals/request", json={
    "actionType": "entry.updateDraft",
    "actionRef": f"ENTRY-OPS-{ENTRY_ID}",
    "reason": "Prepare draft update for editorial review",
    "metadata": {
        "source": "agent-runtime",
        "agentId": "content-ops",
        "traceId": f"trace-entry-{ENTRY_ID}",
    },
    "payload": {
        "entryId": ENTRY_ID,
        "siteId": SITE_ID,
    },
})

idempotency_key = f"entry-ops-{ENTRY_ID}-v1"
request("POST", "/control/actions/execute", json={
    "actionType": "entry.updateDraft",
    "actionRef": f"ENTRY-OPS-{ENTRY_ID}",
    "approvalId": 123,
    "idempotencyKey": idempotency_key,
    "payload": {
        "entryId": ENTRY_ID,
        "siteId": SITE_ID,
        "draftName": "Agent update draft",
        "draftNotes": "Prepared by governed automation for editorial review.",
        "title": "Updated Draft Title",
        "slug": "updated-draft-title",
        "fields": {
            "summary": "Short summary prepared by the agent.",
        },
    },
}, headers={**headers, "X-Idempotency-Key": idempotency_key})
PY;

        return str_replace('__BASE_PATH__', $basePath, trim($snippet));
    }

    private function normalizeBasePath(string $basePath): string
    {
        $normalized = trim($basePath);
        if ($normalized === '') {
            return '/agents/v1';
        }

        if (!str_starts_with($normalized, '/')) {
            $normalized = '/' . $normalized;
        }

        return rtrim($normalized, '/');
    }

    private function resolvePluginVersion(): string
    {
        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $version = trim((string)$plugin->getVersion());
            if ($version !== '') {
                return $version;
            }

            $schemaVersion = trim((string)$plugin->schemaVersion);
            if ($schemaVersion !== '') {
                return $schemaVersion;
            }
        }

        return '0.10.0';
    }
}
