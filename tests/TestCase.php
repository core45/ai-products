<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests;

use Composer\Autoload\ClassLoader;
use Core45\AiCore\AiCoreServiceProvider;
use Core45\AiProducts\AiProductsServiceProvider;
use Orchestra\Testbench\TestCase as OrchTestCase;

/** @var ClassLoader $loader */
$loader = require __DIR__.'/../vendor/autoload.php';
$loader->addPsr4('Core45\\AiCore\\', __DIR__.'/../../ai-core/src', true);

/**
 * D12 two-layer testing: per-package Testbench harness. Boots ONLY this
 * package and ai-core — no host app, no host service providers.
 */
abstract class TestCase extends OrchTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiCoreServiceProvider::class,
            AiProductsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.base_url', 'https://phasea.test');
        $app['config']->set('ai.token', 'test-token');
    }
}
