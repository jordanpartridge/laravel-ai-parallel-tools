<?php

namespace JordanPartridge\ParallelTools\Demo;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class DemoAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  list<DemoProbe>  $probes
     */
    public function __construct(public array $probes) {}

    public function instructions(): string
    {
        return 'Call every probe once, then stop.';
    }

    public function tools(): iterable
    {
        return $this->probes;
    }
}
