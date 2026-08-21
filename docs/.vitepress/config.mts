import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { defineConfig } from 'vitepress'
import { link, pages, sections, slug } from './pages'

const site = 'https://sandermuller.github.io/laravel-queue-insights/'

const description = 'Self-hosted, driver-agnostic queue observability for Laravel: depth, wait time, throughput, batches, chains, alerting, Prometheus, and scheduler runs — across SQS, Redis, and database queues.'

/**
 * A markdown link between source pages (`04-dashboard.md#anchor`) points at a file that only
 * exists in the repo. Rewritten to the published URL so a reader who has the plain-text copy can
 * follow it without guessing the route.
 */
const absoluteLinks = (markdown: string): string => markdown.replace(
    /\]\((\d+-[a-z-]+)\.md(#[a-z0-9-]*)?\)/g,
    (_, file: string, anchor = '') => `](${site}${slug(file)}${anchor})`,
)

export default defineConfig({
    title: 'Laravel Queue Insights',
    description,
    base: '/laravel-queue-insights/',
    cleanUrls: true,
    lastUpdated: true,

    // Lets search engines and agent crawlers enumerate the pages instead of
    // discovering them only by following links from the home page.
    sitemap: {
        // Trailing slash required: routes resolve against this URL, and
        // without it the base path segment is dropped from every entry.
        hostname: site,
    },

    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/laravel-queue-insights/logo.svg' }],
        ['meta', { name: 'theme-color', content: '#10b981' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Laravel Queue Insights' }],
        ['meta', { property: 'og:description', content: description }],
        ['meta', { property: 'og:image', content: `${site}header.png` }],
        ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
        ['meta', { name: 'twitter:image', content: `${site}header.png` }],
    ],

    /**
     * Plain-text builds beside the HTML, for readers that are not browsers
     * (https://llmstxt.org). `llms.txt` is the index and `llms-full.txt` is every page in reading
     * order behind one URL, so an agent can take the whole documentation in a single fetch instead
     * of crawling every page of nav chrome. Each page is also written as `<slug>.md`, for the
     * reader that wants one page rather than all of them. All three are generated from the same
     * page list as the sidebar, so they cannot drift from the site.
     */
    buildEnd: async ({ outDir, srcDir }) => {
        mkdirSync(outDir, { recursive: true })

        const index: string[] = ['# Laravel Queue Insights', '', `> ${description}`, '']

        for (const section of sections) {
            index.push(`## ${section.text}`, '')

            for (const page of section.pages) {
                index.push(`- [${page.text}](${site}${slug(page.file)}): ${page.blurb}`)
            }

            index.push('')
        }

        writeFileSync(join(outDir, 'llms.txt'), index.join('\n'))

        const parts: string[] = ['# Laravel Queue Insights', '', `> ${description} Full documentation, ${pages.length} pages, in reading order. Index: ${site}llms.txt`, '']

        for (const page of pages) {
            const markdown = absoluteLinks(readFileSync(join(srcDir, `${page.file}.md`), 'utf-8'))

            writeFileSync(join(outDir, `${slug(page.file)}.md`), markdown)
            parts.push(`<!-- ${site}${slug(page.file)} -->`, '', markdown.trim(), '')
        }

        writeFileSync(join(outDir, 'llms-full.txt'), parts.join('\n'))
    },

    // README.md is the GitHub-facing folder index; the site's home is home.md.
    srcExclude: ['README.md'],

    rewrites: {
        'home.md': 'index.md',
        ...Object.fromEntries(pages.map(page => [`${page.file}.md`, `${page.file.replace(/^\d+-/, '')}.md`])),
    },

    markdown: {
        // Markdown links target the NN-prefixed source files so they work on
        // GitHub; strip the prefix at render time to match the rewritten routes.
        config(md) {
            const defaultRender = md.renderer.rules.link_open
                ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options))
            md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
                const href = tokens[idx].attrGet('href')
                if (href && /^(\.\/)?\d+-/.test(href)) {
                    tokens[idx].attrSet('href', href.replace(/^(\.\/)?\d+-/, '$1'))
                }
                return defaultRender(tokens, idx, options, env, self)
            }
        },
    },

    themeConfig: {
        logo: '/logo.svg',

        nav: [
            { text: 'Guide', link: link('01-why-queue-insights') },
            { text: 'Alerting', link: link('10-alerting') },
            { text: 'Config', link: link('16-configuration') },
            { text: 'Demo', link: 'https://queue-insights-demo.laravel.cloud' },
            { text: 'Releases', link: 'https://github.com/SanderMuller/laravel-queue-insights/releases' },
            { text: 'Packagist', link: 'https://packagist.org/packages/sandermuller/laravel-queue-insights' },
        ],

        sidebar: sections.map(section => ({
            text: section.text,
            items: section.pages.map(page => ({ text: page.text, link: link(page.file) })),
        })),

        socialLinks: [
            { icon: 'github', link: 'https://github.com/SanderMuller/laravel-queue-insights' },
        ],

        docFooter: {
            next: false,
        },

        editLink: {
            pattern: 'https://github.com/SanderMuller/laravel-queue-insights/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },

        outline: {
            level: [2, 3],
        },
    },
})
