<?php

namespace JordanPartridge\ParallelTools\Demo;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JordanPartridge\ParallelTools\Attributes\Concurrent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * One of the 42 scripted calls. The delay is deterministic from the probe number.
 */
#[Concurrent]
class DemoProbe implements Tool
{
    public const COUNT = 42;

    public function __construct(
        public readonly int $number,
        public readonly int $delayMicroseconds,
        public readonly bool $fails = false,
    ) {}

    /**
     * @return list<int> 1-based probe numbers that throw for the scenario.
     */
    public static function failingNumbers(string $scenario): array
    {
        return match ($scenario) {
            'all-succeed' => [],
            'one-fail' => [7],
            'several-fail' => [3, 10, 21, 40],
            'mixed' => [8, 19, 33],
            default => throw new \InvalidArgumentException("Unknown scenario [{$scenario}]."),
        };
    }

    /**
     * @return list<self>
     */
    public static function plan(string $scenario): array
    {
        $failing = self::failingNumbers($scenario);
        $probes = [];

        for ($number = 1; $number <= self::COUNT; $number++) {
            $probes[] = new self($number, self::delayMicroseconds($number), in_array($number, $failing, true));
        }

        return $probes;
    }

    public static function delayMicroseconds(int $number): int
    {
        return (120 + (($number * 17) % 80)) * 1000;
    }

    public static function summedDelayMicroseconds(): int
    {
        $total = 0;

        for ($number = 1; $number <= self::COUNT; $number++) {
            $total += self::delayMicroseconds($number);
        }

        return $total;
    }

    public function name(): string
    {
        return sprintf('probe-%02d', $this->number);
    }

    public function description(): string
    {
        return 'Deterministic probe '.$this->number.'.';
    }

    public function handle(Request $request): string
    {
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        if ($this->fails) {
            throw new ProbeFailed($this->number);
        }

        return $this->name();
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
