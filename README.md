# Laravel AI Parallel Tools

Run the tool calls from one [Laravel AI](https://laravel.com/framework/docs/ai-sdk#tools) step concurrently, for tools you mark with `#[Concurrent]`.

The model chooses which tools to call, and it may return several calls in one response. This package changes how those calls are executed. The guide is [Parallel Tools](docs/parallel-tools.md).

## Installation

You need PHP 8.3 or newer, Laravel 12 or 13, and `laravel/ai` 1.3 or newer.

```shell
composer require jordanpartridge/laravel-ai-parallel-tools
```

Package discovery registers `JordanPartridge\ParallelTools\ParallelToolsServiceProvider`. This package has no configuration file.

## Example

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

namespace App\Ai\Agents;

use App\Ai\Tools\WeatherLookup;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class TravelAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You help travelers. Use your tools, then answer.';
    }

    public function tools(): iterable
    {
        return [
            new WeatherLookup,
        ];
    }
}
```

```php
use App\Ai\Agents\TravelAgent;

$response = (new TravelAgent)->prompt('What is the weather in Paris and Oslo?');

return (string) $response;
```

If the model asks for both cities in one step, the two calls run together. The rest of the setup is in the [documentation](docs/parallel-tools.md).

## License

Laravel AI Parallel Tools is open-sourced software licensed under the [MIT license](LICENSE).
