# core45/ai-products

Product-domain AI content: `ProductContentRewriteService`,
`ProductCategoryContentRewriteService`, and the `TranslateProductToLocale` /
`TranslateProductCategoryToLocale` jobs.

Part of the phaseb AI extraction. As-built reference: `docs/AI_PACKAGES.md`.

## ⚠️ Not reusable outside phaseb yet

This package imports 7 host classes: `App\Models\Product`, `ProductCategory`,
`ProductTranslation`, `ProductCategoryTranslation`, `ContentArchetype`,
`TranslationLock`, `User`. It installs in another Laravel app and then fatals as
soon as it touches one of them. Organised as a package, not yet portable — see
`docs/AI_PACKAGES.md`, "Known limitation".

`tests/PackageWiringTest.php` pins that list exactly. The list may only shrink.

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

The consuming app must define the seven models listed above, with the
translation relationships `AbstractContentRewriteService` expects
(`astrotomic/laravel-translatable`), and must bind ai-core's seams (see the
ai-core README).

## Testing

```bash
vendor/bin/phpunit -c packages/ai-products/phpunit.xml.dist
```

Orchestra Testbench, ai-core + ai-products only.
