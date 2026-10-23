<?php

namespace JordanPartridge\ParallelTools;

use Closure;
use JordanPartridge\ParallelTools\Support\ProviderPromoter;
use Laravel\Ai\AiManager;
use ReflectionObject;

/**
 * Every text provider the manager returns is promoted before it is cached,
 * so Agent::prompt() and Agent::stream() use the parallel loop.
 */
class ParallelAiManager extends AiManager
{
    /**
     * @param  string|null  $name
     */
    #[\Override]
    public function instance($name = null): mixed
    {
        $name = $name ?: $this->getDefaultInstance();

        $instance = parent::instance($name);

        return $this->instances[$name] = ProviderPromoter::promote($instance);
    }

    /**
     * Keep drivers, fakes, and custom creators from a manager this one replaces.
     */
    public function adopt(AiManager $manager): void
    {
        if ($manager === $this) {
            return;
        }

        $class = new ReflectionObject($manager);

        do {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($manager)) {
                    continue;
                }

                $property->setValue($this, $property->getValue($manager));
            }
        } while ($class = $class->getParentClass());

        $creators = $this->customCreators;
        $this->customCreators = [];

        foreach ($creators as $name => $callback) {
            if ($callback instanceof Closure) {
                $this->extend($name, $callback);
            }
        }
    }
}
