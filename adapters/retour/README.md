# Agents Retour Adapter

Standalone adapter plugin that exposes read-only Retour resources to Agents via external plugin scopes.

## Resources

- `plugins:retour:redirects:read`

## Install

1. Install `klick/agents`
2. Install this adapter package
3. Ensure the Retour plugin is installed in Craft
4. Open `Agents -> Accounts` to assign the new external scope

The adapter only registers when Retour is installed and its redirect table is available.

## Local validation

In the local sandbox, validate the adapter as a real installed Craft plugin with:

```bash
AGENTS_RUN_RETOUR_REAL_INSTALL=1 bash plugins/agents/scripts/qa/release-gate.sh
```

Or run the dedicated check directly:

```bash
bash plugins/agents/scripts/qa/retour-adapter-real-install-check.sh
```
