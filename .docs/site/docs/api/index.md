---
title: API Overview
---

# API Overview

Base path:

- `/agents/v1`

Core characteristics:

- read-only endpoints
- JSON responses
- stable error schema with `requestId`
- scoped token auth by default
- rate-limited guarded routes

## Endpoints summary

- `GET /health`
- `GET /readiness`
- `GET /products`
- `GET /orders`
- `GET /orders/show`
- `GET /entries`
- `GET /entries/show`
- `GET /changes`
- `GET /sections`
- `GET /capabilities`
- `GET /openapi.json`

Public discovery routes:

- `GET /llms.txt`
- `GET /commerce.txt`

Continue with:

- [Auth & Scopes](/api/auth-and-scopes)
- [Endpoints](/api/endpoints)
- [Errors & Rate Limits](/api/errors-and-rate-limits)
- [Incremental Sync](/api/incremental-sync)

