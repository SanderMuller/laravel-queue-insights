/**
 * Single source of truth for the documentation order.
 *
 * Filenames keep their NN- prefix so GitHub renders docs/ in reading order;
 * `slug` strips the prefix so site URLs stay stable when pages are reordered.
 * `blurb` is what the end-of-page "Next" call to action shows, so it reads as
 * a reason to continue rather than a bare page title.
 */
export type DocPage = {
    file: string
    text: string
    blurb: string
}

export type DocSection = {
    text: string
    pages: DocPage[]
}

export const sections: DocSection[] = [
    {
        text: 'Getting started',
        pages: [
            {
                file: '01-why-queue-insights',
                text: 'Why Queue Insights?',
                blurb: 'The questions it answers that queue:work and a failed_jobs table leave open.',
            },
            {
                file: '02-installation',
                text: 'Installation',
                blurb: 'Requirements, the install command, the snapshot scheduler, and the environment knobs.',
            },
            {
                file: '03-payload-capture',
                text: 'Payload capture',
                blurb: 'Three capture modes, the separate pending budget, and binding your own sanitizer.',
            },
        ],
    },
    {
        text: 'Dashboard',
        pages: [
            {
                file: '04-dashboard',
                text: 'Dashboard',
                blurb: 'Authorisation, multi-connection scoping, retry permissions, and the filter row.',
            },
            {
                file: '05-jobs-batches-chains',
                text: 'Jobs, batches, and chains',
                blurb: 'Wait time, the pending inspector, batch progress, chain lineage, and job initiator.',
            },
            {
                file: '06-failure-context',
                text: 'Failure context',
                blurb: 'What is captured when a job fails, and the Sentry deep-link into the matching issue.',
            },
            {
                file: '07-theming-and-embedding',
                text: 'Theming and embedding',
                blurb: 'Custom row markup, mounting inside an admin layout, dark mode, and the cloud look.',
            },
        ],
    },
    {
        text: 'Operations',
        pages: [
            {
                file: '08-running-workers',
                text: 'Running workers',
                blurb: 'The queue-insights:work supervisor, its non-goals, and shutdown grace tuning.',
            },
            {
                file: '09-ops-runbook',
                text: 'Ops runbook',
                blurb: 'Console commands, dashboard signals, driver quirks, key prefixes, and Redis Cluster.',
            },
            {
                file: '10-alerting',
                text: 'Alerting',
                blurb: 'Nine detectors, per-rule cooldown, notification channels, typed events, and silencing.',
            },
        ],
    },
    {
        text: 'Integrations',
        pages: [
            {
                file: '11-horizon',
                text: 'Horizon auto-discovery',
                blurb: 'Read supervisor queues and silenced jobs straight out of your Horizon config.',
            },
            {
                file: '12-connection-aliasing',
                text: 'Connection aliasing',
                blurb: 'Collapse dispatcher/worker connection drift onto one canonical key.',
            },
            {
                file: '13-prometheus',
                text: 'Prometheus',
                blurb: 'The opt-in /metrics endpoint, the metric catalogue, and the push gateway command.',
            },
            {
                file: '14-scheduler',
                text: 'Scheduler observability',
                blurb: 'Per-task snapshots, per-run records, missed and hung detection, and retention.',
            },
        ],
    },
    {
        text: 'Reference',
        pages: [
            {
                file: '15-configuration',
                text: 'Configuration reference',
                blurb: 'Every key in config/queue-insights.php, with defaults and what each one changes.',
            },
        ],
    },
]

/** Flat reading order — drives rewrites, the sidebar, and the "Next" call to action. */
export const pages: DocPage[] = sections.flatMap(section => section.pages)

export const slug = (file: string) => file.replace(/^\d+-/, '')

export const link = (file: string) => `/${slug(file)}`
