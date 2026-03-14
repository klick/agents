# First Worker

Use this guide to prove the current managed-account bootstrap path end to end with a real worker.

This flow keeps the auth model simple:

- create one managed account
- reveal its token once
- put that token into a worker environment
- run the worker manually
- then schedule it

## 1. Create a worker account

In `Agents -> Accounts`, create a new managed account for the worker.

Recommended fields:

- Name: `Bootstrap Worker`
- Description: `First worker connectivity check`
- Owner: set a real CP user

Recommended scopes:

- `health:read`
- `readiness:read`
- `auth:read`

This keeps the worker read-only while still proving that authentication, scopes, and status endpoints work.

## 2. Copy the token once

When the account is created, Agents will reveal the token once.

Put that token into the worker environment immediately. If it is lost, rotate the account and use the new token.

## 3. Configure the example worker

Example path in the public repo:

- `examples/workers/node-bootstrap/`

Prepare the local env file:

```bash
cd examples/workers/node-bootstrap
cp .env.example .env
```

Fill in:

- `SITE_URL` with the site root, such as `https://example.test`
- or `BASE_URL` with the full `https://example.test/agents/v1` base
- `AGENTS_TOKEN` with the one-time revealed token

## 4. Run the worker once

```bash
cd examples/workers/node-bootstrap
./run-worker.sh
```

The worker will call:

- `GET /agents/v1/health`
- `GET /agents/v1/auth/whoami`
- `GET /agents/v1/readiness`

Expected result:

- readable console output for each step
- JSON response output
- a non-zero exit code on invalid token, missing scope, timeout, or transport failure

## 5. Schedule it

Example cron entry:

```txt
*/5 * * * * /bin/bash /absolute/path/to/examples/workers/node-bootstrap/run-worker.sh >> /absolute/path/to/examples/workers/node-bootstrap/worker.log 2>&1
```

This is intentionally simple:

- one environment file
- one runner script
- one log file

## 6. What this proves

This scenario validates the current onboarding model before any pairing/connect flow work:

- account creation works
- token reveal/handoff works
- the worker can authenticate
- the worker sees its own identity and scopes in `auth/whoami`
- the worker can run on a schedule without extra infrastructure

## Related

- [Installation & Setup](/get-started/installation-setup)
- [Configuration](/get-started/configuration)
- [Agent Bootstrap](/api/agent-bootstrap)
- [Starter Packs](/api/starter-packs)
