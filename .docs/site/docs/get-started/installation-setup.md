---
title: Installation & Setup
---

# Installation & Setup

## Plugin Store installation

```bash
composer require klick/agents:^0.1.3
php craft plugin/install agents
```

## Local monorepo/sandbox workflow

Use a dedicated sandbox project for development and testing.

1. Bootstrap sandbox:

```bash
./scripts/dev/bootstrap-sandbox.sh ~/sites/agents-sandbox
```

2. Link local plugin source via path repository:

```bash
./scripts/dev/configure-local-plugin.sh ~/sites/agents-sandbox
```

3. Install plugin in sandbox:

```bash
php craft plugin/install agents
```

4. Apply deterministic fixture config:

```bash
./scripts/dev/apply-fixture-config.sh ~/sites/agents-sandbox agents-local-token
```

5. Run smoke checks:

```bash
./scripts/dev/smoke-sandbox.sh https://agents-sandbox.ddev.site agents-local-token
```

## Important setup policy

- Keep production-bound projects pinned to released versions.
- Do not keep permanent path repository overrides in production-bound projects.
- If temporary local debugging is required, remove local override immediately afterward.

