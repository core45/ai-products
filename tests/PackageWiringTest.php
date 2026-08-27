<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests;

use Core45\AiProducts\AiProductsServiceProvider;
use Core45\AiProducts\Jobs\TranslateProductCategoryToLocale;
use Core45\AiProducts\Jobs\TranslateProductToLocale;
use Core45\AiProducts\Services\ProductCategoryContentRewriteService;
use Core45\AiProducts\Services\ProductContentRewriteService;
use PHPUnit\Framework\Attributes\Test;

class PackageWiringTest extends TestCase
{
    /**
     * The host classes this package is allowed to import: none.
     *
     * This list used to name seven `App\Models\*` classes, recording the
     * documented limitation that ai-products operated directly on the consuming
     * app's product models and so was packaged but not actually reusable. That
     * is no longer true — the drop-in refactor put every one of them behind
     * `ModelRegistry` and the contracts in ai-core, and the measured count is
     * now zero.
     *
     * Kept as an exact assertion rather than deleted. A ceiling that no longer
     * binds is not a weaker gate, it is no gate: at seven it would keep passing
     * if all seven imports came back. At zero, any host import fails this test.
     *
     * If one is genuinely needed, the answer is a contract in
     * `ai-core/src/Contracts/`, not an entry added here.
     *
     * @var list<string>
     */
    private const ALLOWED_HOST_IMPORTS = [];

    #[Test]
    public function the_package_boots_standalone(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(AiProductsServiceProvider::class));
    }

    #[Test]
    public function every_shipped_class_exists(): void
    {
        foreach ([
            ProductContentRewriteService::class,
            ProductCategoryContentRewriteService::class,
            TranslateProductToLocale::class,
            TranslateProductCategoryToLocale::class,
        ] as $class) {
            $this->assertTrue(class_exists($class), "{$class} is missing.");
        }
    }

    #[Test]
    public function host_coupling_does_not_widen_beyond_the_documented_set(): void
    {
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/^use\s+(App\\\\[^\s;]+);/m', (string) file_get_contents($file->getPathname()), $matches);
            $found = array_merge($found, $matches[1]);
        }

        $found = array_values(array_unique($found));
        sort($found);

        $this->assertSame(
            self::ALLOWED_HOST_IMPORTS,
            $found,
            'ai-products host coupling changed. Shrinking it is the goal; widening it needs a documented reason recorded with the change.'
        );
    }
}
