<?php

namespace JordanPartridge\ParallelTools\Concerns;

use JordanPartridge\ParallelTools\ParallelTextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationLoop;

/**
 * Replaces the stock loop on a provider subclass generated at runtime.
 *
 * HasTextGateway hardcodes `new TextGenerationLoop`. A method on the
 * subclass wins over that trait method, including after useTextGateway()
 * clears the cached loop.
 */
trait OverridesTextGenerationLoop
{
    public function usesParallelToolLoop(): true
    {
        return true;
    }

    public function textGenerationLoop(): TextGenerationLoop
    {
        return $this->textGenerationLoop ??= new ParallelTextGenerationLoop($this->textGateway());
    }
}
