# First Worker

Use this guide to get from a new managed account to a real scheduled worker call.

By the end of this flow, you will have:

- a read-only worker account in Craft
- a worker `.env` file
- a successful worker run against `/agents/v1/*`
- a cron example for repeating that check

## Prerequisites

- Agents is installed and enabled
- you can open `Agents -> Accounts`
- you have this repo locally if you want to use the example worker

Example worker path:

- `examples/workers/node-bootstrap/`

## 1. Create a read-only worker account

In `Agents -> Accounts`, create a managed account for the worker.

Recommended fields:

- Name: `Bootstrap Worker`
- Description: `First worker connectivity check`
- Owner: set a real CP user

Recommended scopes:

- `health:read`
- `readiness:read`
- `auth:read`

This keeps the worker read-only while still proving that authentication, identity, and status endpoints work.

## 2. Reveal the token once

When the account is created or rotated, Agents reveals the account token once.

Use it immediately:

- copy the token
- save it in a secure place long enough to configure the worker
- if you lose it, rotate the account and use the new token

Important:

- the token is only shown once
- do not treat it like a human login credential
- keep the account read-only for this first bootstrap

Some installs may expose helper actions such as config export or account validation in the Control Panel. Those are optional conveniences. The core bootstrap flow is still:

- account
- token
- worker environment
- real external run

## 3. Configure the example worker

```bash
cd examples/workers/node-bootstrap
cp .env.example .env
```

Set:

- `SITE_URL` to the site root, such as `https://example.test`
- or `BASE_URL` to the full `https://example.test/agents/v1` base
- `AGENTS_TOKEN` to the one-time revealed token

For local environments, prefer a directly reachable URL if your runtime cannot resolve vanity hostnames.

## 4. Run the worker once

```bash
cd examples/workers/node-bootstrap
./run-worker.sh
```

The worker calls:

- `GET /agents/v1/health`
- `GET /agents/v1/auth/whoami`
- `GET /agents/v1/readiness`

Expected result:

- readable console output for each step
- JSON output for each response
- a non-zero exit code on invalid token, missing scope, timeout, or transport failure

If the worker fails here, fix that before you think about cron or a fuller integration.

## 5. Schedule it

Example cron entry:

```txt
*/5 * * * * /bin/bash /absolute/path/to/examples/workers/node-bootstrap/run-worker.sh >> /absolute/path/to/examples/workers/node-bootstrap/worker.log 2>&1
```

This is intentionally simple:

- one environment file
- one runner script
- one log file

## 6. Troubleshooting

If the worker fails before auth:

- check `SITE_URL` / `BASE_URL`
- check DNS/TLS reachability from the worker runtime
- if a local vanity hostname does not resolve in your runtime, use a directly reachable base URL instead

If the worker returns `401 Unauthorized`:

- the token is missing or invalid
- rotate the account and use the new one-time reveal

If the worker returns `403 Forbidden`:

- the account is authenticated
- but the required scope is missing

## 7. What this proves

This flow validates the current onboarding model before any future pairing/connect work:

- account creation works
- token reveal works
- worker configuration is straightforward
- the worker can authenticate
- the worker sees its own identity and scopes in `auth/whoami`
- the worker can run on a schedule without extra infrastructure

## Related

- [Installation & Setup](/get-started/installation-setup)
- [Configuration](/get-started/configuration)
- [Agent Bootstrap](/api/agent-bootstrap)
- [Starter Packs](/api/starter-packs)
