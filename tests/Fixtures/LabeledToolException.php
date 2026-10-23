<?php

namespace JordanPartridge\ParallelTools\Tests\Fixtures;

use RuntimeException;

class LabeledToolException extends RuntimeException
{
    /**
     * @param  list<string>  $items
     */
    public function __construct(public string $label, int $code = 7, public array $items = [])
    {
        parent::__construct('kaboom:'.$label, $code);
    }
}
