<?php

declare(strict_types=1);

namespace Core45\AiProducts;

use Illuminate\Support\ServiceProvider;

/**
 * ai-products package provider (D11): the product/category rewrite services
 * and translation jobs. Ships services + jobs only (D7) — the host keeps all
 * Filament/Livewire UI, resolves the services out of the container by class
 * name, and owns every product model these services operate on (see README,
 * "Host requirements").
 *
 * This package owns no schema, no config, no routes and no views, so there is
 * deliberately nothing to register or boot.
 */
class AiProductsServiceProvider extends ServiceProvider
{
    //
}
