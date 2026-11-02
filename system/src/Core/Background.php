<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Starts `php system/docker.php <command>` as a detached process, so a job does not wait for the next run of the scheduled tasks (every 5
 * minutes in the container). Only an optimisation: where the host forbids starting processes (or TALEA_BACKGROUND=0, as the tests set), the cron run
 * does the job as before.
 */
final class Background
{
    /** @return bool true = the process was started (not: it succeeded) */
    public static function start(string $command): bool
    {
        if (getenv('TALEA_BACKGROUND') === '0' || !function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true) || PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        // under FrankenPHP or FPM PHP_BINARY is not the command line binary: the one beside it, else the one on the path
        $php = PHP_SAPI === 'cli' ? PHP_BINARY : (is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php');
        $process = @proc_open('exec nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(TALEA_ROOT . '/system/docker.php') . ' ' . escapeshellarg($command) . ' >/dev/null 2>&1 &', [], $pipes, TALEA_ROOT);
        if (!is_resource($process)) {
            return false;
        }
        proc_close($process);

        return true;
    }
}
