<?php

namespace JordanPartridge\ParallelTools\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ProbeTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /** @var list<string> */
    public static array $trace = [];

    public function __construct(
        public string $label,
        public bool $throws = false,
        public int $code = 7,
        public bool $invalid = false,
        public ?string $gate = null,
    ) {}

    public static function reset(): void
    {
        self::$trace = [];
    }

    public function name(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return 'Probe '.$this->label.'.';
    }

    public function handle(Request $request): string
    {
        self::$trace[] = $this->label;

        if ($this->invalid) {
            throw ValidationException::withMessages([
                'city' => 'The city field is required.',
            ]);
        }

        if ($this->gate !== null) {
            return $this->waitForOverlap($this->gate);
        }

        if ($this->throws) {
            throw new LabeledToolException($this->label, $this->code, [$this->label]);
        }

        return 'result:'.$this->label;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }

    protected function waitForOverlap(string $path): string
    {
        file_put_contents($path, "1\n", FILE_APPEND | LOCK_EX);

        $deadline = microtime(true) + 5;

        do {
            clearstatcache(true, $path);

            if (count(file($path, FILE_IGNORE_NEW_LINES) ?: []) >= 2) {
                return 'overlapped';
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return 'alone';
    }
}
