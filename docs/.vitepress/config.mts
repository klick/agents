import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Agents',
  description: 'Governed machine access for Craft CMS and Craft Commerce',
  lang: 'en-US',
  base: '/docs/agents/',
  cleanUrls: true,
  lastUpdated: true,
  themeConfig: {
    nav: [
      { text: 'Get Started', link: '/get-started/' },
      { text: 'Workflows', link: '/workflows/' },
      { text: 'Control Plane', link: '/cp/' },
      { text: 'API', link: '/api/' },
      { text: 'Security', link: '/security/' },
      { text: 'Operations', link: '/troubleshooting/' },
      { text: 'Changelog', link: '/changelog/' }
    ],
    search: {
      provider: 'local'
    },
    sidebar: [
      {
        text: 'Overview',
        items: [
          { text: 'Introduction', link: '/' },
          { text: 'Execution Model', link: '/security/execution-model' },
          { text: 'Roadmap', link: '/roadmap/' },
          { text: 'Changelog', link: '/changelog/' }
        ]
      },
      {
        text: 'Get Started',
        items: [
          { text: 'Get Started', link: '/get-started/' },
          { text: 'Installation & Setup', link: '/get-started/installation-setup' },
          { text: 'Configuration', link: '/get-started/configuration' },
          { text: 'First Worker', link: '/get-started/first-worker' },
          { text: 'Agents vs Element API', link: '/get-started/agents-vs-element-api' }
        ]
      },
      {
        text: 'Workflows',
        items: [
          { text: 'Workflow Guides', link: '/workflows/' },
          { text: 'Governed Entry Drafts', link: '/workflows/governed-entry-drafts' },
          { text: 'Entry Translation Drafts', link: '/workflows/entry-translation-drafts' }
        ]
      },
      {
        text: 'Control Plane',
        items: [
          { text: 'Dashboard & CP', link: '/cp/' }
        ]
      },
      {
        text: 'API',
        items: [
          { text: 'API Overview', link: '/api/' },
          { text: 'Agent Bootstrap', link: '/api/agent-bootstrap' },
          { text: 'Starter Packs', link: '/api/starter-packs' },
          { text: 'Auth & Scopes', link: '/api/auth-and-scopes' },
          { text: 'Scope Guide', link: '/api/scope-guide' },
          { text: 'Endpoints', link: '/api/endpoints' },
          { text: 'Errors & Rate Limits', link: '/api/errors-and-rate-limits' },
          { text: 'Incremental Sync', link: '/api/incremental-sync' },
          { text: 'Compatibility & Deprecations', link: '/api/compatibility-and-deprecations' }
        ]
      },
      {
        text: 'Operations',
        items: [
          { text: 'Security', link: '/security/' },
          { text: 'Execution Model', link: '/security/execution-model' },
          { text: 'Webhooks', link: '/webhooks/' },
          { text: 'CLI', link: '/cli/' },
          { text: 'Troubleshooting', link: '/troubleshooting/' },
          { text: 'Agent Lifecycle Governance', link: '/troubleshooting/agent-lifecycle-governance' },
          { text: 'Observability Runbook', link: '/troubleshooting/observability-runbook' }
        ]
      }
    ],
    socialLinks: [
      { icon: 'github', link: 'https://github.com/klick/agents' }
    ],
    footer: {
      message: 'Governed machine access for Craft CMS and Craft Commerce',
      copyright: 'Copyright 2026 Klick'
    }
  }
})
