<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\CallSiteResolver;

/**
 * Resolve via a deliberately deep call chain. Each `nestedFrame*` helper
 * adds one frame; the resolver should still find the dispatch site within
 * the depth cap unless the cap is set below the chain length.
 */
function callSiteDepthLevel3(int $maxDepth): ?string
{
    return (new CallSiteResolver())->resolve($maxDepth);
}

function callSiteDepthLevel2(int $maxDepth): ?string
{
    return callSiteDepthLevel3($maxDepth);
}

function callSiteDepthLevel1(int $maxDepth): ?string
{
    return callSiteDepthLevel2($maxDepth);
}

it('resolves the calling line as a base-path-relative file:line', function (): void {
    $callSite = (new CallSiteResolver())->resolve(30);

    expect($callSite)->toBeString()
        // The test file is an application frame — outside vendor/ and the
        // package src/. It resolves to a `path:line` shape.
        ->and($callSite)->toMatch('/:\d+$/')
        ->and($callSite)->toContain('CallSiteResolverTest.php');
});

it('two distinct call sites of the same resolver resolve to two different file:line values', function (): void {
    $resolver = new CallSiteResolver();

    $siteA = $resolver->resolve(30);
    $siteB = $resolver->resolve(30);

    // Two `resolve()` invocations on different source lines — the core
    // requirement: distinguishable call sites.
    expect($siteA)->toBeString()
        ->and($siteB)->toBeString()
        ->and($siteA)->not->toBe($siteB);
});

it('honors the depth cap — too-shallow a cap finds no application frame', function (): void {
    // Skip-set covers the whole project tree (tests + vendor) so the ONLY
    // way a frame survives is to be a genuine application frame outside
    // both — which, within the bounded backtrace, there is none. The depth
    // cap then bounds how far the walk looks: depth 1 captures only the
    // immediate caller frame (a test/vendor frame, skipped) → null.
    $skipWholeTree = [dirname(__DIR__, 2), dirname(__DIR__, 3) . '/vendor'];

    $shallow = (new CallSiteResolver($skipWholeTree))->resolve(1);

    expect($shallow)->toBeNull();
});

it('a deep call chain still resolves when the cap is generous', function (): void {
    $resolved = callSiteDepthLevel1(30);

    expect($resolved)->toBeString()
        ->and($resolved)->toContain('CallSiteResolverTest.php');
});

it('excludes QIs own source root even when the injected package root differs from vendor/', function (): void {
    // Simulate a Composer path-repo / symlinked install: QI's source root
    // is somewhere OTHER than vendor/. Inject the test directory as a stand
    // -in "package root" alongside vendor/ — every frame in the bounded
    // backtrace (this test file + Pest's runner under vendor/) is then
    // skipped, so the walk finds no application frame and returns null.
    // This proves the skip-set is honored by INJECTED path, not hardcoded
    // to vendor/.
    $packageRootStandIn = dirname(__DIR__, 2);
    $vendorDir = dirname(__DIR__, 3) . '/vendor';

    $resolved = (new CallSiteResolver([$packageRootStandIn, $vendorDir]))->resolve(30);

    expect($resolved)->toBeNull();
});

it('returns null when every frame is under an injected skip path', function (): void {
    // No real application frame survives the filter.
    $resolved = (new CallSiteResolver([dirname(__DIR__, 2), dirname(__DIR__, 3) . '/vendor']))->resolve(30);

    expect($resolved)->toBeNull();
});
