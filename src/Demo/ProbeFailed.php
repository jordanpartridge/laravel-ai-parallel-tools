<?php

namespace JordanPartridge\ParallelTools\Demo;

use RuntimeException;

class ProbeFailed extends RuntimeException
{
    public function __construct(public readonly int $probe)
    {
        parent::__construct("Probe {$probe} failed.", $probe);
    }
}
