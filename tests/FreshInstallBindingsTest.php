<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests;

use Core45\AiCore\Contracts\GeneratesContentMedia;
use Core45\AiProducts\Services\ProductContentRewriteService;
use Illuminate\Contracts\Container\BindingResolutionException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * What `composer require core45/ai-products` gives you, before the host wires
 * anything.
 *
 * This package declares no contracts and no bindings of its own — its provider
 * only registers prompt keys. Everything it resolves comes from `ai-core`, so
 * on paper it inherits ai-core's defaults for free. "On paper" is the problem:
 * that is an argument, and the fifteen-unbound-contract bug this suite exists
 * to catch was invisible for exactly as long as nobody asked the container.
 *
 * So this asks. It does not carry a hand-written list of contracts, because a
 * hand-written list goes stale the moment someone adds an `app()` call. It
 * SCANS this package's own `src/` for container resolutions and requires every
 * one of them to be satisfiable — a gate that grows itself.
 */
class FreshInstallBindingsTest extends TestCase
{
    /**
     * Deliberately unbound, and documented as such in
     * `AiCoreServiceProvider`'s docblock: there is no honest default for
     * "generate an image", and a null one would silently produce posts with no
     * media while reporting success.
     *
     * It is safe to leave unbound only because its single call site is
     * double-guarded — see the test below.
     */
    private const DELIBERATELY_UNBOUND = [
        GeneratesContentMedia::class,
    ];

    #[Test]
    public function every_container_resolution_in_this_package_is_satisfiable(): void
    {
        $resolutions = $this->scanForContainerResolutions(__DIR__.'/../src');

        $this->assertNotSame(
            [],
            $resolutions,
            'The scanner found no container resolutions at all. That is a broken scanner, '
            .'not a clean package — this gate would then pass while measuring nothing.',
        );

        foreach ($resolutions as $class => $site) {
            if (in_array($class, self::DELIBERATELY_UNBOUND, true)) {
                continue;
            }

            try {
                $this->assertNotNull(app($class), $class);
            } catch (BindingResolutionException $e) {
                $this->fail(
                    "A fresh install cannot resolve {$class}, resolved at {$site}. "
                    .'Either bind a default in a package provider, or add it to '
                    .'self::DELIBERATELY_UNBOUND with the reason it is safe unbound. '
                    .'Container said: '.$e->getMessage(),
                );
            }
        }
    }

    /**
     * The one unbound contract is unreachable on the ordinary path.
     *
     * `processMediaGeneration()` returns before touching the container unless
     * `isMediaGenerationEnabled()` is true, and that reads `$this->mediaOptions`
     * — empty until a caller opts in via the media-generation entry point. The
     * resolution is additionally inside a `try`/`catch` that appends to
     * `$results['errors']` and logs; nothing on that path deletes or overwrites
     * host data.
     *
     * That combination is what makes leaving it unbound safe rather than a
     * latent crash, so it is asserted rather than assumed.
     */
    #[Test]
    public function the_unbound_media_contract_is_unreachable_until_a_caller_opts_in(): void
    {
        $this->assertFalse(
            app()->bound(GeneratesContentMedia::class),
            'GeneratesContentMedia is now bound by default. If that is intended, delete '
            .'this test and the DELIBERATELY_UNBOUND entry — but a default that fabricates '
            .'media while reporting success is the failure this absence prevents.',
        );

        $isEnabled = new ReflectionMethod(ProductContentRewriteService::class, 'isMediaGenerationEnabled');

        $this->assertFalse(
            $isEnabled->invoke(app(ProductContentRewriteService::class)),
            'Media generation is enabled on a freshly constructed service, so the unbound '
            .'GeneratesContentMedia resolution is now reachable on the ordinary rewrite path.',
        );
    }

    /**
     * Map every `app(Foo::class)` / `resolve(Foo::class)` in the tree to the
     * FQCN its file imported, so the assertions above run against real class
     * names rather than short ones.
     *
     * @return array<class-string, string> FQCN => the file:line it was found at
     */
    private function scanForContainerResolutions(string $dir): array
    {
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());

            // Short name => FQCN, from this file's own `use` statements.
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $imports);
            $aliases = [];
            foreach ($imports[1] as $fqcn) {
                $aliases[substr((string) strrchr('\\'.$fqcn, '\\'), 1)] = $fqcn;
            }

            $lines = explode("\n", $source);
            foreach ($lines as $index => $line) {
                if (preg_match_all('/\b(?:app|resolve)\(\s*([A-Za-z0-9_\\\\]+)::class/', $line, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $shortName) {
                    $fqcn = $aliases[$shortName] ?? $shortName;
                    if (interface_exists($fqcn) || class_exists($fqcn)) {
                        $found[$fqcn] = basename($file->getPathname()).':'.($index + 1);
                    }
                }
            }
        }

        return $found;
    }
}
