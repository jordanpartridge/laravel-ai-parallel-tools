# jordanpartridge/laravel-ai-parallel-tools

Concurrent tool calls for [Laravel AI](https://github.com/laravel/ai) agents. When a model step asks for several tools, the ones you mark run together, and their results go back in the original call order.

Laravel AI pull request #759, which proposed this in core, was closed, and releasing it as a package was the suggested route.

The package works with stock `laravel/ai` ^1.3. It does not fork the framework and it does not edit framework files.

## Requirements

- PHP 8.3 or 8.4
- Laravel 12 or 13 (`illuminate/concurrency`, `illuminate/support`)
- `laravel/ai` ^1.3

## Install

```bash
composer require jordanpartridge/laravel-ai-parallel-tools
```

Laravel discovers `JordanPartridge\ParallelTools\ParallelToolsServiceProvider`. After that, `Agent::prompt()` and `Agent::stream()` use the parallel tool loop.

This repository is not published to Packagist. Until it is, point Composer at the Git repository:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/jordanpartridge/laravel-ai-parallel-tools"
        }
    ]
}
```

## Usage

Mark each tool that is safe to overlap. Unmarked tools stay on the stock path and split the step, so a tool with side effects keeps running alone.

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use JordanPartridge\ParallelTools\Attributes\Concurrent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

#[Concurrent]
class WeatherLookup implements Tool
{
    public function name(): string
    {
        return 'weather';
    }

    public function description(): string
    {
        return 'Look up the current weather for a city.';
    }

    public function handle(Request $request): string
    {
        return '72F and clear';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->required(),
        ];
    }
}
```

Put `#[Concurrent]` on the tool class itself. PHP does not inherit attributes, and this package reads the attribute from that class only.

Two or more marked tools in one model step run through [Laravel Concurrency](https://laravel.com/docs/concurrency). That is the same facility Laravel AI's own concurrency discussion used, and it follows `config('concurrency.default')`. The default `process` driver overlaps blocking work such as HTTP and `usleep`, because each call runs in its own process. The `sync` driver runs the batch in this process, in call order, which is what the test suite uses. One marked tool runs inline, with no extra process. There is no concurrency cap.

Results are applied in the model's call order after the whole batch finishes. `ToolInvoked` and `ToolFailed` are dispatched in that same order.

## Demo

The package includes a 42-call demo. A fake, offline gateway returns one step with 42 tool calls, then a final answer. Each probe sleeps a seeded 120–199ms. No network and no API key.

From an application that has the package installed:

```bash
php artisan parallel-tools:demo all-succeed
php artisan parallel-tools:demo one-fail
php artisan parallel-tools:demo several-fail
php artisan parallel-tools:demo mixed
```

From this repository:

```bash
composer install
php vendor/bin/testbench parallel-tools:demo all-succeed
php vendor/bin/testbench parallel-tools:demo one-fail
php vendor/bin/testbench parallel-tools:demo several-fail
php vendor/bin/testbench parallel-tools:demo mixed
```

`--mode=parallel` is the default and uses Laravel's `process` concurrency driver. `--mode=sequential` runs the same 42 marked calls with the driver switched to `sync`, so the only difference is concurrency. `--json` prints one JSON object to stdout and nothing else. The gateway stays scripted: no API key and no HTTP.

```bash
php vendor/bin/testbench parallel-tools:demo all-succeed --mode=parallel --json
php vendor/bin/testbench parallel-tools:demo all-succeed --mode=sequential --json
```

```json
{"calls":42,"mode":"parallel","driver":"process","scenario":"all-succeed","wall_ms":1303.4,"summed_delay_ms":6711,"invoked":42,"failed":0,"results_in_call_order":true,"threw":null}
```

```json
{"calls":42,"mode":"sequential","driver":"sync","scenario":"all-succeed","wall_ms":6725.9,"summed_delay_ms":6711,"invoked":42,"failed":0,"results_in_call_order":true,"threw":null}
```

`all-succeed` is the default. The command exits `0` when the outcome matches the scenario. Wall time depends on the machine; this run was PHP 8.3 with the `process` driver. Summed delay is 6711.0ms. Overlapping the 42 sleeps finished in about 1.2–1.5s (about 4.5–5.4x).

```text
parallel-tools demo
scenario: all-succeed
driver: process
calls: 42
summed_delay_ms: 6711.0
wall_ms: 1469.4
speedup: 4.57x
invoked: 42
failed: 0
results_in_call_order: yes
failures_as_tool_text: no
first_result: probe-01
last_result: probe-42
threw: nothing
answer: 42 probes finished.
```

`one-fail` throws the original `ProbeFailed` from probe 7. The other 41 results are recorded in call order, and the error is not rewritten into tool text.

```text
parallel-tools demo
scenario: one-fail
driver: process
calls: 42
summed_delay_ms: 6711.0
wall_ms: 1264.9
speedup: 5.31x
invoked: 41
failed: 1
results_in_call_order: yes
failures_as_tool_text: no
first_result: probe-01
last_result: probe-42
tool_failed: probe-07: JordanPartridge\ParallelTools\Demo\ProbeFailed: Probe 7 failed.
threw: ProbeFailed
probe: 7
message: Probe 7 failed.
```

`several-fail` (probes 3, 10, 21, and 40) and `mixed` (probes 8, 19, and 33) throw `MultipleParallelToolFailures`. Successes are still recorded. The message lists every failure, in call order, and says those errors were not returned to the model as text.

```text
threw: MultipleParallelToolFailures
thrown_tools: probe-03, probe-10, probe-21, probe-40
message: 4 tools failed in one step. None of those errors were returned to the model as text. probe-03: JordanPartridge\ParallelTools\Demo\ProbeFailed: Probe 3 failed.; probe-10: JordanPartridge\ParallelTools\Demo\ProbeFailed: Probe 10 failed.; probe-21: JordanPartridge\ParallelTools\Demo\ProbeFailed: Probe 21 failed.; probe-40: JordanPartridge\ParallelTools\Demo\ProbeFailed: Probe 40 failed.
```

## Errors

| Batch | What happens |
| --- | --- |
| Every tool returns | Results go back to the model in call order. |
| One tool throws | That exception is rethrown. Successes are already `ToolInvoked`. The failure is `ToolFailed`. |
| Two or more throw | `JordanPartridge\ParallelTools\Exceptions\MultipleParallelToolFailures`. `tools()` and `exceptions()` follow call order. `getPrevious()` is the first exception. |
| Successes and one failure | Every success is delivered, then the original exception is thrown. |
| `ValidationException` | Stock behavior: the message is tool text so the model can retry. It is not a failed call. |
| Approval resume | Stock behavior: a thrown handler becomes `The tool call failed: …` with `failed: true`. Siblings still return and the run continues. |

A normal step never turns a thrown handler into tool text. The process concurrency driver drops sibling results when a child process exits with an exception, so each task catches its own failure and returns a payload. The parent then records every outcome and throws once. In-process drivers (`sync`) rethrow the same exception instance. The `process` driver rebuilds it from the class, message, code, and public constructor properties. A custom exception that cannot be rebuilt is wrapped in `RuntimeException` and still thrown.

## How it attaches

`HasTextGateway::textGenerationLoop()` constructs `TextGenerationLoop` with `new`. The manager is what agents resolve, so the service provider extends the `AiManager` binding. Each text provider that comes back is promoted to a generated subclass whose `textGenerationLoop()` returns `ParallelTextGenerationLoop`.

That loop groups adjacent `#[Concurrent]` tools, runs the group, then settles results in call order. Streaming still emits each final `ToolResult` event from those results. Sub-agent tools (`AgentTool`) stay on the streaming path so their events can yield between calls. Tools that need approval are held until resume, then a resumed group runs together.

## Limitations

- Agents that construct a provider with `new`, outside `AiManager`, keep the stock loop. `Agent::prompt()` and `Agent::stream()` resolve the manager, so they are covered.
- A `final` provider class cannot be subclassed, so it cannot be promoted.
- Replacing the `AiManager` binding keeps drivers and custom creators. Methods that exist only on some other manager subclass are not kept.
- Events for a concurrent batch are dispatched in call order after the batch returns, in the parent process. `handle()` itself runs in the worker.
- The `process` driver serializes the tool into a fresh application process. The tool object and its return value have to be serializable. The child boots the app, so `handle()` can use the container, the database, and HTTP.
- `AgentTool` calls stay sequential on the stream path.
- The attribute lives in this package: `JordanPartridge\ParallelTools\Attributes\Concurrent`.
- The last step of a run does not run tools concurrently. Stock Laravel AI reports those calls as exhausted.
- There is no concurrency limit.

## Tests

```bash
composer check
```

That runs Pint (`pint --test`), PHPStan (level 6 on `src`), and Pest. The feature tests cover parallel success, call-order results when execution order differs, a single failure, several failures, a mixed batch, streaming, approval resume, a failed approval beside a successful one, real overlap under the `process` driver, and the demo JSON shape in both modes.

GitHub Actions (`.github/workflows/ci.yml`) runs the same checks on PHP 8.3 and 8.4.

## License

MIT. Copyright 2026 Jordan Partridge.
