<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('demo json has the same shape in parallel and sequential modes', function () {
    $walls = [];

    foreach (['parallel' => 'process', 'sequential' => 'sync'] as $mode => $driver) {
        Http::fake();

        $exit = Artisan::call('parallel-tools:demo', [
            'scenario' => 'all-succeed',
            '--mode' => $mode,
            '--json' => true,
        ]);

        Http::assertNothingSent();

        $trimmed = trim(Artisan::output());
        $decoded = json_decode($trimmed, true);

        expect($exit)->toBe(0)
            ->and($trimmed)->not->toContain("\n")
            ->and($trimmed)->not->toContain('parallel-tools demo')
            ->and($decoded)->toBeArray()
            ->and(array_keys($decoded))->toBe([
                'calls',
                'mode',
                'driver',
                'scenario',
                'wall_ms',
                'summed_delay_ms',
                'invoked',
                'failed',
                'results_in_call_order',
                'threw',
            ])
            ->and($decoded['calls'])->toBe(42)
            ->and($decoded['mode'])->toBe($mode)
            ->and($decoded['driver'])->toBe($driver)
            ->and($decoded['scenario'])->toBe('all-succeed')
            ->and(is_int($decoded['wall_ms']) || is_float($decoded['wall_ms']))->toBeTrue()
            ->and((float) $decoded['wall_ms'])->toBeGreaterThan(0)
            ->and((float) $decoded['summed_delay_ms'])->toBe(6711.0)
            ->and($decoded['invoked'])->toBe(42)
            ->and($decoded['failed'])->toBe(0)
            ->and($decoded['results_in_call_order'])->toBeTrue()
            ->and($decoded['threw'])->toBeNull();

        $walls[$mode] = (float) $decoded['wall_ms'];
    }

    expect($walls['sequential'])->toBeGreaterThan($walls['parallel']);
});
