<?php

namespace JordanPartridge\ParallelTools\Support;

use JordanPartridge\ParallelTools\Concerns\OverridesTextGenerationLoop;
use Laravel\Ai\Contracts\Providers\TextProvider;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;
use Throwable;

/**
 * Stock providers construct the text loop with `new` and do not resolve it
 * from the container. PHP cannot declare `class Child extends $runtimeParent`,
 * so each concrete text provider gets one generated subclass whose only job
 * is to override textGenerationLoop().
 */
class ProviderPromoter
{
    /** @var array<class-string, class-string> */
    private static array $subclasses = [];

    public static function promote(object $provider): object
    {
        if (! $provider instanceof TextProvider || method_exists($provider, 'usesParallelToolLoop')) {
            return $provider;
        }

        $class = self::subclass($provider::class);

        if ($class === null) {
            return $provider;
        }

        $promoted = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::copyState($provider, $promoted);
        self::forgetCachedLoop($promoted);

        return $promoted;
    }

    /**
     * @param  class-string  $providerClass
     * @return class-string|null
     */
    private static function subclass(string $providerClass): ?string
    {
        if (isset(self::$subclasses[$providerClass])) {
            return self::$subclasses[$providerClass];
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $providerClass)) {
            return null;
        }

        $short = 'Parallel_'.str_replace('\\', '_', $providerClass);
        $fqcn = 'JordanPartridge\\ParallelTools\\Internal\\'.$short;

        if (! class_exists($fqcn, false)) {
            $trait = OverridesTextGenerationLoop::class;

            try {
                eval(sprintf(
                    'namespace JordanPartridge\\ParallelTools\\Internal; class %s extends \\%s { use \\%s; }',
                    $short,
                    $providerClass,
                    $trait,
                ));
            } catch (Throwable) {
                return null;
            }
        }

        if (! class_exists($fqcn, false)) {
            return null;
        }

        /** @var class-string $fqcn */
        return self::$subclasses[$providerClass] = $fqcn;
    }

    private static function copyState(object $from, object $to): void
    {
        $class = new ReflectionObject($from);

        do {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($from)) {
                    continue;
                }

                $property->setValue($to, $property->getValue($from));
            }
        } while ($class = $class->getParentClass());
    }

    private static function forgetCachedLoop(object $provider): void
    {
        try {
            $property = (new ReflectionClass($provider))->getProperty('textGenerationLoop');
        } catch (ReflectionException) {
            return;
        }

        if ($property->isInitialized($provider)) {
            $property->setValue($provider, null);
        }
    }
}
