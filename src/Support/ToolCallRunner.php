<?php

namespace JordanPartridge\ParallelTools\Support;

use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ToolCallRunner
{
    /**
     * Run the handler. Validation errors are text for the model, not a failed call.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function handle(Tool $tool, array $arguments, ?string $toolCallId, string $toolInvocationId): mixed
    {
        try {
            return $tool->handle(new Request($arguments, $toolCallId, $toolInvocationId));
        } catch (ValidationException $exception) {
            return implode(' ', $exception->validator->errors()->all()) ?: $exception->getMessage();
        }
    }
}
