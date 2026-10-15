<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Process\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Process\ContextProcess;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Real processes, real time: the durations are short but the assertions
 * leave wide margins for a loaded CI runner.
 */
#[Test]
#[Covers(ContextProcess::class)]
final class ContextProcessTest
{
    /** POSIX numbers: the SIG* constants exist only with ext-pcntl, which this package does not need. */
    private const int SIGTERM = 15;

    public function aProcessWithinTheBudgetReturnsItsExitCode(): void
    {
        [$context] = Context::background()->withTimeout(5.0);

        Assert::same(ContextProcess::run(new Process(['sh', '-c', 'exit 3']), $context), 3);
    }

    public function withoutADeadlineTheProcessRunsToCompletion(): void
    {
        Assert::same(ContextProcess::run(new Process(['true']), Context::background()), 0);
    }

    public function theOutputReachesTheCallbackIncludingTheTail(): void
    {
        $chunks = [];

        ContextProcess::run(
            new Process(['sh', '-c', 'echo one; sleep 0.1; echo two; echo err >&2']),
            Context::background(),
            static function (string $type, string $chunk) use (&$chunks): void {
                $chunks[] = [$type, $chunk];
            },
            pollInterval: 0.01,
        );

        $out = '';
        $err = '';
        foreach ($chunks as [$type, $chunk]) {
            $type === Process::ERR ? $err .= $chunk : $out .= $chunk;
        }
        Assert::same($out, "one\ntwo\n");
        Assert::same($err, "err\n");
    }

    public function theDeadlineStopsTheProcess(): void
    {
        $process = new Process(['sleep', '10']);
        [$context] = Context::background()->withTimeout(0.2);
        $started = hrtime(as_number: true);

        try {
            ContextProcess::run($process, $context);
        } catch (ContextExpiredException) {
            Assert::false($process->isRunning());
            Assert::true(hrtime(as_number: true) - $started < 3_000_000_000);
            Assert::same($process->getTermSignal(), self::SIGTERM);

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    public function aCancellationStopsTheProcess(): void
    {
        $process = new Process(['sh', '-c', 'echo ready; sleep 10']);
        [$context, $controller] = Context::background()->withTimeout(10.0);

        try {
            ContextProcess::run($process, $context, static function () use ($controller): void {
                $controller->requestCancel(new \RuntimeException('SIGTERM to the worker'));
            });
        } catch (ContextCancelledException $cancelled) {
            Assert::same($cancelled->getPrevious()?->getMessage(), 'SIGTERM to the worker');
            Assert::false($process->isRunning());

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function aProcessIgnoringSigtermIsKilledAfterTheGracePeriod(): void
    {
        $process = new Process(['sh', '-c', "trap '' TERM; echo ready; while :; do sleep 0.05; done"]);
        [$context] = Context::background()->withTimeout(0.3);
        $started = hrtime(as_number: true);

        try {
            ContextProcess::run($process, $context, gracePeriod: 0.4);
        } catch (ContextExpiredException) {
            // Killed only after the grace period: 0.3 s budget + 0.4 s grace.
            // getTermSignal() is unreliable after SIGKILL (symfony/process may
            // close the handle before reading the status), so time it instead.
            $elapsed = hrtime(as_number: true) - $started;
            Assert::false($process->isRunning());
            Assert::true($elapsed >= 650_000_000);
            Assert::true($elapsed < 5_000_000_000);

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    public function theProcessOwnTimeoutIsRethrownUnchanged(): void
    {
        $process = new Process(['sleep', '10']);
        $process->setTimeout(0.2);
        [$context] = Context::background()->withTimeout(10.0);

        try {
            ContextProcess::run($process, $context);
        } catch (ProcessTimedOutException $timedOut) {
            Assert::same($timedOut->getProcess(), $process);
            Assert::false($process->isRunning());
            Assert::false($context->isTerminated());

            return;
        }

        throw new \LogicException('the process timeout was expected');
    }

    public function aThrowingCallbackStillStopsTheProcess(): void
    {
        $process = new Process(['sh', '-c', 'echo ready; sleep 10']);

        try {
            ContextProcess::run($process, Context::background(), static function (): never {
                throw new \DomainException('callback failed');
            });
        } catch (\DomainException) {
            Assert::false($process->isRunning());

            return;
        }

        throw new \LogicException('the callback exception was expected');
    }

    public function aTerminatedContextNeverStartsTheProcess(): void
    {
        $process = new Process(['true']);
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();

        try {
            ContextProcess::run($process, $context);
        } catch (ContextCancelledException) {
            Assert::false($process->isStarted());

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function anAlreadyStartedProcessIsRejected(): void
    {
        $process = new Process(['sleep', '1']);
        $process->start();

        try {
            ContextProcess::run($process, Context::background());
        } catch (\LogicException $exception) {
            Assert::same($exception->getMessage(), 'The process is already started');

            return;
        } finally {
            $process->stop(0);
        }

        throw new \LogicException('an already started process was expected to be rejected');
    }

    #[DataProvider('invalidSettingsProvider')]
    public function invalidSettingsAreRejectedBeforeStarting(float $gracePeriod, float $pollInterval, string $message): void
    {
        $process = new Process(['true']);

        try {
            ContextProcess::run($process, Context::background(), gracePeriod: $gracePeriod, pollInterval: $pollInterval);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), $message);
            Assert::false($process->isStarted());

            return;
        }

        throw new \LogicException('invalid settings were expected to be rejected');
    }

    public static function invalidSettingsProvider(): iterable
    {
        $grace = 'Grace period must be a finite non-negative number of seconds';
        $poll = 'Poll interval must be a finite positive number of seconds';

        yield 'negative grace' => [-1.0, 0.05, $grace];
        yield 'infinite grace' => [INF, 0.05, $grace];
        yield 'NaN grace' => [NAN, 0.05, $grace];
        yield 'zero poll' => [1.0, 0.0, $poll];
        yield 'negative poll' => [1.0, -0.1, $poll];
        yield 'infinite poll' => [1.0, INF, $poll];
    }

    public function aZeroGracePeriodKillsAtOnce(): void
    {
        $process = new Process(['sh', '-c', "trap '' TERM; while :; do sleep 0.05; done"]);
        [$context] = Context::background()->withTimeout(0.2);
        $started = hrtime(as_number: true);

        try {
            ContextProcess::run($process, $context, gracePeriod: 0.0);
        } catch (ContextExpiredException) {
            Assert::false($process->isRunning());
            Assert::true(hrtime(as_number: true) - $started < 600_000_000);

            return;
        }

        throw new \LogicException('an expiry was expected');
    }
}
