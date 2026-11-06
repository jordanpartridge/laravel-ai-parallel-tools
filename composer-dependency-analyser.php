<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// laravel/framework replaces illuminate/*, so component classes are attributed
// to laravel/framework and the illuminate packages look unused.
return (new Configuration)
    ->ignoreErrorsOnPackage('laravel/framework', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackages([
        'illuminate/concurrency',
        'illuminate/console',
        'illuminate/container',
        'illuminate/contracts',
        'illuminate/support',
        'illuminate/validation',
    ], [ErrorType::UNUSED_DEPENDENCY]);
