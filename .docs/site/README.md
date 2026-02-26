# Agents Public Docs Site

Static documentation site for the `klick/agents` plugin.

## Local development

```bash
cd .docs/site
npm install
npm run dev
```

## Build static files

```bash
cd .docs/site
npm run build
```

Build output:

- `.docs/site/docs/.vitepress/dist`

## Hosting notes

- VitePress `base` is configured as `/docs/agents/`.
- Configure `AGENTS_DOCS_HOSTNAME` in CI/CD for sitemap generation, for example:
  - `AGENTS_DOCS_HOSTNAME=https://your-domain.tld/docs/agents/`
