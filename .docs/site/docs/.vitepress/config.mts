import { defineConfig } from 'vitepress'

const base = '/docs/agents/'
const fallbackHostname = 'https://example.com/docs/agents/'
const hostname = (process.env.AGENTS_DOCS_HOSTNAME || fallbackHostname).replace(/([^/])$/, '$1/')

export default defineConfig({
  title: 'Agents Plugin Docs',
  description: 'Public documentation for the klick/agents Craft CMS plugin API, operations, and security model.',
  lang: 'en-US',
  base,
  cleanUrls: true,
  lastUpdated: true,
  sitemap: {
    hostname,
  },
  head: [
    ['meta', { name: 'theme-color', content: '#0b1220' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Agents Plugin Docs' }],
    ['meta', { property: 'og:description', content: 'Reference docs for API, CLI, discovery files, and operations.' }],
  ],
  themeConfig: {
    search: {
      provider: 'local',
    },
    nav: [
      { text: 'Get Started', link: '/get-started/' },
      { text: 'API', link: '/api/' },
      { text: 'Security', link: '/security/' },
      { text: 'Changelog', link: '/changelog/' },
      { text: 'GitHub', link: 'https://github.com/klick/agents' },
    ],
    sidebar: {
      '/get-started/': [
        {
          text: 'Get Started',
          items: [
            { text: 'Overview', link: '/get-started/' },
            { text: 'Installation & Setup', link: '/get-started/installation-setup' },
            { text: 'Configuration', link: '/get-started/configuration' },
          ],
        },
      ],
      '/api/': [
        {
          text: 'API Reference',
          items: [
            { text: 'Overview', link: '/api/' },
            { text: 'Auth & Scopes', link: '/api/auth-and-scopes' },
            { text: 'Endpoints', link: '/api/endpoints' },
            { text: 'Errors & Rate Limits', link: '/api/errors-and-rate-limits' },
            { text: 'Incremental Sync', link: '/api/incremental-sync' },
          ],
        },
      ],
      '/discovery/': [
        {
          text: 'Discovery Files',
          items: [
            { text: 'Overview', link: '/discovery/' },
            { text: 'llms.txt & commerce.txt', link: '/discovery/llms-and-commerce' },
          ],
        },
      ],
      '/cp/': [
        {
          text: 'Control Panel',
          items: [
            { text: 'Cockpit Overview', link: '/cp/' },
          ],
        },
      ],
      '/security/': [
        {
          text: 'Security',
          items: [
            { text: 'Overview', link: '/security/' },
            { text: 'Deployment Checklist', link: '/security/deployment-checklist' },
          ],
        },
      ],
      '/webhooks/': [
        {
          text: 'Webhooks',
          items: [
            { text: 'Webhook Delivery', link: '/webhooks/' },
          ],
        },
      ],
      '/cli/': [
        {
          text: 'CLI',
          items: [
            { text: 'Commands', link: '/cli/' },
          ],
        },
      ],
      '/troubleshooting/': [
        {
          text: 'Troubleshooting',
          items: [
            { text: 'Troubleshooting Flow', link: '/troubleshooting/' },
          ],
        },
      ],
      '/changelog/': [
        {
          text: 'Changelog',
          items: [
            { text: 'Release Notes', link: '/changelog/' },
          ],
        },
      ],
      '/roadmap/': [
        {
          text: 'Roadmap',
          items: [
            { text: 'Roadmap Overview', link: '/roadmap/' },
          ],
        },
      ],
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/klick/agents' },
    ],
    footer: {
      message: 'Agents plugin documentation',
      copyright: 'Copyright © Klick'
    },
  },
})
