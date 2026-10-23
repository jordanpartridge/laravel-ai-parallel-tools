<?php

namespace JordanPartridge\ParallelTools\Demo;

use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use RuntimeException;

/**
 * Offline stand-in for a model. Each call returns the next scripted step.
 */
class ScriptedTextGateway implements StepTextGateway
{
    private int $index = 0;

    /**
     * @param  list<StepResponse>  $steps
     */
    public function __construct(private array $steps) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $step = $this->steps[$this->index] ?? null;
        $this->index++;

        if (! $step instanceof StepResponse) {
            throw new RuntimeException('The scripted model has no step left.');
        }

        return $step;
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $step = $this->generateTextStep(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );

        yield from [];

        return $step;
    }
}
