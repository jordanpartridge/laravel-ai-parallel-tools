<?php

namespace JordanPartridge\ParallelTools;

use Generator;
use Illuminate\Support\Collection;
use JordanPartridge\ParallelTools\Attributes\Concurrent;
use JordanPartridge\ParallelTools\Exceptions\MultipleParallelToolFailures;
use JordanPartridge\ParallelTools\Support\ConcurrentCalls;
use JordanPartridge\ParallelTools\Support\ToolCallOutcome;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\AgentTool;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Stock tool loop, with opted-in calls from one step run together.
 *
 * Events are applied in the model's call order after the batch finishes.
 * A sub-agent tool still streams between calls, and a tool that needs
 * approval never enters the batch.
 */
class ParallelTextGenerationLoop extends TextGenerationLoop
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<Tool|ProviderTool>  $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    #[\Override]
    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false, ?RunContext $context = null): array
    {
        [$resolved, $pendingApprovals] = $this->resolveToolCalls($toolCalls, $tools, $isFinalStep);

        $toolResults = [];
        $batch = [];

        foreach ($resolved as [$toolCall, $tool]) {
            if ($this->runsConcurrently($tool, $isFinalStep)) {
                $batch[] = [$toolCall, $tool, $toolCall->arguments];

                continue;
            }

            array_push($toolResults, ...$this->release($batch, $context, resume: false));
            $batch = [];
            $toolResults[] = $this->resolvedToolResult($toolCall, $tool, $isFinalStep, $tools, $context);
        }

        array_push($toolResults, ...$this->release($batch, $context, resume: false));

        return [$toolResults, $pendingApprovals];
    }

    /**
     * @param  array<Tool|ProviderTool>  $tools
     * @return Generator<int, \Laravel\Ai\Streaming\Events\ToolResult, mixed, array{array<int, ToolResult>, Collection<int, PendingApproval>}>
     */
    #[\Override]
    protected function streamedStepToolResults(StepResponse $result, bool $isFinalStep, array $tools, string $invocationId, ?TextGenerationOptions $options = null, ?RunContext $context = null): Generator
    {
        if (($earlyOutcome = $this->earlyStepToolResults($result)) !== null) {
            return $earlyOutcome;
        }

        [$resolved, $pendingApprovals] = $this->withRepair(
            $options,
            fn (): array => $this->resolveToolCalls($result->toolCalls, $tools, $isFinalStep),
        );

        $toolResults = [];
        $batch = [];

        foreach ($resolved as [$toolCall, $tool]) {
            if ($tool instanceof AgentTool && ! $isFinalStep) {
                array_push($toolResults, ...$this->release($batch, $context, resume: false));
                $batch = [];

                $events = $this->executeAgentToolStreaming($tool, $toolCall->arguments, $toolCall->id, $context);

                yield from $this->preliminaryToolResults($events, $toolCall, $invocationId);

                $toolResults[] = $this->recordedToolResult($this->toolResult($toolCall, $events->getReturn()), $context);

                continue;
            }

            if ($this->runsConcurrently($tool, $isFinalStep)) {
                $batch[] = [$toolCall, $tool, $toolCall->arguments];

                continue;
            }

            array_push($toolResults, ...$this->release($batch, $context, resume: false));
            $batch = [];
            $toolResults[] = $this->withRepair(
                $options,
                fn (): ToolResult => $this->resolvedToolResult($toolCall, $tool, $isFinalStep, $tools, $context),
            );
        }

        array_push($toolResults, ...$this->release($batch, $context, resume: false));

        return [$toolResults, $pendingApprovals];
    }

    /**
     * Approved calls use the stock resume rule: a thrown handler becomes
     * "The tool call failed: …" and the run continues. Concurrent calls
     * still overlap; their failures are not dropped.
     *
     * @param  array<string, Decision>  $approval
     * @param  array<int, Message>  $messages
     * @param  array<Tool|ProviderTool>  $tools
     * @param  array{0: Collection<int, ToolCall>, 1: Collection<string, ?Tool>}|null  $validatedApproval
     * @return array{array<int, ToolResult>, bool}
     */
    #[\Override]
    protected function resolveApprovalResults(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): array
    {
        [$pendingToolCalls, $resolvedTools] = $validatedApproval ?? $this->validateApproval($approval, $messages, $tools);

        $toolResults = [];
        $hasBareRejection = false;
        $batch = [];

        foreach ($pendingToolCalls as $toolCall) {
            $tool = $resolvedTools[$toolCall->id];

            $decision = $tool instanceof Tool && ! $tool instanceof Approvable
                ? Decision::reject('This tool call was not executed because it was not pending approval.')
                : $approval[$toolCall->id] ?? $approval['*'] ?? Decision::reject('The user rejected this tool call.');

            if ($decision->isRejected()) {
                array_push($toolResults, ...$this->release($batch, $context, resume: true));
                $batch = [];

                $hasBareRejection = $hasBareRejection || $decision->result === null;

                $toolResults[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $decision->result ?? 'The user rejected this tool call.',
                    $toolCall->resultId,
                    denied: true,
                );

                continue;
            }

            $arguments = $decision->arguments ?? $toolCall->arguments;

            if (! $tool instanceof Tool) {
                array_push($toolResults, ...$this->release($batch, $context, resume: true));
                $batch = [];

                throw new NoSuchToolException($toolCall->name);
            }

            if ($this->runsConcurrently($tool, false)) {
                $batch[] = [$toolCall, $tool, $arguments];

                continue;
            }

            array_push($toolResults, ...$this->release($batch, $context, resume: true));
            $batch = [];
            $toolResults[] = $this->runApprovedInline($toolCall, $tool, $arguments, $context);
        }

        array_push($toolResults, ...$this->release($batch, $context, resume: true));

        return [$toolResults, ! $hasBareRejection];
    }

    protected function runsConcurrently(?Tool $tool, bool $isFinalStep): bool
    {
        return ! $isFinalStep
            && $tool instanceof Tool
            && ! $tool instanceof AgentTool
            && (new ReflectionClass($tool))->getAttributes(Concurrent::class) !== [];
    }

    /**
     * One call runs inline. Two or more run together, then settle in call order.
     *
     * @param  list<array{0: ToolCall, 1: Tool, 2: array<string, mixed>}>  $batch
     * @return list<ToolResult>
     */
    private function release(array $batch, ?RunContext $context, bool $resume): array
    {
        if ($batch === []) {
            return [];
        }

        if (count($batch) < 2) {
            [$toolCall, $tool, $arguments] = $batch[0];

            return $resume
                ? [$this->runApprovedInline($toolCall, $tool, $arguments, $context)]
                : [$this->resolvedToolResult($toolCall, $tool, false, [], $context)];
        }

        return $this->apply(ConcurrentCalls::run($batch, $context?->invocationId), $context, $resume);
    }

    /**
     * @param  list<ToolCallOutcome>  $outcomes
     * @return list<ToolResult>
     */
    private function apply(array $outcomes, ?RunContext $context, bool $resume): array
    {
        $results = [];
        $failures = [];

        foreach ($outcomes as $outcome) {
            $context?->invokingTool($outcome->tool, $outcome->arguments, $outcome->id);

            if (! $outcome->ok) {
                $exception = $outcome->exception ?? new \RuntimeException('Tool failed.');

                $context?->toolFailed($outcome->tool, $outcome->arguments, $exception, $outcome->id, $outcome->milliseconds);

                if ($resume) {
                    $results[] = new ToolResult(
                        $outcome->toolCall->id,
                        $outcome->toolCall->name,
                        $outcome->arguments,
                        'The tool call failed: '.$exception->getMessage(),
                        $outcome->toolCall->resultId,
                        failed: true,
                    );

                    continue;
                }

                $failures[] = [
                    'tool' => $outcome->toolCall->name,
                    'exception' => $exception,
                ];

                continue;
            }

            $context?->toolInvoked($outcome->tool, $outcome->arguments, $outcome->result, $outcome->id, $outcome->milliseconds);

            $results[] = $this->recordedToolResult(
                $this->toolResult($outcome->toolCall, (string) $outcome->result),
                $context,
            );
        }

        if ($failures === []) {
            return $results;
        }

        throw count($failures) === 1
            ? $failures[0]['exception']
            : new MultipleParallelToolFailures($failures);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runApprovedInline(ToolCall $toolCall, Tool $tool, array $arguments, ?RunContext $context): ToolResult
    {
        $failed = false;

        try {
            $result = $this->executeTool($tool, $arguments, $toolCall->id, $context);
        } catch (Throwable $exception) {
            $failed = true;
            $result = 'The tool call failed: '.$exception->getMessage();
        }

        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $arguments,
            $result,
            $toolCall->resultId,
            failed: $failed,
        );
    }

    /**
     * withRepairSetting() is private on the stock loop. Streaming still has
     * to honor a RepairToolCalls agent, so call it rather than copy it.
     */
    private function withRepair(?TextGenerationOptions $options, \Closure $callback): mixed
    {
        return (new ReflectionMethod(TextGenerationLoop::class, 'withRepairSetting'))->invoke($this, $options, $callback);
    }
}
