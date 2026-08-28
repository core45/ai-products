<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests\Fixtures;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model implements TranslatableContract
{
    use Translatable;

    protected $translationModel = ProductTranslation::class;

    /** @var array<int, string> */
    public array $translatedAttributes = [
        'name', 'slug', 'summary', 'description', 'seo_title',
        'seo_description', 'seo_keywords', 'specifications', 'user_manual',
        'on_demand_description', 'extra_info_title', 'extra_info_description', 'gspr',
    ];

    protected $guarded = [];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsToMany<ProductCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'product_product_category');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag');
    }
}
