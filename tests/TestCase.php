<?php

namespace JordanPartridge\ParallelTools\Tests;

use JordanPartridge\ParallelTools\ParallelToolsServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            ParallelToolsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('concurrency.default', 'sync');
        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai.key', 'test-key');
    }
}
