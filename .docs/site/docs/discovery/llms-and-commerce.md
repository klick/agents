---
title: llms.txt & commerce.txt
---

# `llms.txt` and `commerce.txt`

## `llms.txt`

Purpose:

- machine-readable discovery overview for assistants/indexers

Typical content includes:

- site identity/summary
- links to sitemap and key API endpoints
- optional project-defined links

## `commerce.txt`

Purpose:

- machine-readable commerce metadata and policy pointers

Typical content includes:

- store metadata
- policy URLs
- support contacts
- catalog and capabilities links

## Configuration ownership

These files are controlled through `config/agents.php` fields such as:

- `enableLlmsTxt`
- `enableCommerceTxt`
- `llms*` fields
- `commerce*` fields

## Operations

Use CP Discovery actions for safe cache operations:

- prewarm `all`
- prewarm `llms`
- prewarm `commerce`
- clear cache

