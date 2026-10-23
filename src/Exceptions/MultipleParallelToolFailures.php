<?php

namespace JordanPartridge\ParallelTools\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Two or more tools in one concurrent batch threw.
 *
 * A single failure is rethrown as itself, matching stock Laravel AI. Several
 * failures stay on this exception instead of being rewritten into tool-result
 * text the model would see and answer.
 */
class MultipleParallelToolFailures extends RuntimeException
{
    /**
     * @param  list<array{tool: string, exception: Throwable}>  $failures  Call order.
     */
    public function __construct(public readonly array $failures)
    {
        $summary = implode('; ', array_map(
            fn (array $failure): string => $failure['tool'].': '.$failure['exception']::class.': '.$failure['exception']->getMessage(),
            $failures,
        ));

        parent::__construct(
            count($failures).' tools failed in one step. None of those errors were returned to the model as text. '.$summary,
            0,
            $failures[0]['exception'] ?? null,
        );
    }

    /**
     * @return list<Throwable>
     */
    public function exceptions(): array
    {
        return array_values(array_map(
            fn (array $failure): Throwable => $failure['exception'],
            $this->failures,
        ));
    }

    /**
     * @return list<string>
     */
    public function tools(): array
    {
        return array_values(array_map(
            fn (array $failure): string => $failure['tool'],
            $this->failures,
        ));
    }
}
