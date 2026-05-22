<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * boost-core configuration — which AI agents `composer boost:sync` writes to,
 * which dependency vendors' shipped skills are synced, and which skill tags
 * are active.
 *
 * `withAllowedVendors()` is an explicit allowlist: a dependency's skills sync
 * ONLY if its package name is listed here.
 *
 * `withTags()` filters `sandermuller/boost-skills`: with no tags you still get
 * the universal skills; each tag adds its capability-specific set (`php` adds
 * backend-quality / pre-release / etc.; `github` adds GitHub-workflow skills).
 *
 * `withExcludedSkills()` blocks individual vendor skills. LQI ships its own
 * `pre-release` (carries LQI-specific pipeline steps: package-boost:sync,
 * CI-matrix gate, ci-matrix-troubleshooting ref) — exclude the vendor copy.
 *
 * Re-run `composer boost:install` to change agents/vendors/tags interactively,
 * or hand-edit this file; then run `composer sync-ai` (or `vendor/bin/boost
 * sync`).
 *
 * Docs: https://github.com/sandermuller/boost-core
 */
return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::COPILOT,
        Agent::CODEX,
    ])
    ->withAllowedVendors([
        'sandermuller/boost-skills',
        'sandermuller/package-boost-laravel',
        'sandermuller/package-boost-php',
    ])
    ->withTags('php', 'github')
    ->withExcludedSkills([
        'sandermuller/boost-skills:pre-release',
    ])
    ->withDisabledEmitters([]);
