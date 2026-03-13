# Get Started

Agents is the governed machine-access layer for Craft CMS and Craft Commerce. Start by installing the plugin, configuring machine credentials, and validating the API and control-plane posture before connecting agents, automations, or external integrations.

## Requirements

- PHP `^8.2`
- Craft CMS `^5.0`
- Craft Commerce recommended for full commerce endpoints

## Install

```bash
composer require klick/agents:^0.21.2
php craft plugin/install agents
```

## Verify

- Open CP: `admin/agents/status`
- Confirm service state and readiness score
- Hit `GET /agents/v1/health`
- Hit `GET /agents/v1/schema` with a token that has `schema:read`
- Hit `GET /agents/v1/capabilities` to inspect the discovered contract and auth posture

## Next

- Set environment variables in `.env` / `config/agents.php`
- Configure API credentials (scopes + optional TTL/IP allowlists)
- Treat credentials as machine identities with the minimum scopes each integration needs
- Use starter packs for copy/paste integration bootstrap (`/api/starter-packs`)
- Review webhook scopes and delivery posture before production

See:

- [Installation & Setup](/get-started/installation-setup)
- [Configuration](/get-started/configuration)
- [Agent Bootstrap](/api/agent-bootstrap)
- [Starter Packs](/api/starter-packs)
