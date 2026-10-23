<?php

namespace JordanPartridge\ParallelTools\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use JordanPartridge\ParallelTools\Demo\DemoAgent;
use JordanPartridge\ParallelTools\Demo\DemoProbe;
use JordanPartridge\ParallelTools\Demo\ProbeFailed;
use JordanPartridge\ParallelTools\Demo\ScriptedTextGateway;
use JordanPartridge\ParallelTools\Exceptions\MultipleParallelToolFailures;
use JordanPartridge\ParallelTools\Support\ProcessAutoload;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\ToolNameResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'parallel-tools:demo')]
class ParallelToolsDemoCommand extends Command
{
    protected $signature = 'parallel-tools:demo
        {scenario=all-succeed : all-succeed, one-fail, several-fail, or mixed}
        {--mode=parallel : parallel (process driver) or sequential (sync driver)}
        {--json : Print one JSON object to stdout and nothing else}';

    protected $description = 'Run 42 scripted tool calls and compare wall time with the summed delays';

    public function handle(): int
    {
        $scenario = (string) $this->argument('scenario');
        $mode = (string) $this->option('mode');
        $json = (bool) $this->option('json');

        if (! in_array($mode, ['parallel', 'sequential'], true)) {
            $this->error('Unknown mode ['.$mode.']. Use parallel or sequential.');

            return self::FAILURE;
        }

        try {
            DemoProbe::failingNumbers($scenario);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage().' Use all-succeed, one-fail, several-fail, or mixed.');

            return self::FAILURE;
        }

        $driver = $mode === 'sequential' ? 'sync' : 'process';

        if ($driver === 'process') {
            ProcessAutoload::ensure();
        }

        config(['concurrency.default' => $driver]);

        $probes = DemoProbe::plan($scenario);
        $calls = array_map(
            fn (DemoProbe $probe): ToolCall => new ToolCall('call-'.$probe->number, $probe->name(), []),
            $probes,
        );

        $meta = new Meta('scripted', 'parallel-demo');

        app(AiManager::class)->textProvider()->useTextGateway(new ScriptedTextGateway([
            new StepResponse('', $calls, FinishReason::ToolCalls, new TextUsage, $meta),
            new StepResponse('42 probes finished.', [], FinishReason::Stop, new TextUsage, $meta),
        ]));

        /** @var list<string> $invoked */
        $invoked = [];
        /** @var list<string> $failed */
        $failed = [];

        Event::listen(function (ToolInvoked $event) use (&$invoked): void {
            $invoked[] = (string) $event->result;
        });

        Event::listen(function (ToolFailed $event) use (&$failed): void {
            $failed[] = ToolNameResolver::resolve($event->tool).': '.$event->exception::class.': '.$event->exception->getMessage();
        });

        $started = hrtime(true);
        $response = null;
        $thrown = null;

        try {
            $response = (new DemoAgent($probes))->prompt('Run all 42 probes.');
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $wallMs = (hrtime(true) - $started) / 1e6;
        $sumMs = DemoProbe::summedDelayMicroseconds() / 1000;
        $expectedInvoked = array_values(array_map(
            fn (DemoProbe $probe): string => $probe->name(),
            array_filter($probes, fn (DemoProbe $probe): bool => ! $probe->fails),
        ));
        $ordered = $invoked === $expectedInvoked;
        $leaked = $this->failureLeakedIntoResults($response);
        $ok = $ordered && ! $leaked && $this->outcomeMatches($scenario, $thrown, $invoked, $failed);

        if ($json) {
            $this->output->writeln((string) json_encode([
                'calls' => DemoProbe::COUNT,
                'mode' => $mode,
                'driver' => $driver,
                'scenario' => $scenario,
                'wall_ms' => (float) $this->ms($wallMs),
                'summed_delay_ms' => (float) $this->ms($sumMs),
                'invoked' => count($invoked),
                'failed' => count($failed),
                'results_in_call_order' => $ordered,
                'threw' => $this->thrownName($thrown),
            ], JSON_THROW_ON_ERROR));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line('parallel-tools demo');
        $this->line('scenario: '.$scenario);
        $this->line('driver: '.$driver);
        $this->line('calls: '.DemoProbe::COUNT);
        $this->line('summed_delay_ms: '.$this->ms($sumMs));
        $this->line('wall_ms: '.$this->ms($wallMs));
        $this->line('speedup: '.number_format($sumMs / max($wallMs, 0.1), 2, '.', '').'x');
        $this->line('invoked: '.count($invoked));
        $this->line('failed: '.count($failed));
        $this->line('results_in_call_order: '.($ordered ? 'yes' : 'no'));
        $this->line('failures_as_tool_text: '.($leaked ? 'yes' : 'no'));

        if ($invoked !== []) {
            $this->line('first_result: '.$invoked[0]);
            $this->line('last_result: '.$invoked[array_key_last($invoked)]);
        }

        foreach ($failed as $line) {
            $this->line('tool_failed: '.$line);
        }

        if ($thrown instanceof MultipleParallelToolFailures) {
            $this->line('threw: MultipleParallelToolFailures');
            $this->line('thrown_tools: '.implode(', ', $thrown->tools()));
            $this->line('message: '.$thrown->getMessage());
        } elseif ($thrown instanceof ProbeFailed) {
            $this->line('threw: ProbeFailed');
            $this->line('probe: '.$thrown->probe);
            $this->line('message: '.$thrown->getMessage());
        } elseif ($thrown instanceof Throwable) {
            $this->line('threw: '.$thrown::class);
            $this->line('message: '.$thrown->getMessage());
        } else {
            $this->line('threw: nothing');
            $this->line('answer: '.$response->text);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function thrownName(?Throwable $thrown): ?string
    {
        if ($thrown === null) {
            return null;
        }

        if ($thrown instanceof MultipleParallelToolFailures) {
            return 'MultipleParallelToolFailures';
        }

        if ($thrown instanceof ProbeFailed) {
            return 'ProbeFailed';
        }

        return $thrown::class;
    }

    /**
     * @param  list<string>  $invoked
     * @param  list<string>  $failed
     */
    private function outcomeMatches(string $scenario, ?Throwable $thrown, array $invoked, array $failed): bool
    {
        $failing = DemoProbe::failingNumbers($scenario);

        if (count($invoked) !== DemoProbe::COUNT - count($failing) || count($failed) !== count($failing)) {
            return false;
        }

        if ($scenario === 'all-succeed') {
            return $thrown === null;
        }

        if ($scenario === 'one-fail') {
            return $thrown instanceof ProbeFailed && $thrown->probe === $failing[0];
        }

        return $thrown instanceof MultipleParallelToolFailures
            && $thrown->tools() === array_map(fn (int $number): string => sprintf('probe-%02d', $number), $failing);
    }

    private function failureLeakedIntoResults(mixed $response): bool
    {
        if (! is_object($response) || ! isset($response->toolResults)) {
            return false;
        }

        foreach ($response->toolResults as $result) {
            $text = (string) $result->result;

            if (str_contains($text, 'Probe ') && str_contains($text, 'failed')) {
                return true;
            }
        }

        return false;
    }

    private function ms(float $milliseconds): string
    {
        return number_format($milliseconds, 1, '.', '');
    }
}
