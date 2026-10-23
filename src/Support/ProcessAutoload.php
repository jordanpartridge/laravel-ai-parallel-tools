<?php

namespace JordanPartridge\ParallelTools\Support;

/**
 * The process driver boots `artisan` from the application base path.
 * A Testbench skeleton has no vendor directory of its own, so point it
 * at this package's autoload before any child process starts.
 */
class ProcessAutoload
{
    public static function ensure(): void
    {
        $link = base_path('vendor/autoload.php');

        if (is_file($link)) {
            return;
        }

        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';

        if (! is_file($autoload)) {
            return;
        }

        $directory = dirname($link);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (! is_file($link)) {
            symlink($autoload, $link);
        }
    }
}
