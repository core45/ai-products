<?php

declare(strict_types=1);

namespace Core45\AiProducts;

use Core45\AiCore\Support\PromptKeyRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * ai-products package provider (D11): the product/category rewrite services
 * and translation jobs. Ships services + jobs only (D7) — the host keeps all
 * Filament/Livewire UI, resolves the services out of the container by class
 * name, and owns every product model these services operate on (see README,
 * "Host requirements").
 *
 * This package owns no schema, no config, no routes and no views. What it does
 * own is its four prompt keys, which is why this provider is no longer empty:
 * `ai-core` used to enumerate them, and that made the package everything else
 * depends on aware of the product taxonomy (plan §9 D14).
 */
class AiProductsServiceProvider extends ServiceProvider
{
    /**
     * The prompt keys this package's rewrite services ask for, from the
     * `getPromptOverrideKey()` / `getSeoPromptOverrideKey()` implementations in
     * `ProductContentRewriteService` and `ProductCategoryContentRewriteService`.
     *
     * `product_rewrite_system` carries `warning` where its three siblings carry
     * the default. That asymmetry is inherited verbatim from the `match` this
     * replaced — see the note in `AiCoreServiceProvider::registerOwnPromptKeys()`.
     * It looks like drift, but changing it here would restyle the admin table as
     * a side effect of a refactor, so it is preserved.
     */
    public function boot(): void
    {
        $registry = $this->app->make(PromptKeyRegistry::class);

        $registry->registerMany([
            'product_rewrite_system' => [
                'label' => 'Product Content Rewrite System Prompt',
                'color' => 'warning',
                'template' => 'rewrite.field_rewrite',
            ],
            'product_category_rewrite_system' => [
                'label' => 'Product Category Content Rewrite System Prompt',
                'template' => 'rewrite.field_rewrite',
            ],
            'product_seo_system' => [
                'label' => 'Product SEO Generation System Prompt',
                'template' => 'rewrite.seo_field',
            ],
            'product_category_seo_system' => [
                'label' => 'Product Category SEO Generation System Prompt',
                'template' => 'rewrite.seo_field',
            ],
        ]);
    }
}
