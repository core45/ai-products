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
     * The host classes this package is ALLOWED to import. This is the
     * documented limitation from docs/AI_PACKAGES.md in executable form:
     * ai-products is organised as a package but is not yet reusable, because
     * it operates directly on the consuming app's product models.
     *
     * This list may only SHRINK. Widening it means the package got MORE
     * host-bound, which is the opposite of the extraction's direction.
     *
     * @var list<string>
     */
    private const ALLOWED_HOST_IMPORTS = [
        'App\Models\ContentArchetype',
        'App\Models\Product',
        'App\Models\ProductCategory',
        'App\Models\ProductCategoryTranslation',
        'App\Models\ProductTranslation',
        'App\Models\TranslationLock',
        'App\Models\User',
    ];

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
            'ai-products host coupling changed. Shrinking it is the goal; widening it needs a documented reason in docs/AI_PACKAGES.md.'
        );
    }
}
