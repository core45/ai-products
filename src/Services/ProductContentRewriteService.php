<?php

declare(strict_types=1);

namespace Core45\AiProducts\Services;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\GeneratesContentMedia;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiOperationAlreadyCompletedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AbstractContentRewriteService;
use Illuminate\Database\Eloquent\Model;

/**
 * AI content rewrite service for Products.
 *
 * Extends AbstractContentRewriteService to provide Product-specific implementations
 * for content rewriting, translation, and SEO generation.
 *
 * Also handles optional fields (specifications, user_manual, etc.) via the
 * processOptionalFields hook method.
 */
class ProductContentRewriteService extends AbstractContentRewriteService
{
    /** @var array<string, mixed> Media generation options */
    private array $mediaOptions = [];

    /**
     * Optional fields that are only processed if source content exists.
     */
    private const OPTIONAL_FIELDS = [
        'specifications' => 'rich',
        'user_manual' => 'rich',
        'on_demand_description' => 'rich',
        'extra_info_title' => 'plain',
        'extra_info_description' => 'rich',
        'gspr' => 'rich',
    ];

    /**
     * Character limits for fields stored in varchar columns.
     */
    private const FIELD_CHARACTER_LIMITS = [
        'extra_info_title' => 255,
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Set media generation options for the next processing run.
     *
     * @param  array<string, mixed>  $options
     */
    public function withMediaGeneration(array $options): self
    {
        $this->mediaOptions = $options;

        return $this;
    }

    /**
     * Process a product - rewrite/translate all content and SEO fields.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function processProduct(Model $product): array
    {
        // The package never imports the host's Product class (§3 seam) — guard
        // the Astrotomic-dependent parent algorithm at this boundary, following
        // the precedent at DetectionPollService.php:260.
        if (!$product instanceof Translatable) {
            throw new \InvalidArgumentException(sprintf(
                'Model [%s] must implement %s to be processed by %s.',
                $product::class,
                Translatable::class,
                self::class,
            ));
        }

        return $this->processModel($product);
    }

    /**
     * Translate all translatable fields of a product from one locale to another.
     *
     * Translates name, slug, summary, description, optional fields, and SEO fields using AI.
     * Skips fields that already have content in the target locale unless $overwrite is true.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function translateToLocale(Model $product, string $sourceLocale, string $targetLocale, bool $overwrite = false): array
    {
        /** @phpstan-ignore property.notFound */
        $modelId = (int) $product->id;

        $results = [
            'model_id' => $modelId,
            'processed_fields' => [],
            'errors' => [],
        ];

        // The package never imports the host's Product class (§3 seam) — guard
        // the Astrotomic calls below at this boundary instead, following the
        // precedent at DetectionPollService.php:260.
        if (!$product instanceof Translatable) {
            $results['errors'][] = sprintf('Model [%s] does not support translations.', $product::class);

            return $results;
        }

        $this->logRequest($this->getServiceName().' - translateToLocale', [
            'model_id' => $modelId,
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'overwrite' => $overwrite,
        ]);

        $sourceTranslation = $product->translate($sourceLocale);
        if ($sourceTranslation === null || empty($sourceTranslation->name)) {
            $results['errors'][] = "No source content found for locale {$sourceLocale}";

            return $results;
        }

        // Core fields: name, summary, description + SEO
        $allFields = array_merge(
            ['name' => 'plain', 'summary' => 'plain'],
            ['description' => 'rich'],
            $this->getSeoFields(),
        );

        foreach ($allFields as $field => $type) {
            if ($this->isCancelled()) {
                break;
            }

            $sourceValue = (string) ($sourceTranslation->{$field} ?? '');
            if ($sourceValue === '') {
                continue;
            }

            $targetTranslation = $product->translate($targetLocale);
            if (!$overwrite && $targetTranslation !== null && !empty($targetTranslation->{$field})) {
                continue;
            }

            try {
                // Deterministic per-field operation id derived from the job's
                // operationId (audit 5.4) — retries replay the same id so
                // phasea's usage metering does not re-bill already-translated
                // fields. Falls back to per-call UUIDs when no id was threaded.
                /** @var string $translated */
                $translated = $this->withScopedOperationId(
                    "field:{$field}",
                    fn (): string => $this->translateText($sourceValue, $sourceLocale, $targetLocale, $type),
                );
                $this->saveTranslation($product, $field, $targetLocale, $translated);
                $results['processed_fields'][] = "{$field}:{$targetLocale}";
            } catch (AiBudgetExceededException $e) {
                // Budget exhaustion is tenant-global — fail fast so the job
                // fails immediately instead of burning retries (audit §6).
                throw $e;
            } catch (AiOperationAlreadyCompletedException $e) {
                // A retry re-sent a field a prior attempt already translated under the
                // same deterministic id — reconcile as done, not failed.
                $this->reconcileAlreadyCompletedField($product, $field, $targetLocale, $results, $e);
            } catch (\Exception $e) {
                $results['errors'][] = "{$field}:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
                $this->logError($this->getServiceName().' - translateToLocale', $e);
            }
        }

        // Generate slug from translated name
        if (in_array("name:{$targetLocale}", $results['processed_fields'], true)) {
            try {
                $product->refresh();
                $translatedName = $product->translate($targetLocale)->name ?? '';
                if ($translatedName !== '') {
                    $slug = $this->generateUniqueSlug($translatedName, $modelId, $targetLocale);
                    $this->saveTranslation($product, 'slug', $targetLocale, $slug);
                    $results['processed_fields'][] = "slug:{$targetLocale}";
                }
            } catch (\Exception $e) {
                $results['errors'][] = "slug:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
            }
        }

        // Translate optional fields (specifications, user_manual, etc.)
        $this->translateOptionalFields($product, $sourceLocale, $targetLocale, $overwrite, $results);

        $product->refresh();

        $this->logResponse($this->getServiceName().' - translateToLocale', $results);

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ABSTRACT METHOD IMPLEMENTATIONS
    // ═══════════════════════════════════════════════════════════════════════

    protected function getContentFields(): array
    {
        return [
            'summary' => 'plain',
            'description' => 'rich',
        ];
    }

    protected function getSeoFields(): array
    {
        return [
            'seo_title' => 'plain',
            'seo_description' => 'plain',
            'seo_keywords' => 'plain',
        ];
    }

    protected function getModelClass(): string
    {
        // ModelRegistry::getModelClassOrFail() is typed `string`, not
        // `class-string<Model>` (§3 seam lives in ai-core, out of scope here).
        return app(ModelRegistry::class)->getModelClassOrFail('product');
    }

    protected function getTranslationClass(): string
    {
        return app(ModelRegistry::class)->getModelClassOrFail('product_translation');
    }

    /**
     * Override the property default: the touchable class must come from
     * `ModelRegistry`, and a property initializer cannot call `app()`.
     *
     * @return array<int, class-string<Model>>
     */
    protected function getTouchableModelClasses(): array
    {
        return [app(ModelRegistry::class)->getModelClassOrFail('product')];
    }

    protected function getServiceName(): string
    {
        return 'ProductContentRewrite';
    }

    protected function getContentTypeLabel(): string
    {
        return 'products';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.field_rewrite` and
     * `rewrite.field_generate` templates select this subclass's field
     * descriptions with (v2.6 design §2.5). It replaces the already-resolved
     * `field_description` string plan 48-05 sent as a temporary shape.
     */
    protected function getContentTypeKey(): string
    {
        return 'product';
    }

    protected function getPromptOverrideKey(): string
    {
        return 'product_rewrite_system';
    }

    protected function getSeoPromptOverrideKey(): string
    {
        return 'product_seo_system';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.seo_field` template
     * selects this subclass's instruction with (v2.6 design §2.5). It replaces
     * `buildSeoFieldPrompt()`, whose three branches were transcribed verbatim
     * into that template's variant table.
     */
    protected function getSeoContentTypeKey(): string
    {
        return 'product';
    }

    protected function buildModelContext(Model $model): array
    {
        $model->load(['brand.translations', 'categories.translations', 'tags']);

        // The package never imports the host's Product class (§3 seam), so these
        // Product-only attributes are read off the widened Model one at a
        // time and ignored individually rather than typed away.
        /** @phpstan-ignore property.notFound */
        $productId = $model->id;
        /** @phpstan-ignore property.notFound */
        $sku = $model->sku;
        /** @phpstan-ignore property.notFound */
        $price = $model->price;
        /** @phpstan-ignore property.notFound */
        $tags = $model->tags;
        /** @phpstan-ignore property.notFound */
        $isVirtual = $model->is_virtual;
        /** @phpstan-ignore property.notFound */
        $onDemand = $model->on_demand;

        $context = [
            'product_id' => $productId,
            'sku' => $sku,
            'price' => $price ? number_format($price / 100, 2).' '.config('money.defaultCurrency', 'EUR') : null,
            'brand' => [],
            'categories' => [],
            'tags' => $tags->pluck('name')->toArray(),
            'is_virtual' => $isVirtual,
            'on_demand' => $onDemand,
            'translations' => [],
        ];

        // Collect brand names in all locales
        /** @phpstan-ignore property.notFound */
        $brand = $model->brand;
        if ($brand) {
            foreach ($this->getLocales() as $locale) {
                $brandTranslation = $brand->translate($locale);
                if ($brandTranslation?->name) {
                    $context['brand'][$locale] = $brandTranslation->name;
                }
            }
        }

        // Collect category names in all locales
        /** @phpstan-ignore property.notFound */
        $categories = $model->categories;
        foreach ($categories as $category) {
            foreach ($this->getLocales() as $locale) {
                $catTranslation = $category->translate($locale);
                if ($catTranslation?->name) {
                    $context['categories'][$locale][] = $catTranslation->name;
                }
            }
        }

        // Collect existing translations for context
        if ($model instanceof Translatable) {
            foreach ($this->getLocales() as $locale) {
                $translation = $model->translate($locale);
                if ($translation) {
                    /** @phpstan-ignore property.notFound */
                    $name = $translation->name;
                    /** @phpstan-ignore property.notFound */
                    $summary = $translation->summary;
                    /** @phpstan-ignore property.notFound */
                    $description = $translation->description;

                    $context['translations'][$locale] = [
                        'name' => $name,
                        'summary' => $summary,
                        'description' => $this->truncateForContext($description),
                    ];
                }
            }
        }

        return $context;
    }

    protected function getFieldDescription(string $field): string
    {
        return match ($field) {
            'summary' => 'a concise product summary (2-3 sentences highlighting key benefits)',
            'description' => 'a detailed product description (3-5 paragraphs covering features, benefits, and use cases)',
            'seo_title' => 'an SEO title (max 60 characters, include main keyword)',
            'seo_description' => 'an SEO meta description (max 160 characters, compelling call-to-action)',
            'seo_keywords' => 'SEO keywords (5-10 relevant keywords, comma-separated)',
            'specifications' => 'technical specifications in a structured format',
            'user_manual' => 'user instructions or manual content',
            'on_demand_description' => 'description for on-demand/custom order information',
            'extra_info_title' => 'a title for additional product information section',
            'extra_info_description' => 'additional product information content',
            'gspr' => 'General Safety and Product Regulations compliance information',
            default => "content for the {$field} field",
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildSeoContext(Model $model, string $locale, array $context): array
    {
        $translation = $model instanceof Translatable ? $model->translate($locale) : null;

        $seoContextLimit = (int) config('ai.assistant.content.seo_context_limit', 5000);

        return [
            'name' => $translation->name ?? '',
            'summary' => $translation->summary ?? '',
            'description' => $this->truncateForContext($translation->description ?? null, $seoContextLimit),
            'sku' => $context['sku'] ?? '',
            'price' => $context['price'] ?? '',
            'brand' => $context['brand'][$locale]
                ?? $context['brand'][array_key_first($context['brand'] ?? [])]
                ?? '',
            'categories' => $context['categories'][$locale]
                ?? $context['categories'][array_key_first($context['categories'] ?? [])]
                ?? [],
            'tags' => $context['tags'] ?? [],
            'is_virtual' => $context['is_virtual'] ?? false,
        ];
    }

    /**
     * Handle name/slug generation for a product.
     *
     * @param  array<string, mixed>  $context
     */
    protected function handleNameSlug(Model $model, string $locale, array $context): void
    {
        if (!$model instanceof Translatable) {
            return;
        }

        $translation = $model->translate($locale);
        $hasName = $translation && !empty($translation->name);
        $hasSlug = $translation && !empty($translation->slug);

        if ($hasName && $hasSlug) {
            return;
        }

        $name = null;

        if (!$hasName) {
            $name = $this->generateOrTranslateName($model, $locale, $context);
        } else {
            $name = $translation->name;
        }

        /** @phpstan-ignore property.notFound */
        $modelId = (int) $model->id;
        $slug = $hasSlug ? $translation->slug : $this->generateUniqueSlug($name, $modelId, $locale);

        $this->saveTranslation($model, 'name', $locale, $name);
        $this->saveTranslation($model, 'slug', $locale, $slug);
    }

    protected function findSourceLocale(Model $model, string $field): ?string
    {
        if (!$model instanceof Translatable) {
            return null;
        }

        foreach ($this->getLocales() as $locale) {
            $translation = $model->translate($locale);
            if ($translation && !empty($translation->{$field})) {
                return $locale;
            }
        }

        return null;
    }

    protected function slugExistsForLocale(string $slug, int $excludeId, string $locale): bool
    {
        $translationClass = app(ModelRegistry::class)->getModelClassOrFail('product_translation');

        return $translationClass::where('slug', $slug)
            ->where('locale', $locale)
            ->where('product_id', '!=', $excludeId)
            ->exists();
    }

    /**
     * Resolve the content archetype for a product.
     *
     * Uses the Product's HasContentArchetype trait to resolve:
     * 1. Direct assignment on product
     * 2. Category default
     * 3. System default for Product type
     *
     * The host's HasContentArchetype trait is not exposed via a package-visible
     * contract, so it is called dynamically here — this package never imports
     * the host's Product class.
     */
    protected function resolveArchetype(Model $model): ?Model
    {
        if (!method_exists($model, 'resolveContentArchetype')) {
            return null;
        }

        $archetype = $model->resolveContentArchetype();

        return $archetype instanceof Model ? $archetype : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // OPTIONAL FIELDS PROCESSING (Hook Override)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process optional fields (specifications, user_manual, etc.).
     * Only processes fields that have source content in at least one locale.
     *
     * @param  array<string>  $locales
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $results
     */
    protected function processOptionalFields(Model $model, array $locales, array $context, array &$results): void
    {
        if (!$model instanceof Translatable) {
            return;
        }

        $product = $model;

        $processedExtraInfoFields = false;

        foreach (self::OPTIONAL_FIELDS as $field => $type) {
            $sourceLocale = $this->findSourceLocale($product, $field);

            if (!$sourceLocale) {
                continue;
            }

            foreach ($locales as $locale) {
                try {
                    if ($locale === $sourceLocale) {
                        $content = $this->rewriteField($product, $field, $locale, $type, $context);
                    } else {
                        $content = $this->translateField($product, $field, $sourceLocale, $locale, $type, $context);
                    }
                    $content = $this->enforceFieldCharacterLimit($field, $content);
                    $this->saveTranslation($product, $field, $locale, $content);
                    $results['processed_fields'][] = "{$field}:{$locale}";

                    // Track if extra_info fields were processed
                    if (in_array($field, ['extra_info_title', 'extra_info_description'], true)) {
                        $processedExtraInfoFields = true;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = "{$field}:{$locale} - ".$this->sanitizeErrorMessage($e);
                    $this->logError($this->getServiceName().' - optionalField', $e);
                }
            }
        }

        // Enable extra_info_show if extra_info fields were processed
        /** @phpstan-ignore property.notFound */
        $extraInfoShown = (bool) $product->extra_info_show;
        if ($processedExtraInfoFields && !$extraInfoShown) {
            $product->extra_info_show = true;
            $product->save();
            $results['processed_fields'][] = 'extra_info_show:enabled';
        }
    }

    /**
     * Translate optional fields from source to target locale.
     * Only processes fields that have source content.
     *
     * @param  array{model_id: int, processed_fields: array<string>, errors: array<string>}  $results
     */
    private function translateOptionalFields(Model $product, string $sourceLocale, string $targetLocale, bool $overwrite, array &$results): void
    {
        if (!$product instanceof Translatable) {
            return;
        }

        $sourceTranslation = $product->translate($sourceLocale);
        $processedExtraInfoFields = false;

        foreach (self::OPTIONAL_FIELDS as $field => $type) {
            $sourceValue = (string) ($sourceTranslation->{$field} ?? '');
            if ($sourceValue === '') {
                continue;
            }

            $targetTranslation = $product->translate($targetLocale);
            if (!$overwrite && $targetTranslation !== null && !empty($targetTranslation->{$field})) {
                continue;
            }

            try {
                /** @var string $translated */
                $translated = $this->withScopedOperationId(
                    "field:{$field}",
                    fn (): string => $this->translateText($sourceValue, $sourceLocale, $targetLocale, $type),
                );
                $translated = $this->enforceFieldCharacterLimit($field, $translated);
                $this->saveTranslation($product, $field, $targetLocale, $translated);
                $results['processed_fields'][] = "{$field}:{$targetLocale}";

                if (in_array($field, ['extra_info_title', 'extra_info_description'], true)) {
                    $processedExtraInfoFields = true;
                }
            } catch (AiBudgetExceededException $e) {
                // Budget exhaustion is tenant-global — fail fast (audit §6).
                throw $e;
            } catch (\Exception $e) {
                $results['errors'][] = "{$field}:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
                $this->logError($this->getServiceName().' - translateOptionalField', $e);
            }
        }

        /** @phpstan-ignore property.notFound */
        $extraInfoShown = (bool) $product->extra_info_show;
        if ($processedExtraInfoFields && !$extraInfoShown) {
            $product->extra_info_show = true;
            $product->save();
            $results['processed_fields'][] = 'extra_info_show:enabled';
        }
    }

    /**
     * Enforce character limits for fields stored in varchar columns.
     */
    private function enforceFieldCharacterLimit(string $field, string $content): string
    {
        if (!isset(self::FIELD_CHARACTER_LIMITS[$field])) {
            return $content;
        }

        $maxLength = self::FIELD_CHARACTER_LIMITS[$field];

        if (mb_strlen($content) <= $maxLength) {
            return $content;
        }

        // Truncate at word boundary to avoid cutting words in half
        $truncated = mb_substr($content, 0, $maxLength);

        // Find last space to avoid cutting mid-word
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.7) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        // Remove trailing punctuation that looks incomplete
        $truncated = rtrim($truncated, ' .,;:-');

        return $truncated;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MEDIA GENERATION (Phase 5 Hook Override)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if media generation is enabled for the current run.
     */
    private function isMediaGenerationEnabled(): bool
    {
        return !empty($this->mediaOptions) && ($this->mediaOptions['enabled'] ?? false);
    }

    /**
     * Process AI image generation for a product (Phase 5).
     *
     * @param  array<string, mixed>  $results
     */
    protected function processMediaGeneration(Model $model, array &$results): void
    {
        if (!$this->isMediaGenerationEnabled()) {
            return;
        }

        $model->load(['brand.translations', 'categories.translations']);

        try {
            $service = app(GeneratesContentMedia::class);
            $service->processMediaGeneration($model, array_merge($this->mediaOptions, [
                'rich_text_field' => 'description',
                'gallery_collection' => config('media.gallery.collection', 'photos'),
            ]));
            $results['processed_fields'][] = 'media_generation';
        } catch (\Exception $e) {
            $results['errors'][] = 'media_generation - '.$this->sanitizeErrorMessage($e);
            $this->logError($this->getServiceName().' - processMediaGeneration', $e);
        }
    }

    /**
     * The image-marker GATING decision, shared by the v2.6 parameter path and by
     * the legacy `getImageMarkerInstructions()` below, so the condition has one
     * home per subclass and cannot drift between them.
     */
    protected function imageMarkersEnabled(): bool
    {
        if (!$this->isMediaGenerationEnabled()) {
            return false;
        }

        return ($this->mediaOptions['target'] ?? 'gallery') !== 'gallery';
    }

    /**
     * Which phasea marker variant a product renders.
     */
    protected function imageMarkerVariant(): ?string
    {
        return 'product';
    }

    /**
     * Get [IMAGE:prompt] marker instructions for rich content descriptions.
     *
     * D-48-4 — TRANSLATE ONLY. See the twin in `PostContentRewriteService`: the
     * only remaining caller is `getFormatInstructions()` <-
     * `buildTranslatePrompt()`, the translation carve-out owned by phases 41–43.
     */
    protected function getImageMarkerInstructions(): string
    {
        if (!$this->imageMarkersEnabled()) {
            return '';
        }

        return <<<'INSTRUCTIONS'
- IMAGE MARKERS: Insert exactly 2 image markers in the description at natural visual break points.
  Use this exact format: [IMAGE:descriptive prompt for the image]
  The prompt should describe a product photo that illustrates the surrounding text.
  Example: [IMAGE:professional photo of industrial cable connector with gold-plated terminals on white background]
  Place markers between paragraphs, NOT inside HTML tags.
INSTRUCTIONS;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate or translate name from source locale.
     *
     * @param  array<string, mixed>  $context
     */
    private function generateOrTranslateName(Model $product, string $locale, array $context): string
    {
        $sourceLocale = $this->findSourceLocale($product, 'name');

        if ($sourceLocale && $sourceLocale !== $locale && $product instanceof Translatable) {
            /** @phpstan-ignore property.notFound */
            $sourceName = (string) $product->translate($sourceLocale)?->name;

            return $this->translateText($sourceName, $sourceLocale, $locale);
        }

        return $this->generateNameFromContext($locale, $context);
    }

    /**
     * Generate name from context when no source exists.
     *
     * @param  array<string, mixed>  $context
     */
    private function generateNameFromContext(string $locale, array $context): string
    {
        $parts = [];

        // Add brand if available
        $brand = $context['brand'][$locale] ?? $context['brand'][array_key_first($context['brand'] ?? [])] ?? null;
        if ($brand) {
            $parts[] = $brand;
        }

        // Add first category if available
        $categories = $context['categories'][$locale] ?? $context['categories'][array_key_first($context['categories'] ?? [])] ?? [];
        if (!empty($categories)) {
            $parts[] = $categories[0];
        }

        // Add SKU for uniqueness
        if ($context['sku']) {
            $parts[] = $context['sku'];
        }

        if (empty($parts)) {
            $parts[] = 'Product '.$context['product_id'];
        }

        return implode(' ', $parts);
    }
}
