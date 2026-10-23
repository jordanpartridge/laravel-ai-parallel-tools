<?php

namespace JordanPartridge\ParallelTools\Internal;

use JordanPartridge\ParallelTools\Concerns\OverridesTextGenerationLoop;
use Laravel\Ai\Providers\OpenAiProvider;

/**
 * Type-checks the parallel loop against a stock text provider.
 *
 * Runtime promotion evals the same trait onto whichever provider the
 * manager resolves. This class is the one PHPStan can see.
 *
 * @internal
 */
final class CheckedParallelProvider extends OpenAiProvider
{
    use OverridesTextGenerationLoop;
}
