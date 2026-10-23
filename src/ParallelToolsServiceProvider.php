<?php

namespace JordanPartridge\ParallelTools;

use Illuminate\Support\ServiceProvider;
use JordanPartridge\ParallelTools\Console\ParallelToolsDemoCommand;
use Laravel\Ai\AiManager;

class ParallelToolsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->extend(AiManager::class, function ($manager, $app) {
            if ($manager instanceof ParallelAiManager) {
                return $manager;
            }

            $parallel = new ParallelAiManager($app);

            if ($manager instanceof AiManager) {
                $parallel->adopt($manager);
            }

            return $parallel;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ParallelToolsDemoCommand::class,
            ]);
        }
    }
}
