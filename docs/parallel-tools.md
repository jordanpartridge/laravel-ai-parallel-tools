# Parallel Tools

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Defining Concurrent Tools](#defining-concurrent-tools)
    - [When to Mark a Tool Concurrent](#when-to-mark-a-tool-concurrent)
- [How the Model Calls Tools](#how-the-model-calls-tools)
    - [Providers](#providers)
    - [Turning Parallel Calls Off](#turning-parallel-calls-off)
    - [How a Step Runs](#how-a-step-runs)
    - [Failures](#failures)
- [Concurrency Drivers](#concurrency-drivers)
    - [Serialization](#serialization)
- [Testing](#testing)
- [Benchmarks](#benchmarks)

<a name="introduction"></a>
## Introduction

[Parallel Tools](https://github.com/jordanpartridge/laravel-ai-parallel-tools) runs the tool calls from one Laravel AI step at the same time, for tools you mark with the `Concurrent` attribute.

The model chooses the calls. A step can contain several of them. Two or more marked calls in that step run through [Laravel Concurrency](https://laravel.com/framework/docs/concurrency). Every other call runs in order. The prompt, the schemas, and the calls the model returns stay the same.

<a name="requirements"></a>
## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- [`laravel/ai`](https://laravel.com/framework/docs/ai-sdk) 1.3 or newer

<a name="installation"></a>
## Installation

Install the package with Composer:

```shell
composer require jordanpartridge/laravel-ai-parallel-tools
```

Package discovery registers `JordanPartridge\ParallelTools\ParallelToolsServiceProvider`. This package has no configuration file.

`prompt()` and `stream()` resolve the AI manager, so they use the concurrent loop. A provider you construct with `new`, outside that manager, keeps Laravel's own loop.

<a name="configuration"></a>
## Configuration

There is no configuration file for this package. Concurrent calls use the driver in Laravel's concurrency config. The default driver is `process`, from the `CONCURRENCY_DRIVER` environment variable.

To change it, publish Laravel's concurrency config and set `default` to `process`, `fork`, or `sync`:

```shell
php artisan config:publish concurrency
```

```php
'default' => env('CONCURRENCY_DRIVER', 'process'),
```

You may also set the driver for one request:

```php
config(['concurrency.default' => 'sync']);
```

The [concurrency drivers](#concurrency-drivers) below describe what each value does to a tool call.

<a name="defining-concurrent-tools"></a>
## Defining Concurrent Tools

Create a tool the same way you create any [Laravel AI tool](https://laravel.com/framework/docs/ai-sdk#tools). Add `#[Concurrent]` to the class when that tool is safe to run beside other marked calls from the same step.

The tool name sent to the model is the class basename. Define `name(): string` when you want a different name.

```php
<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JordanPartridge\ParallelTools\Attributes\Concurrent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

#[Concurrent]
class WeatherLookup implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Look up the current weather for a city.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return '72F and clear in '.$request['city'];
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->required(),
        ];
    }
}
```

```php
<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JordanPartridge\ParallelTools\Attributes\Concurrent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

#[Concurrent]
class FlightStatus implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Look up the status of a flight by its number.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $request['flight'].' is on time.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'flight' => $schema->string()->required(),
        ];
    }
}
```

Return the tools from an agent that implements `HasTools` and uses the `Promptable` trait. Prompt the agent the same way you prompt any other agent:

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Tools\FlightStatus;
use App\Ai\Tools\WeatherLookup;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class TravelAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You help travelers. Use your tools, then answer.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new WeatherLookup,
            new FlightStatus,
        ];
    }
}
```

```php
use App\Ai\Agents\TravelAgent;

$response = (new TravelAgent)->prompt(
    'What is the weather in Paris, and is flight BA117 on time?'
);

return (string) $response;
```

> [!NOTE]
> Put `#[Concurrent]` on the class you pass to the agent. PHP does not inherit attributes, and this package reads the attribute from that class only.

<a name="when-to-mark-a-tool-concurrent"></a>
### When to Mark a Tool Concurrent

Mark a tool when each call can finish on its own. Weather for two cities, or a weather lookup beside a flight lookup, are separate calls. They do not need each other's results.

Leave the attribute off when a call needs a result from another call in the same step. Leave it off when the calls write the same row, the same file, or any other shared state. An unmarked tool runs on its own, in its place in the model's list, and it splits the marked calls on either side into separate groups.

> [!WARNING]
> Two marked tools that write the same state can overlap. The attribute is a promise that the overlap is safe. This package does not lock anything for you.

<a name="how-the-model-calls-tools"></a>
## How the Model Calls Tools

An agent loop is a series of requests. On each request the agent sends its instructions, the conversation so far, and the tools it is willing to run. Each tool is sent as its name, description, and JSON schema. The model then answers with text, or with one or more tool calls.

[Laravel AI](https://laravel.com/framework/docs/ai-sdk#tools) runs the calls from that response and sends the results back. The model may answer, or it may call more tools. That repeats until the model stops, or until the agent reaches its step limit.

You set the limit with the [`MaxSteps`](https://laravel.com/framework/docs/ai-sdk#agent-configuration) attribute. If you leave it off, the AI SDK derives one: `round(1.5 × tool count)`, and never more than 25. Each tool counts as one. A tool hidden behind tool search counts as the number of tools inside it. An agent with no tools gets 5 steps. `RepairToolCalls` adds one step only when the limit is derived. An explicit `MaxSteps` value is used as written, with a floor of 1.

```php
use Laravel\Ai\Attributes\MaxSteps;

#[MaxSteps(10)]
class TravelAgent implements Agent, HasTools
{
    use Promptable;

    // ...
}
```

On the last allowed step, Laravel records each pending tool call as `The agent reached its maximum number of steps without running this tool call.` The calls stay unexecuted.

Returning several tool calls in one response is the provider's parallel tool calling. [OpenAI](https://developers.openai.com/api/docs/guides/function-calling#parallel-function-calling) calls it parallel function calling. [Anthropic](https://platform.claude.com/docs/en/agents-and-tools/tool-use/parallel-tool-use) calls it parallel tool use. The provider's [tool-use loop](https://platform.claude.com/docs/en/agents-and-tools/tool-use/how-tool-use-works) is the same shape: the app sends the tools, the model may return several `tool_use` blocks, the app runs them, and the app sends the results back. [Gemini](https://ai.google.dev/gemini-api/docs/function-calling#parallel_function_calling) documents the same idea as parallel function calling.

> [!NOTE]
> This package does not ask the model to emit several calls. If the model returns one call, one call runs. Marking a tool concurrent has no effect until a step contains at least two marked calls.

<a name="providers"></a>
### Providers

These providers document that a model may return several tool calls in one response:

- OpenAI, as [parallel function calling](https://developers.openai.com/api/docs/guides/function-calling#parallel-function-calling). It is allowed unless you set `parallel_tool_calls` to `false`.
- Anthropic, as [parallel tool use](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview). It is allowed unless `tool_choice.disable_parallel_tool_use` is true. See [Parallel tool use](https://platform.claude.com/docs/en/agents-and-tools/tool-use/parallel-tool-use).
- Gemini, as [parallel function calling](https://ai.google.dev/gemini-api/docs/function-calling#parallel_function_calling).

`laravel/ai` 1.3 will pass on every tool call a provider returns in a step. Whether any other provider emits more than one call is that provider's behavior. This package runs the marked calls from whatever list comes back.

<a name="turning-parallel-calls-off"></a>
### Turning Parallel Calls Off

`laravel/ai` 1.3 has no method of its own for this. Agents that implement [`HasProviderOptions`](https://laravel.com/framework/docs/ai-sdk#provider-options) can forward the provider's field. The OpenAI gateway, and the Azure gateway that builds the same request, merge `providerOptions()` into the JSON body. Anthropic merges it the same way, so a `tool_choice` key replaces the `tool_choice` the SDK had set.

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class TravelAgent implements Agent, HasTools, HasProviderOptions
{
    use Promptable;

    /**
     * Get provider-specific generation options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::OpenAI, Lab::Azure => [
                'parallel_tool_calls' => false,
            ],
            Lab::Anthropic => [
                'tool_choice' => [
                    'type' => 'auto',
                    'disable_parallel_tool_use' => true,
                ],
            ],
            default => [],
        };
    }
}
```

> [!WARNING]
> The Anthropic `tool_choice` array above replaces the SDK's `tool_choice` for that request, including a choice you set with `ToolChoice` and the choice used for structured output. This version of the SDK does not expose a switch for Gemini.

<a name="how-a-step-runs"></a>
### How a Step Runs

The model returns the calls for one step. This package walks that list in order. A run of two or more `#[Concurrent]` tools is handed to Laravel's concurrency driver and those calls overlap. One marked tool runs inline, in the current process. An unmarked tool, a sub-agent, or a tool that still needs approval runs on its own and ends the concurrent group. A sub-agent is never part of a concurrent group. While streaming, it stays on Laravel's path so its events can yield between calls.

When the group finishes, the results are applied in the model's call order. `ToolInvoked` and `ToolFailed` are dispatched in that same order, in the parent process. `handle()` itself runs in the worker when the driver is `process` or `fork`.

```mermaid
sequenceDiagram
    participant Agent
    participant Model
    participant Loop

    Agent->>Model: Prompt and tool schemas
    Model-->>Loop: N tool calls in one step
    Note over Loop: Adjacent concurrent calls run together
    Note over Loop: Every other call runs in order
    Loop->>Model: Results in the original call order
    Model-->>Agent: Another step, until the step limit
```

There is no cap on how many marked calls run together.

A tool that is waiting for approval stays out of the group. After you resume the agent, the approved calls that are marked concurrent run together. During that resume, a thrown `handle()` becomes the tool text `The tool call failed: …` and the run continues. That is Laravel's resume rule.

<a name="failures"></a>
### Failures

A normal step keeps a thrown `handle()` as an exception. It is not rewritten into text for the model.

| What happened | Result |
| --- | --- |
| Every call returns | The results go back to the model in call order. |
| One call throws | That exception is rethrown. `ToolInvoked` has already fired for each sibling that returned. |
| Two or more throw | `JordanPartridge\ParallelTools\Exceptions\MultipleParallelToolFailures`. `tools()` and `exceptions()` follow call order. `getPrevious()` is the first exception. None of those errors are sent to the model as text. |
| `ValidationException` | The validation message is the tool result, so the model can correct the arguments. The call is not a failure. |

On the `process` and `fork` drivers, the exception is rebuilt in the parent from its class, message, code, and public constructor arguments. An exception that cannot be rebuilt is thrown as a `RuntimeException`. The `sync` driver rethrows the original instance when PHP can serialize it.

<a name="concurrency-drivers"></a>
## Concurrency Drivers

The package reads `config('concurrency.default')`, which defaults to `process`. The three drivers are Laravel's. They are documented in [Concurrency](https://laravel.com/framework/docs/concurrency).

| Driver | What a batch does |
| --- | --- |
| `process` | The default. Each call is a serialized closure run by `php artisan invoke-serialized-closure` in its own PHP process. The child boots the application, so `handle()` may use the container, the database, and HTTP. |
| `fork` | A forked CLI process, via `spatie/fork`. It is faster than `process`, and it is available only in the CLI. Install it with `composer require spatie/fork`. |
| `sync` | Runs the calls in this process, in call order. Nothing is overlapped. Use this in tests, and whenever you want to turn concurrency off. |

A batch of one call skips the driver and runs inline. Two or more marked calls use the driver above.

<a name="serialization"></a>
### Serialization

The `process` driver serializes the closure, and the tool instance is captured by that closure. The tool and the value returned from `handle()` have to be serializable. Resolve the container, a database connection, or an HTTP client inside `handle()` rather than storing them on the tool. The child process boots your application, so that resolution works there.

The `fork` driver returns the task value from another process, so the value from `handle()` has to be serializable as well. The `sync` driver stays in the current process and keeps the tool in that process.

> [!WARNING]
> A tool that holds a closure, a resource, or another value PHP cannot serialize will fail under the `process` driver.

<a name="testing"></a>
## Testing

Set the concurrency driver to `sync` in tests. Marked calls then run in the test process, in call order, and the test stays in one PHP process. Add this to `phpunit.xml`:

```xml
<php>
    <env name="CONCURRENCY_DRIVER" value="sync"/>
</php>
```

You may set it for a single test instead:

```php
config(['concurrency.default' => 'sync']);
```

Fake the model's reply with the agent's `fake()` method. A string response is returned as text, and the tools are not called. The `sync` driver matters for tests that do reach `handle()`.

This package's own suite is `composer check`. That runs Pint, PHPStan at level 6 on `src`, and Pest. GitHub Actions runs those checks on PHP 8.3 and 8.4. See [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

<a name="benchmarks"></a>
## Benchmarks

These times are from the `all-succeed` demo: 42 tool calls, each sleeping a seeded 120–199ms. The machine was a Ryzen AI MAX+ 395, PHP 8.5, `laravel/ai` v1.3.0, 10 runs per mode after 2 warmups, with no errors.

| Mode | p50 wall time |
| --- | --- |
| Parallel (`process`) | 400.1 ms |
| Sequential (`sync`) | 6720.2 ms |

That is about 16.8x faster at p50. Counting PHP and Testbench startup in each run, it is about 14x. The demo uses a fake gateway and makes no network call, so the numbers measure how the tool calls are scheduled, rather than model latency.

`php artisan parallel-tools:demo all-succeed` runs that scripted step. `--mode=sequential` is the same 42 calls with the driver set to `sync`.
