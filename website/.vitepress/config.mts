import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

// ADR 0016 : une seule source. Les pages d'un paquet vivent dans packages/<paquet>/docs
// (publiées aussi par le split), les pages transverses dans website/. srcDir est donc la
// racine du monorepo, réduite par srcExclude, et rewrites donne à chaque page son URL.
export default defineConfig({
    srcDir: '..',
    srcExclude: [
        '*.md',
        '**/vendor/**',
        '**/node_modules/**',
        '**/tests/**',
        '**/README.md',
        'benchmarks/**',
        'demo/**',
        'docker/**',
        'docs/**',
        'recipes/**',
        'tools/**',
        'var/**',
    ],
    rewrites: {
        'website/:page*': ':page*',
        'packages/:pkg/docs/:page*': ':pkg/:page*',
    },

    title: 'Prism ORM',
    description: 'A modern PHP ORM for business applications and AI.',
    lang: 'en-US',
    cleanUrls: true,
    head: [['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }]],

    themeConfig: {
        logo: '/logo.svg',
        nav: [
            { text: 'Symfony', link: '/bundle/symfony' },
            { text: 'Commands', link: '/bundle/commands' },
        ],
        sidebar: [
            {
                text: 'Symfony',
                items: [
                    { text: 'Prism in Symfony', link: '/bundle/symfony' },
                    { text: 'Console commands', link: '/bundle/commands' },
                ],
            },
        ],
        search: { provider: 'local' },
        outline: { level: [2, 3] },
        socialLinks: [{ icon: 'github', link: 'https://github.com/IMAMx39/prism' }],
        editLink: {
            pattern: ({ filePath }) => `https://github.com/IMAMx39/prism/edit/main/${filePath}`,
            text: 'Edit this page',
        },
        footer: {
            message: 'Released under the MIT License.',
        },
    },

    vite: {
        resolve: {
            // une page de packages/ ne voit pas website/node_modules en remontant les dossiers
            alias: { vue: fileURLToPath(new URL('../node_modules/vue', import.meta.url)) },
        },
        server: {
            // la racine du monorepo est servie : ne surveiller que les sources des pages
            watch: { ignored: ['**/vendor/**', '**/var/**', '**/demo/**', '**/tests/**'] },
        },
    },
})
