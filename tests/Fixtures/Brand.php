<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests\Fixtures;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model implements TranslatableContract
{
    use Translatable;

    public $timestamps = false;

    /** @var array<int, string> */
    public array $translatedAttributes = ['name'];

    protected $guarded = [];
}
