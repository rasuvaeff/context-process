<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Process;

use Rasuvaeff\Context\Context;
use Symfony\Component\Process\Process;

/**
 * Runs a symfony/process {@see Process} under a rasuvaeff/context budget.
 *
 * The process is polled while it runs: its output reaches `$callback`, its
 * own timeouts are checked, and between polls the context sleeps — so the
 * deadline or a cancellation (also one requested from a signal handler, which
 * wakes the sleep) is noticed within one poll interval. The process is then
 * stopped: SIGTERM, and SIGKILL if it is still running after `$gracePeriod`
 * — on Windows symfony/process terminates it instead. The context's
 * exception is rethrown.
 *
 * @api
 */
final readonly class ContextProcess
{
    private function __construct() {}

    /**
     * @param (callable(string, string): void)|null $callback receives `Process::OUT`/`Process::ERR` and the chunk, as with `Process::run()`
     * @param float $gracePeriod seconds between SIGTERM and SIGKILL; not bounded by the budget, so the process can clean up
     * @param float $pollInterval seconds between polls (output, timeouts, context)
     *
     * @return int the exit code
     *
     * @throws \Rasuvaeff\Context\ContextExpiredException when the deadline passes first (the process is stopped)
     * @throws \Rasuvaeff\Context\ContextCancelledException when the context is cancelled (the process is stopped)
     * @throws \Symfony\Component\Process\Exception\ProcessTimedOutException when the process's own timeout fires
     * @throws \LogicException when the process was already started
     */
    public static function run(
        Process $process,
        Context $context,
        ?callable $callback = null,
        float $gracePeriod = 2.0,
        float $pollInterval = 0.05,
    ): int {
        if (!is_finite($gracePeriod) || $gracePeriod < 0.0) {
            throw new \InvalidArgumentException('Grace period must be a finite non-negative number of seconds');
        }

        if (!is_finite($pollInterval) || $pollInterval <= 0.0) {
            throw new \InvalidArgumentException('Poll interval must be a finite positive number of seconds');
        }

        if ($process->isStarted()) {
            throw new \LogicException('The process is already started');
        }

        $context->assertActive();
        $process->start($callback);

        try {
            // isRunning() reads the pipes and feeds the callback; checkTimeout()
            // enforces the process's own timeouts; sleep() ends with the
            // context's exception when the deadline or a cancellation comes.
            while ($process->isRunning()) {
                $process->checkTimeout();
                $context->sleep($pollInterval);
            }
        } finally {
            // The context's exception, a throwing callback or the process's
            // own timeout unwinds the loop while the process may still run:
            // never leave it behind.
            if ($process->isRunning()) {
                $process->stop($gracePeriod);
            }
        }

        return $process->getExitCode() ?? throw new \LogicException('A finished process has no exit code');
    }
}
