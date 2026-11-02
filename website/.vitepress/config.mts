import { defineConfig } from 'vitepress'

// DOCS_BASE: "/" for a custom domain, "/<repo>/" for https://<user>.github.io/<repo>/ (set by the workflow).
const base = process.env.DOCS_BASE ?? '/'

export default defineConfig({
  base,
  lang: 'en-US',
  title: 'Laravel ZATCA',
  description: 'ZATCA Phase 2 (Fatoora) e-invoicing for Laravel 9–13: B2B clearance, B2C reporting, corrections and credit notes.',
  cleanUrls: true,
  lastUpdated: true,
  head: [['meta', { name: 'theme-color', content: '#0f766e' }]],
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'Reference', link: '/reference/configuration' },
      { text: 'Changelog', link: 'https://github.com/mranonyms022/laravel-zetca/blob/main/CHANGELOG.md' },
    ],
    sidebar: {
      '/guide/': [
        {
          text: 'Getting started',
          items: [
            { text: 'Introduction', link: '/guide/introduction' },
            { text: 'Key concepts', link: '/guide/concepts' },
            { text: 'Requirements', link: '/guide/requirements' },
            { text: 'Installation', link: '/guide/installation' },
            { text: 'Quick start (sandbox)', link: '/guide/quick-start' },
          ],
        },
        {
          text: 'Setup',
          items: [
            { text: 'Taxpayers and devices', link: '/guide/devices' },
            { text: 'Queues, workers and scheduler', link: '/guide/workers' },
          ],
        },
        {
          text: 'Invoicing',
          items: [
            { text: 'Invoice payload', link: '/guide/payload' },
            { text: 'B2C: simplified invoices', link: '/guide/b2c' },
            { text: 'B2B: standard invoices', link: '/guide/b2b' },
            { text: 'Statuses and lifecycle', link: '/guide/statuses' },
            { text: 'Rejections, retries and corrections', link: '/guide/corrections' },
            { text: 'Credit and debit notes', link: '/guide/notes' },
          ],
        },
        {
          text: 'Integration',
          items: [
            { text: 'HTTP API', link: '/guide/http-api' },
            { text: 'Events and notifications', link: '/guide/events' },
            { text: 'Multi-company and multi-tenant', link: '/guide/tenancy' },
          ],
        },
        {
          text: 'Operations',
          items: [
            { text: 'Monitoring and daily checks', link: '/guide/monitoring' },
            { text: 'Performance and scaling', link: '/guide/scaling' },
            { text: 'Security', link: '/guide/security' },
            { text: 'Going live', link: '/guide/going-live' },
            { text: 'Troubleshooting and FAQ', link: '/guide/troubleshooting' },
          ],
        },
      ],
      '/reference/': [
        {
          text: 'Reference',
          items: [
            { text: 'Configuration', link: '/reference/configuration' },
            { text: 'Artisan commands', link: '/reference/commands' },
            { text: 'PHP API', link: '/reference/php-api' },
            { text: 'Events', link: '/reference/events' },
            { text: 'Validation rules', link: '/reference/validation-rules' },
            { text: 'Database tables', link: '/reference/database' },
            { text: 'Compatibility', link: '/reference/compatibility' },
          ],
        },
      ],
    },
    socialLinks: [{ icon: 'github', link: 'https://github.com/mranonyms022/laravel-zetca' }],
    search: { provider: 'local' },
    outline: { level: [2, 3] },
    editLink: {
      pattern: 'https://github.com/mranonyms022/laravel-zetca/edit/main/website/:path',
      text: 'Edit this page on GitHub',
    },
    footer: {
      message: 'Not affiliated with or endorsed by ZATCA. Verify regulatory requirements with ZATCA and your tax advisor.',
      copyright: 'CodingTriplets',
    },
  },
})
