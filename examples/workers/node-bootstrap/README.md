# Node Bootstrap Worker

This example proves the current managed-account bootstrap flow end to end.

The easiest path is:

- create or rotate a managed account in Craft
- copy the one-time revealed token
- place that token in `.env`
- run `./run-worker.sh`

It expects either:

- `BASE_URL` set to the full `https://example.test/agents/v1` base, or
- `SITE_URL` set to the site root so the worker can derive `/agents/v1`

## Configure

```bash
cp .env.example .env
```

Fill in:

- `SITE_URL` or `BASE_URL`
- `AGENTS_TOKEN`

If your install offers a helper export for worker config, you can use that instead of filling `.env` by hand.

## Run once

```bash
./run-worker.sh
```

## Schedule with cron

```txt
*/5 * * * * /bin/bash /absolute/path/to/examples/workers/node-bootstrap/run-worker.sh >> /absolute/path/to/examples/workers/node-bootstrap/worker.log 2>&1
```
