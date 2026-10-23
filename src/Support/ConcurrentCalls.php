<?php

namespace JordanPartridge\ParallelTools\Support;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Responses\Data\ToolCall;
use Throwable;

/**
 * One opted-in batch. Tasks catch their own failures so a process-driver
 * throw cannot drop every sibling result.
 */
class ConcurrentCalls
{
    /**
     * Drivers that return the task value in this process. Every other driver
     * serializes it, so the live exception object cannot ride along.
     *
     * @var list<string>
     */
    private const IN_PROCESS = ['sync', 'reverse'];

    /**
     * @param  list<array{0: ToolCall, 1: Tool, 2: array<string, mixed>}>  $batch
     * @return list<ToolCallOutcome>
     */
    public static function run(array $batch, ?string $invocationId): array
    {
        $keepException = in_array((string) config('concurrency.default', 'process'), self::IN_PROCESS, true);
        $tasks = [];
        $meta = [];

        foreach ($batch as $index => [$toolCall, $tool, $arguments]) {
            $id = (string) Str::uuid7();
            $meta[$index] = [$toolCall, $tool, $arguments, $id];
            $toolCallId = $toolCall->id;

            $tasks[$index] = static function () use ($tool, $arguments, $toolCallId, $invocationId, $id, $keepException): array {
                $startedAt = hrtime(true);

                try {
                    $result = ParentInvocation::within(
                        $invocationId,
                        $id,
                        fn (): mixed => ToolCallRunner::handle($tool, $arguments, $toolCallId, $id),
                    );

                    return [
                        'ok' => true,
                        'result' => $result,
                        'ms' => (hrtime(true) - $startedAt) / 1e6,
                    ];
                } catch (Throwable $exception) {
                    return [
                        'ok' => false,
                        'ms' => (hrtime(true) - $startedAt) / 1e6,
                        ...ExceptionRebuilder::capture($exception, $keepException),
                    ];
                }
            };
        }

        /** @var array<int, array<string, mixed>> $ran */
        $ran = Container::getInstance()->make(ConcurrencyManager::class)->run($tasks);
        $outcomes = [];

        foreach ($meta as $index => [$toolCall, $tool, $arguments, $id]) {
            $row = $ran[$index];
            $ok = (bool) ($row['ok'] ?? false);

            $outcomes[] = new ToolCallOutcome(
                toolCall: $toolCall,
                tool: $tool,
                arguments: $arguments,
                id: $id,
                milliseconds: (float) ($row['ms'] ?? 0),
                ok: $ok,
                result: $ok ? ($row['result'] ?? null) : null,
                exception: $ok ? null : ExceptionRebuilder::restore($row),
            );
        }

        return $outcomes;
    }
}
