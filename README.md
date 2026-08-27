# core45/ai-products

Product-domain AI content: `ProductContentRewriteService`,
`ProductCategoryContentRewriteService`, and the `TranslateProductToLocale` /
`TranslateProductCategoryToLocale` jobs.

Part of the phaseb AI extraction. As-built reference: `docs/AI_PACKAGES.md`.

## Host coupling

The drop-in refactor put every host model behind `Core45\AiCore\ModelRegistry`
and the seam contracts in `ai-core`'s `Contracts/` — this package imports zero
`App\*` classes. `tests/PackageWiringTest.php` asserts that count is exactly
zero (`ALLOWED_HOST_IMPORTS = []`); it may only stay at zero or shrink further,
never widen without a documented reason in `docs/AI_PACKAGES.md`.

That does not make the package pluggable with no host work: it still expects
five host models registered under specific `ModelRegistry` keys and four seam
contracts bound — see "Wiring it up in a fresh app" below.

## Requirements

PHP 8.4+, Laravel 13, `core45/ai-core: ^0.1`, `filament/filament` (admin
notifications raised by the translation jobs).

## What this package provides

- `Services\ProductContentRewriteService` — extends
  `Core45\AiCore\Services\AbstractContentRewriteService`; also handles product
  specifications, user manuals and AI media generation.
- `Services\ProductCategoryContentRewriteService` — same base, category fields.
- `Jobs\TranslateProductToLocale`, `Jobs\TranslateProductCategoryToLocale` —
  queued per-locale translation with `TranslationLock` guarding concurrent runs.

Ships services and jobs only (D7): the host keeps the Filament bulk actions and
table buttons and resolves these classes from the container.

**No tables, no config, no routes, no views** — the provider has nothing to
register. The `ai_*` schema ships with `core45/ai-core`.

## Host requirements

The consuming app must define its own `Product`, `ProductTranslation`,
`ProductCategory` and `ProductCategoryTranslation` models (plus `User`) with
the translation relationships `AbstractContentRewriteService` expects
(`astrotomic/laravel-translatable`), register them with `ModelRegistry`, and
bind ai-core's seam contracts. Full detail below.

## Wiring it up in a fresh app

Three call sites: what the host must provide, the config, and how a caller
actually reaches the services and jobs. Every snippet here is copy-pasteable
into an app that has never seen phaseb — nothing below references a phaseb
class by anything other than name-as-example.

### (a) What the host must provide

**Models.** `Product`, `ProductCategory` and their translation models must
implement `Astrotomic\Translatable\Contracts\Translatable` — both services
guard on `instanceof Translatable` before doing anything
(`ProductContentRewriteService.php:74`, `ProductCategoryContentRewriteService.php:38`).
`Product` and `ProductCategory` are also expected to answer
`resolveContentArchetype(): ?Model` — checked with `method_exists()` before
being called (`ProductContentRewriteService.php:487`,
`ProductCategoryContentRewriteService.php:406`), so a model without it degrades
to "no archetype" instead of fataling.

**`config('ai.models')` keys**, resolved via
`app(ModelRegistry::class)->getModelClassOrFail($key)`
(`ProductContentRewriteService.php:226,231`,
`ProductCategoryContentRewriteService.php:188,193`,
`TranslateProductToLocale.php:59,239`):

| Key | Expected class |
| --- | --- |
| `product` | Your `Product` model |
| `product_translation` | Your `ProductTranslation` model |
| `category` | Your `ProductCategory` model |
| `product_category_translation` | Your `ProductCategoryTranslation` model |
| `translation_lock` | `Core45\AiCore\Models\TranslationLock` — ships with ai-core, just register it |
| `user` | Your `User` model |

**Seam contracts.** `AbstractContentRewriteService::processModel()` — the
method both `processProduct()` and `processCategory()` delegate to — calls
`app(GeneratesSlugs::class)`, `app(StoresFocusKeyphrases::class)` and
`app(EnrichesCitations::class)` **unconditionally**
(`AbstractContentRewriteService.php:2024,530,450`); none of the three has a
default binding in `ai-core`'s service provider, so a fresh app without these
bound gets a "target is not instantiable" container exception the first time
it rewrites or translates a product or category. `ChecksContentSimilarity` is
only resolved when a caller opts in via `withMediaGeneration()`'s sibling,
`withSimilarityChecking()` (`AbstractContentRewriteService.php:324`).
`GeneratesContentMedia` is only resolved when `withMediaGeneration(['enabled'
=> true, ...])` was called first (`ProductContentRewriteService.php:670`) — omit
the binding if you never turn media generation on.

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\ProductTranslation;
use App\Models\User;
use App\Services\AI\CitationEnrichmentService; // your GEO/citation lookup, or a no-op stub
use App\Services\AI\FocusKeyphraseStore;        // writes to your SEO-indexable table
use App\Services\AI\SlugGenerator;              // wraps Illuminate\Support\Str::slug() + uniqueness
use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Contracts\StoresFocusKeyphrases;
use Core45\AiCore\Models\TranslationLock;
use Illuminate\Support\ServiceProvider;

class AiSeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Slug generation for translated names (unconditional — see above).
        $this->app->bind(GeneratesSlugs::class, SlugGenerator::class);

        // Focus-keyphrase persistence for SEO-indexable models (unconditional).
        $this->app->bind(StoresFocusKeyphrases::class, FocusKeyphraseStore::class);

        // GEO citation enrichment; a no-op implementation (supports() => false)
        // is fine if you don't run GEO citation enrichment.
        $this->app->bind(EnrichesCitations::class, CitationEnrichmentService::class);
    }

    public function boot(): void
    {
        config([
            'ai.models' => array_merge(config('ai.models', []), [
                'product' => Product::class,
                'product_translation' => ProductTranslation::class,
                'category' => ProductCategory::class,
                'product_category_translation' => ProductCategoryTranslation::class,
                'translation_lock' => TranslationLock::class,
                'user' => User::class,
            ]),
        ]);
    }
}
```

### (b) Config

The rewrite pipeline reads these keys (all under the `ai-core`-owned `ai`
namespace) with the shown fallbacks
(`ProductContentRewriteService.php:393`,
`AbstractContentRewriteService.php:774,778,1258,1907,1953`):

```php
// config/ai.php
return [
    // ... 'models' from the service provider above are merged in too ...

    'assistant' => [
        'content' => [
            'brand_voice' => 'Acme Outdoor Gear — friendly, expert, safety-conscious.',
            'seo_context_limit' => 5000,
        ],
        'translation' => [
            'server_side_chunking' => false,
            'chunk_threshold' => 12000,
        ],
        'circuit_breaker' => [
            'max_ai_calls_per_job' => 150,
        ],
        'timeout' => 300,
    ],
];
```

`Phase 3c` of `processModel()` also reads `config('geo.citation.enabled')` and
`config('geo.citation.in_rewrite_pipeline')` to decide whether to call the
bound `EnrichesCitations` implementation (`AbstractContentRewriteService.php:450`).
No package publishes a `config/geo.php`, so on a fresh app both are `null`
(falsy) and citation enrichment is simply skipped — but `EnrichesCitations`
still has to be bound, because `app(EnrichesCitations::class)` runs before that
check.

### (c) Actually calling it

Resolving the service directly and rewriting one product:

```php
use App\Models\Product;
use Core45\AiProducts\Services\ProductContentRewriteService;

$product = Product::findOrFail($id);

/** @var ProductContentRewriteService $service */
$service = app(ProductContentRewriteService::class);

$result = $service->processProduct($product);
// $result: ['model_id' => int, 'processed_fields' => string[], 'errors' => string[]]
```

Enabling AI media generation for that same run
(`ProductContentRewriteService.php:57`) requires binding
`Core45\AiCore\Contracts\GeneratesContentMedia` first:

```php
$service->withMediaGeneration(['enabled' => true, 'target' => 'gallery'])
    ->processProduct($product);
```

Dispatching the queued translation job
(`Core45\AiProducts\Jobs\TranslateProductToLocale`,
`TranslateProductCategoryToLocale.php` is identical with a `$category`
parameter):

```php
use App\Models\Product;
use Core45\AiProducts\Jobs\TranslateProductToLocale;
use Illuminate\Support\Facades\Auth;

TranslateProductToLocale::dispatch(
    product: Product::findOrFail($id),
    sourceLocale: 'en',
    targetLocale: 'es',
    userId: Auth::id(),
);
```

The job runs on the `ai-bulk` queue, retries up to 10 times with a `[10, 30,
60]`-second backoff, and notifies the dispatching user (via the bound
`NotifiesOperator` — `ai-core` defaults this to a log-only implementation, see
the ai-core README) on completion or permanent failure
(`TranslateProductToLocale.php:25-45,144-152`).

A Filament bulk action wiring the job the same way `ai-posts-filament` wires
its own rewrite action (`ai-posts-filament/src/Actions/AiRewriteContentAction.php`):

```php
use Core45\AiProducts\Jobs\TranslateProductToLocale;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

BulkAction::make('translate_to_spanish')
    ->label(__('Translate to Spanish'))
    ->icon(Heroicon::OutlinedLanguage)
    ->requiresConfirmation()
    ->action(function (Collection $records): void {
        foreach ($records as $product) {
            TranslateProductToLocale::dispatch($product, 'en', 'es', Auth::id());
        }
    });
```

## Testing

```bash
vendor/bin/phpunit -c packages/ai-products/phpunit.xml.dist
```

Orchestra Testbench, ai-core + ai-products only.
