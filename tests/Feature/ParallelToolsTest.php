<?php

use Carbon\CarbonInterval;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Event;
use JordanPartridge\ParallelTools\Demo\ScriptedTextGateway;
use JordanPartridge\ParallelTools\Exceptions\MultipleParallelToolFailures;
use JordanPartridge\ParallelTools\ParallelTextGenerationLoop;
use JordanPartridge\ParallelTools\Support\ProcessAutoload;
use JordanPartridge\ParallelTools\Tests\Fixtures\BarrierTool;
use JordanPartridge\ParallelTools\Tests\Fixtures\EchoTool;
use JordanPartridge\ParallelTools\Tests\Fixtures\LabeledToolException;
use JordanPartridge\ParallelTools\Tests\Fixtures\ProbeTool;
use Laravel\Ai\AiManager;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

use function Illuminate\Support\defer;

beforeEach(function () {
    ProbeTool::reset();
    config(['concurrency.default' => 'sync']);
});

test('opted-in calls from one step all succeed', function () {
    $response = promptWith([new EchoTool('a'), new EchoTool('b')], [
        toolStep([['call-a', 'a'], ['call-b', 'b']]),
        textStep('Done.'),
    ]);

    expect(app(AiManager::class)->textProvider()->textGenerationLoop())
        ->toBeInstanceOf(ParallelTextGenerationLoop::class)
        ->and(resultsOf($response))->toBe(['result:a', 'result:b'])
        ->and($response->text)->toBe('Done.')
        ->and(ProbeTool::$trace)->toBe(['a', 'b']);
});

test('results stay in call order when execution order does not', function () {
    reverseDriver();

    $response = promptWith([
        new EchoTool('a'),
        new EchoTool('b'),
        new BarrierTool('c'),
        new EchoTool('d'),
        new EchoTool('e'),
    ], [
        toolStep([
            ['call-a', 'a'],
            ['call-b', 'b'],
            ['call-c', 'c'],
            ['call-d', 'd'],
            ['call-e', 'e'],
        ]),
        textStep('Done.'),
    ]);

    expect(resultsOf($response))->toBe(['result:a', 'result:b', 'result:c', 'result:d', 'result:e'])
        ->and(ProbeTool::$trace)->toBe(['b', 'a', 'c', 'e', 'd']);
});

test('a single failing tool still throws that exception', function () {
    $invoked = [];
    $failed = [];
    listen($invoked, $failed);

    $caught = promptCatching([new EchoTool('a'), new EchoTool('b', throws: true)], [
        toolStep([['call-a', 'a'], ['call-b', 'b']]),
        textStep('Done.'),
    ]);

    expect($caught)->toBeInstanceOf(LabeledToolException::class)
        ->and($caught->label)->toBe('b')
        ->and($caught->items)->toBe(['b'])
        ->and($caught->getMessage())->toBe('kaboom:b')
        ->and($caught->getCode())->toBe(7)
        ->and($invoked)->toBe([['a', 'result:a']])
        ->and($failed)->toBe([['b', LabeledToolException::class, 'kaboom:b']])
        ->and(ProbeTool::$trace)->toBe(['a', 'b']);
});

test('several failures are thrown together and are not tool text', function () {
    $invoked = [];
    $failed = [];
    listen($invoked, $failed);

    $caught = promptCatching([
        new EchoTool('a', throws: true),
        new EchoTool('b', throws: true),
    ], [
        toolStep([['call-a', 'a'], ['call-b', 'b']]),
        textStep('Done.'),
    ]);

    expect($caught)->toBeInstanceOf(MultipleParallelToolFailures::class)
        ->and($caught->tools())->toBe(['a', 'b'])
        ->and($caught->exceptions()[0]->label)->toBe('a')
        ->and($caught->exceptions()[1]->label)->toBe('b')
        ->and($caught->getPrevious())->toBe($caught->exceptions()[0])
        ->and($invoked)->toBe([])
        ->and($failed)->toBe([
            ['a', LabeledToolException::class, 'kaboom:a'],
            ['b', LabeledToolException::class, 'kaboom:b'],
        ])
        ->and($caught->getMessage())->not->toContain('The tool call failed');
});

test('a mixed batch keeps every success and throws the one failure', function () {
    $invoked = [];
    $failed = [];
    listen($invoked, $failed);

    $caught = promptCatching([
        new EchoTool('a'),
        new EchoTool('b', throws: true),
        new EchoTool('c'),
    ], [
        toolStep([['call-a', 'a'], ['call-b', 'b'], ['call-c', 'c']]),
        textStep('Done.'),
    ]);

    expect($caught)->toBeInstanceOf(LabeledToolException::class)
        ->and($caught->label)->toBe('b')
        ->and($invoked)->toBe([['a', 'result:a'], ['c', 'result:c']])
        ->and($failed)->toBe([['b', LabeledToolException::class, 'kaboom:b']])
        ->and(ProbeTool::$trace)->toBe(['a', 'b', 'c']);
});

test('streaming returns concurrent results in call order', function () {
    $agent = prepare([new EchoTool('a'), new EchoTool('b')], [
        toolStep([['call-b', 'b'], ['call-a', 'a']]),
        textStep('Both ready.'),
    ]);

    $results = null;

    $agent->stream('Run the tools')->then(function ($response) use (&$results): void {
        $results = resultsOf($response);
    })->each(fn () => null);

    expect($results)->toBe(['result:b', 'result:a']);
});

test('approval waits, then a resumed batch runs the approved calls in order', function () {
    $gatedA = (new EchoTool('a'))->requireApproval('Check a.');
    $open = new EchoTool('b');
    $gatedC = (new EchoTool('c'))->requireApproval('Check c.');

    $agent = prepare([$gatedA, $open, $gatedC], [
        toolStep([['call-a', 'a'], ['call-b', 'b'], ['call-c', 'c']]),
        textStep('Approved.'),
    ]);

    $paused = $agent->withMessages([])->prompt('Run the tools');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->pluck('tool')->all())->toBe(['a', 'c'])
        ->and(resultsOf($paused))->toBe(['result:b'])
        ->and(ProbeTool::$trace)->toBe(['b']);

    $resumed = $agent->withMessages($paused->messages)->prompt(Decisions::from([
        'call-a' => true,
        'call-c' => true,
    ]));

    expect($resumed->text)->toBe('Approved.')
        ->and(resultsOf($resumed))->toBe(['result:b', 'result:a', 'result:c'])
        ->and(ProbeTool::$trace)->toBe(['b', 'a', 'c']);
});

test('a failed approved call stays model text and its sibling still returns', function () {
    $agent = prepare([
        (new EchoTool('a'))->requireApproval(),
        (new EchoTool('b', throws: true))->requireApproval(),
    ], [
        toolStep([['call-a', 'a'], ['call-b', 'b']]),
        textStep('Settled.'),
    ]);

    $paused = $agent->withMessages([])->prompt('Run the tools');

    $resumed = $agent->withMessages($paused->messages)->prompt(Decisions::from([
        'call-a' => true,
        'call-b' => true,
    ]));

    expect($resumed->text)->toBe('Settled.')
        ->and(resultsOf($resumed))->toBe(['result:a', 'The tool call failed: kaboom:b'])
        ->and($resumed->toolResults[1]->failed)->toBeTrue()
        ->and(ProbeTool::$trace)->toBe(['a', 'b']);
});

test('two opted-in calls overlap under the process driver', function () {
    ProcessAutoload::ensure();
    config(['concurrency.default' => 'process']);

    $gate = sys_get_temp_dir().'/parallel-tools-'.uniqid('', true);

    try {
        $response = promptWith([
            new EchoTool('a', gate: $gate),
            new EchoTool('b', gate: $gate),
        ], [
            toolStep([['call-a', 'a'], ['call-b', 'b']]),
            textStep('Done.'),
        ]);

        expect(resultsOf($response))->toBe(['overlapped', 'overlapped']);
    } finally {
        @unlink($gate);
    }
});

/**
 * @param  list<ProbeTool>  $tools
 * @param  list<StepResponse>  $steps
 */
function promptWith(array $tools, array $steps): AgentResponse
{
    return prepare($tools, $steps)->prompt('Run the tools');
}

/**
 * @param  list<ProbeTool>  $tools
 * @param  list<StepResponse>  $steps
 */
function promptCatching(array $tools, array $steps): ?Throwable
{
    try {
        prepare($tools, $steps)->prompt('Run the tools');
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * @param  list<ProbeTool>  $tools
 * @param  list<StepResponse>  $steps
 */
function prepare(array $tools, array $steps): ParallelProbeAgent
{
    $provider = app(AiManager::class)->textProvider();
    $provider->useTextGateway(new ScriptedTextGateway($steps));

    expect($provider->textGenerationLoop())->toBeInstanceOf(ParallelTextGenerationLoop::class);

    return new ParallelProbeAgent($tools);
}

/**
 * @param  list<array{0: string, 1: string}>  $calls
 */
function toolStep(array $calls): StepResponse
{
    return new StepResponse(
        '',
        array_map(
            fn (array $call): ToolCall => new ToolCall($call[0], $call[1], []),
            $calls,
        ),
        FinishReason::ToolCalls,
        new TextUsage,
        new Meta('scripted', 'test'),
    );
}

function textStep(string $text): StepResponse
{
    return new StepResponse($text, [], FinishReason::Stop, new TextUsage, new Meta('scripted', 'test'));
}

function resultsOf(AgentResponse $response): array
{
    return $response->toolResults->map(fn ($result): string => (string) $result->result)->all();
}

/**
 * @param  list<array{0: string, 1: mixed}>  $invoked
 * @param  list<array{0: string, 1: class-string, 2: string}>  $failed
 */
function listen(array &$invoked, array &$failed): void
{
    Event::listen(function (ToolInvoked $event) use (&$invoked): void {
        $invoked[] = [$event->tool->name(), $event->result];
    });

    Event::listen(function (ToolFailed $event) use (&$failed): void {
        $failed[] = [$event->tool->name(), $event->exception::class, $event->exception->getMessage()];
    });
}

function reverseDriver(): void
{
    Concurrency::extend('reverse', fn (): Driver => new class implements Driver
    {
        public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
        {
            $results = [];

            foreach (array_reverse($tasks, true) as $key => $task) {
                $results[$key] = $task();
            }

            return $results;
        }

        public function defer(Closure|array $tasks): DeferredCallback
        {
            return defer(fn (): array => $this->run($tasks));
        }
    });

    config(['concurrency.default' => 'reverse']);
}

class ParallelProbeAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  list<ProbeTool>  $toolList
     */
    public function __construct(public array $toolList) {}

    public function instructions(): string
    {
        return 'Call the tools.';
    }

    public function tools(): iterable
    {
        return $this->toolList;
    }
}
