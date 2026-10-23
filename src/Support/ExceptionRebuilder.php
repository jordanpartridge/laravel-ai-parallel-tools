<?php

namespace JordanPartridge\ParallelTools\Support;

use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Carry a tool exception across a concurrency driver without turning it into text.
 */
class ExceptionRebuilder
{
    /**
     * @return array{class: class-string<Throwable>, message: string, code: int, parameters: array<string, mixed>|null, exception?: Throwable}
     */
    public static function capture(Throwable $exception, bool $keepException): array
    {
        $payload = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => (int) $exception->getCode(),
            'parameters' => self::parameters($exception),
        ];

        if (! $keepException) {
            return $payload;
        }

        try {
            serialize($exception);
        } catch (Throwable) {
            return $payload;
        }

        $payload['exception'] = $exception;

        return $payload;
    }

    /**
     * @param  array{class?: class-string<Throwable>, message?: string, code?: int, parameters?: array<string, mixed>|null, exception?: Throwable}  $payload
     */
    public static function restore(array $payload): Throwable
    {
        if (($payload['exception'] ?? null) instanceof Throwable) {
            return $payload['exception'];
        }

        $class = $payload['class'] ?? null;
        $message = (string) ($payload['message'] ?? '');
        $code = (int) ($payload['code'] ?? 0);
        $parameters = $payload['parameters'] ?? null;

        try {
            if (! is_string($class) || ! is_a($class, Throwable::class, true)) {
                return new RuntimeException($message, $code);
            }

            if ($parameters === null) {
                return new RuntimeException($message, $code);
            }

            if ($parameters === []) {
                $constructor = (new ReflectionClass($class))->getConstructor();

                if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $class) {
                    return new $class;
                }

                return new $class($message, $code);
            }

            return new $class(...$parameters);
        } catch (Throwable $exception) {
            return new RuntimeException($message, $code, $exception);
        }
    }

    /**
     * Constructor arguments stored on the exception.
     *
     * Null means it cannot be rebuilt. An empty array means it takes no arguments.
     *
     * @return array<string, mixed>|null
     */
    private static function parameters(Throwable $exception): ?array
    {
        $reflection = new ReflectionClass($exception);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $reflection->getName()) {
            return [];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $property = $reflection->hasProperty($parameter->name) ? $reflection->getProperty($parameter->name) : null;

            if ($property?->isPublic()) {
                $value = $property->getValue($exception);
            } elseif ($parameter->name === 'message') {
                $value = $exception->getMessage();
            } elseif ($parameter->name === 'code') {
                $value = (int) $exception->getCode();
            } elseif ($parameter->isDefaultValueAvailable()) {
                continue;
            } else {
                return null;
            }

            try {
                serialize($value);
            } catch (Throwable) {
                return null;
            }

            $parameters[$parameter->name] = $value;
        }

        return $parameters;
    }
}
