<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests;

use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Support\PromptKeyRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * This package registers its own prompt keys (plan §9 D14).
 *
 * Four of them, covering products and product categories, content and SEO.
 * `ai-core` used to enumerate all four — the §2.5 item 3 leak — which meant an
 * application with no product code still saw them offered.
 */
class PromptKeyRegistrationTest extends TestCase
{
    #[Test]
    public function the_product_prompt_keys_are_registered(): void
    {
        $offered = AIPromptOverride::availablePromptKeys();

        $this->assertSame('Product Content Rewrite System Prompt', $offered['product_rewrite_system'] ?? null);
        $this->assertSame('Product Category Content Rewrite System Prompt', $offered['product_category_rewrite_system'] ?? null);
        $this->assertSame('Product SEO Generation System Prompt', $offered['product_seo_system'] ?? null);
        $this->assertSame('Product Category SEO Generation System Prompt', $offered['product_category_seo_system'] ?? null);
    }

    #[Test]
    public function the_core_keys_survive_alongside_them(): void
    {
        $this->assertArrayHasKey('admin_system', AIPromptOverride::availablePromptKeys());
    }

    #[Test]
    public function no_other_domains_keys_appear(): void
    {
        $offered = array_keys(AIPromptOverride::availablePromptKeys());

        $this->assertNotContains('post_rewrite_system', $offered);
        $this->assertNotContains('webinar_seo_system', $offered);
    }

    /**
     * `product_rewrite_system` renders `warning` while its three siblings render
     * the default grey. That asymmetry is inherited verbatim from the `match`
     * that used to live on `AIPromptOverride`; it is asserted here so the
     * inversion is provably behaviour-preserving, NOT because the inconsistency
     * is intended. Changing it is a deliberate decision, not a refactor.
     */
    #[Test]
    public function the_inherited_badge_colours_are_unchanged(): void
    {
        $this->assertSame('warning', AIPromptOverride::promptKeyBadgeColor('product_rewrite_system'));
        $this->assertSame('gray', AIPromptOverride::promptKeyBadgeColor('product_seo_system'));
        $this->assertSame('gray', AIPromptOverride::promptKeyBadgeColor('product_category_rewrite_system'));
        $this->assertSame('gray', AIPromptOverride::promptKeyBadgeColor('product_category_seo_system'));
    }

    #[Test]
    public function each_key_carries_the_template_its_overrides_ride(): void
    {
        $registry = app(PromptKeyRegistry::class);

        $this->assertSame('rewrite.field_rewrite', $registry->templateFor('product_rewrite_system'));
        $this->assertSame('rewrite.field_rewrite', $registry->templateFor('product_category_rewrite_system'));
        $this->assertSame('rewrite.seo_field', $registry->templateFor('product_seo_system'));
        $this->assertSame('rewrite.seo_field', $registry->templateFor('product_category_seo_system'));
    }
}
