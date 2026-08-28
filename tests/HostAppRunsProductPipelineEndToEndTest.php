<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests;

use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiProducts\Services\ProductContentRewriteService;
use Core45\AiProducts\Tests\Fixtures\Brand;
use Core45\AiProducts\Tests\Fixtures\FakeAiClient;
use Core45\AiProducts\Tests\Fixtures\Product;
use Core45\AiProducts\Tests\Fixtures\ProductCategory;
use Core45\AiProducts\Tests\Fixtures\ProductTranslation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end proof that a bare Testbench host loading only ai-core and
 * ai-products can run the primary product rewrite pipeline against real
 * Eloquent models, host tables, and the packages' auto-loaded migrations.
 * Only the outermost remote AI client is replaced.
 */
class HostAppRunsProductPipelineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $aiClient;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.models', [
            'product' => Product::class,
            'product_translation' => ProductTranslation::class,
        ]);
        $app['config']->set('translatable.locales', ['en']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('brand_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id');
            $table->string('locale')->index();
            $table->string('name')->nullable();
            $table->unique(['brand_id', 'locale']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->boolean('is_virtual')->default(false);
            $table->boolean('on_demand')->default(false);
            $table->boolean('extra_info_show')->default(false);
            $table->timestamps();
        });

        Schema::create('product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->string('locale')->index();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('summary')->nullable();
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->text('specifications')->nullable();
            $table->text('user_manual')->nullable();
            $table->text('on_demand_description')->nullable();
            $table->string('extra_info_title')->nullable();
            $table->text('extra_info_description')->nullable();
            $table->text('gspr')->nullable();
            $table->unique(['product_id', 'locale']);
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('product_category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_category_id');
            $table->string('locale')->index();
            $table->string('name')->nullable();
            $table->unique(['product_category_id', 'locale']);
        });

        Schema::create('product_product_category', function (Blueprint $table): void {
            $table->foreignId('product_id');
            $table->foreignId('product_category_id');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('product_tag', function (Blueprint $table): void {
            $table->foreignId('product_id');
            $table->foreignId('tag_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiClient = new FakeAiClient;
        $this->app->bind(RemoteAiClient::class, fn (): FakeAiClient => $this->aiClient);
    }

    /**
     * Migration compatibility is proved under sqlite only. sqlite does not
     * enforce cross-table foreign-key ordering at CREATE TABLE time, so a
     * MySQL host still requires separate migration-order verification.
     */
    #[Test]
    public function the_package_migrations_run_in_the_bare_app(): void
    {
        $this->assertTrue(Schema::hasTable('ai_prompt_overrides'), 'ai-core migrations did not run.');
    }

    #[Test]
    public function a_bare_app_rewrites_and_persists_a_product(): void
    {
        $locale = (string) config('translatable.locales.0');
        $brand = Brand::create();
        DB::table('brand_translations')->insert([
            'brand_id' => $brand->id,
            'locale' => $locale,
            'name' => 'Northstar',
        ]);

        $category = ProductCategory::create();
        DB::table('product_category_translations')->insert([
            'product_category_id' => $category->id,
            'locale' => $locale,
            'name' => 'Warehouse systems',
        ]);

        $tagId = DB::table('tags')->insertGetId(['name' => 'automation']);
        $product = Product::create([
            'brand_id' => $brand->id,
            'sku' => 'NS-100',
            'price' => 129900,
        ]);
        $product->categories()->attach($category);
        $product->tags()->attach($tagId);

        DB::table('product_translations')->insert([
            'product_id' => $product->id,
            'locale' => $locale,
            'name' => 'Original product',
            'slug' => 'original-product',
            'summary' => 'Original summary.',
            'description' => '<p>Original description.</p>',
        ]);

        $results = app(ProductContentRewriteService::class)->processProduct($product->fresh());

        $this->assertSame([], $results['errors'], 'Rewrite reported errors: '.json_encode($results['errors']));

        $row = DB::table('product_translations')
            ->where('product_id', $product->id)
            ->where('locale', $locale)
            ->first();

        $this->assertStringContainsString('AI SUMMARY', (string) $row->summary);
        $this->assertStringContainsString('AI DESCRIPTION', (string) $row->description);
        $this->assertSame('AI SEO TITLE', $row->seo_title);
        $this->assertSame('AI SEO DESCRIPTION', $row->seo_description);
        $this->assertSame('ai, seo, keywords', $row->seo_keywords);

        $this->assertSame([
            ['template' => 'rewrite.field_rewrite', 'field' => 'summary'],
            ['template' => 'rewrite.field_rewrite', 'field' => 'description'],
            ['template' => 'rewrite.seo_field', 'field' => 'seo_title'],
            ['template' => 'rewrite.seo_field', 'field' => 'seo_description'],
            ['template' => 'rewrite.seo_field', 'field' => 'seo_keywords'],
        ], $this->aiClient->calls);

        $this->assertSame('Northstar', $this->aiClient->parameterSets[0]['context']['brand'][$locale]);
        $this->assertSame(['Warehouse systems'], $this->aiClient->parameterSets[0]['context']['categories'][$locale]);
        $this->assertSame(['automation'], $this->aiClient->parameterSets[0]['context']['tags']);
    }
}
