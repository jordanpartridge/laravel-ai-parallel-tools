<?php

namespace JordanPartridge\ParallelTools\Support;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\ToolCall;
use Throwable;

class ToolCallOutcome
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public ToolCall $toolCall,
        public Tool $tool,
        public array $arguments,
        public string $id,
        public float $milliseconds,
        public bool $ok,
        public mixed $result = null,
        public ?Throwable $exception = null,
    ) {}
}
