<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Process\ContextProcess;
use Symfony\Component\Process\Process;

// A 0.5 s budget for a command that would take 10 s: the process gets
// SIGTERM when the budget runs out, SIGKILL after the grace period.
[$context] = Context::background()->withTimeout(0.5);
$process = new Process(['sleep', '10']);
$started = hrtime(true);

try {
    ContextProcess::run($process, $context, gracePeriod: 1.0);
} catch (ContextExpiredException) {
    printf("stopped by the budget after %.1f s; still running: %s\n", (hrtime(true) - $started) / 1e9, var_export($process->isRunning(), true));
}

// Within the budget: the exit code comes back, output reaches the callback.
[$context] = Context::background()->withTimeout(5.0);
$exitCode = ContextProcess::run(
    new Process(['sh', '-c', 'echo converting; exit 0']),
    $context,
    static function (string $type, string $chunk): void {
        echo "[{$type}] {$chunk}";
    },
);
echo "exit code {$exitCode}\n";
