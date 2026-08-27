<?php

declare(strict_types=1);

namespace Core45\AiProducts\Services;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiOperationAlreadyCompletedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AbstractContentRewriteService;
use Illuminate\Database\Eloquent\Model;

/**
 * AI content rewrite service for Product Categories.
 *
 * Extends AbstractContentRewriteService to provide ProductCategory-specific implementations
 * for content rewriting, translation, and SEO generation.
 */
class ProductCategoryContentRewriteService extends AbstractContentRewriteService
{
    protected array $touchableModelClasses = [];

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process a product category - rewrite/translate all content and SEO fields.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function processCategory(Model $category): array
    {
        // The package never imports the host's ProductCategory class (§3
        // seam) — guard the Astrotomic-dependent parent algorithm at this
        // boundary, following the precedent at DetectionPollService.php:260.
        if (!$category instanceof Translatable) {
            throw new \InvalidArgumentException(sprintf(
                'Model [%s] must implement %s to be processed by %s.',
                $category::class,
                Translatable::class,
                self::class,
            ));
        }

        return $this->processModel($category);
    }

    /**
     * Translate a product category from one locale to another.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function translateToLocale(Model $category, string $sourceLocale, string $targetLocale, bool $overwrite = false): array
    {
        /** @phpstan-ignore property.notFound */
        $modelId = (int) $category->id;

        $results = [
            'model_id' => $modelId,
            'processed_fields' => [],
            'errors' => [],
        ];

        // The package never imports the host's ProductCategory class (§3
        // seam) — guard the Astrotomic calls below at this boundary, following
        // the precedent at DetectionPollService.php:260.
        if (!$category instanceof Translatable) {
            $results['errors'][] = sprintf('Model [%s] does not support translations.', $category::class);

            return $results;
        }

        $this->logRequest($this->getServiceName().' - translateToLocale', [
            'model_id' => $modelId,
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'overwrite' => $overwrite,
        ]);

        $sourceTranslation = $category->translate($sourceLocale);
        if ($sourceTranslation === null || empty($sourceTranslation->name)) {
            $results['errors'][] = "No source content found for locale {$sourceLocale}";

            return $results;
        }

        // Core fields: name, description + SEO
        $allFields = array_merge(
            $this->getContentFields(),
            $this->getSeoFields(),
        );

        // Also include 'name' as plain text
        $allFields = array_merge(['name' => 'plain'], $allFields);

        foreach ($allFields as $field => $type) {
            if ($this->isCancelled()) {
                break;
            }

            $sourceValue = (string) ($sourceTranslation->{$field} ?? '');
            if ($sourceValue === '') {
                continue;
            }

            $targetTranslation = $category->translate($targetLocale);
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
                $this->saveTranslation($category, $field, $targetLocale, $translated);
                $results['processed_fields'][] = "{$field}:{$targetLocale}";
            } catch (AiBudgetExceededException $e) {
                // Budget exhaustion is tenant-global — fail fast so the job
                // fails immediately instead of burning retries (audit §6).
                throw $e;
            } catch (AiOperationAlreadyCompletedException $e) {
                // A retry re-sent a field a prior attempt already translated under the
                // same deterministic id — reconcile as done, not failed.
                $this->reconcileAlreadyCompletedField($category, $field, $targetLocale, $results, $e);
            } catch (\Exception $e) {
                $results['errors'][] = "{$field}:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
                $this->logError($this->getServiceName().' - translateToLocale', $e);
            }
        }

        // Generate slug from translated name
        if (in_array("name:{$targetLocale}", $results['processed_fields'], true)) {
            try {
                $category->refresh();
                $translatedCategory = $category->translate($targetLocale);
                $translatedName = (string) ($translatedCategory->name ?? '');
                if ($translatedName !== '') {
                    $slug = $this->generateUniqueSlug($translatedName, $modelId, $targetLocale);
                    $this->saveTranslation($category, 'slug', $targetLocale, $slug);
                    $results['processed_fields'][] = "slug:{$targetLocale}";
                }
            } catch (\Exception $e) {
                $results['errors'][] = "slug:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
            }
        }

        $category->refresh();

        $this->logResponse($this->getServiceName().' - translateToLocale', $results);

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ABSTRACT METHOD IMPLEMENTATIONS
    // ═══════════════════════════════════════════════════════════════════════

    protected function getContentFields(): array
    {
        return [
            'title' => 'plain',
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
        // NOTE: the registry key is `category`, not `product_category`.
        return app(ModelRegistry::class)->getModelClassOrFail('category');
    }

    protected function getTranslationClass(): string
    {
        return app(ModelRegistry::class)->getModelClassOrFail('product_category_translation');
    }

    protected function getServiceName(): string
    {
        return 'ProductCategoryContentRewrite';
    }

    protected function getContentTypeLabel(): string
    {
        return 'product categories';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.field_rewrite` and
     * `rewrite.field_generate` templates select this subclass's field
     * descriptions with (v2.6 design §2.5). It replaces the already-resolved
     * `field_description` string plan 48-05 sent as a temporary shape.
     */
    protected function getContentTypeKey(): string
    {
        return 'product_category';
    }

    protected function getPromptOverrideKey(): string
    {
        return 'product_category_rewrite_system';
    }

    protected function getSeoPromptOverrideKey(): string
    {
        return 'product_category_seo_system';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.seo_field` template
     * selects this subclass's instruction with (v2.6 design §2.5). It replaces
     * `buildSeoFieldPrompt()`, whose three branches were transcribed verbatim
     * into that template's variant table.
     */
    protected function getSeoContentTypeKey(): string
    {
        return 'product_category';
    }

    protected function buildModelContext(Model $model): array
    {
        $model->load(['parent.translations', 'children.translations', 'products']);

        // The package never imports the host's ProductCategory class (§3
        // seam), so these category-only attributes/relations are read off the widened
        // Model one at a time and ignored individually rather than typed away.
        /** @phpstan-ignore property.notFound */
        $parent = $model->parent;
        $parentTranslation = $parent instanceof Translatable ? $parent->translate() : null;
        $parentName = $parentTranslation->name ?? null;

        /** @phpstan-ignore property.notFound */
        $categoryId = $model->id;
        /** @phpstan-ignore property.notFound */
        $active = $model->active;
        /** @phpstan-ignore property.notFound */
        $depth = $model->depth;
        /** @phpstan-ignore method.notFound */
        $productsCount = $model->products()->count();
        /** @phpstan-ignore method.notFound */
        $childrenCount = $model->children()->count();

        $context = [
            'category_id' => $categoryId,
            'active' => $active,
            'depth' => $depth,
            'products_count' => $productsCount,
            'children_count' => $childrenCount,
            'parent_name' => $parentName,
            'translations' => [],
        ];

        // Collect existing translations for context
        if ($model instanceof Translatable) {
            foreach ($this->getLocales() as $locale) {
                $translation = $model->translate($locale);
                if ($translation) {
                    /** @phpstan-ignore property.notFound */
                    $name = $translation->name;
                    /** @phpstan-ignore property.notFound */
                    $title = $translation->title;
                    /** @phpstan-ignore property.notFound */
                    $description = $translation->description;

                    $context['translations'][$locale] = [
                        'name' => $name,
                        'title' => $title,
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
            'title' => 'a compelling category title (concise, descriptive, 3-8 words)',
            'description' => 'a detailed category description (well-structured with what products are available, who they are for, and key features)',
            'seo_title' => 'an SEO-optimized title (max 60 characters, include main keyword)',
            'seo_description' => 'an SEO meta description (max 160 characters, compelling call to action)',
            'seo_keywords' => 'comma-separated SEO keywords (5-10 relevant keywords)',
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

        $name = $translation->name ?? '';
        $title = $translation->title ?? '';
        $description = $translation->description ?? null;

        return [
            'name' => $name,
            'title' => $title,
            'description' => $this->truncateForContext($description, $seoContextLimit),
            'products_count' => $context['products_count'] ?? 0,
            'children_count' => $context['children_count'] ?? 0,
            'parent_name' => $context['parent_name'] ?? '',
            'depth' => $context['depth'] ?? 0,
        ];
    }

    /**
     * Handle name/slug generation for a product category.
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
        $translationClass = app(ModelRegistry::class)->getModelClassOrFail('product_category_translation');

        return $translationClass::where('slug', $slug)
            ->where('locale', $locale)
            ->where('product_category_id', '!=', $excludeId)
            ->exists();
    }

    /**
     * Resolve the content archetype for a product category.
     *
     * Uses the HasContentArchetype trait's resolution:
     * 1. Direct assignment on the category
     * 2. System default for ProductCategory type
     *
     * The host's HasContentArchetype trait is not exposed via a
     * package-visible contract, so it is called dynamically here — this
     * package never imports the host's ProductCategory class.
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
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate or translate name from source locale.
     *
     * @param  array<string, mixed>  $context
     */
    private function generateOrTranslateName(Model $category, string $locale, array $context): string
    {
        $sourceLocale = $this->findSourceLocale($category, 'name');

        if ($sourceLocale && $sourceLocale !== $locale && $category instanceof Translatable) {
            /** @phpstan-ignore property.notFound */
            $sourceName = (string) $category->translate($sourceLocale)?->name;

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
        // Generate a basic name from context
        $baseName = 'Category #'.$context['category_id'];

        // If we have source content in any locale, use that for name generation
        foreach ($context['translations'] as $localeData) {
            if (!empty($localeData['name'])) {
                return $this->translateText($localeData['name'], 'en', $locale);
            }
        }

        return $baseName;
    }
}
