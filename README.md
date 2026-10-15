# rasuvaeff/context-process

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-process/v)](https://packagist.org/packages/rasuvaeff/context-process)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-process/downloads)](https://packagist.org/packages/rasuvaeff/context-process)
[![Build](https://github.com/rasuvaeff/context-process/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-process/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-process/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-process/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-process/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-process/php)](https://packagist.org/packages/rasuvaeff/context-process)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Runs external processes (ImageMagick, wkhtmltopdf, git, ffmpeg, your own
scripts) under a [`rasuvaeff/context`](https://github.com/rasuvaeff/context)
budget. When the deadline passes or the context is cancelled, the process is
really stopped — SIGTERM, then SIGKILL after a grace period — instead of
running on after the caller gave up.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

## Requirements

- PHP 8.3+
- `rasuvaeff/context` ^0.3
- `symfony/process` ^7.0 or ^8.0

## Installation

```bash
composer require rasuvaeff/context-process
```

## Limitations

- **Only the process itself gets the signal.** Pass the command as an argv
  array (`new Process(['convert', $in, $out])`), not a shell string: with
  `Process::fromShellCommandline()` the shell receives SIGTERM and its children
  may keep running.
- **The grace period is not bounded by the budget**: a process that handles
  SIGTERM gets `$gracePeriod` seconds to clean up even after the deadline.
- **Termination is noticed within one poll interval** (`$pollInterval`,
  50 ms by default); a cancellation requested from a signal handler wakes the
  wait immediately.
- **Windows**: there are no signals — symfony/process terminates the process
  (`taskkill`) instead of SIGTERM/SIGKILL.

## Usage

```php
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Process\ContextProcess;
use Symfony\Component\Process\Process;

try {
    $exitCode = ContextProcess::run(
        new Process(['convert', $in, '-resize', '50%', $out]),
        $context,                                   // e.g. the request or job context
        callback: static fn(string $type, string $chunk) => $log->debug($chunk),
        gracePeriod: 2.0,                           // SIGTERM → wait → SIGKILL
    );
} catch (ContextExpiredException) {
    // the budget ran out: the process is already stopped
}
```

| Situation | What happens |
|---|---|
| The process finishes in time | Its exit code is returned |
| The deadline passes | The process is stopped (SIGTERM, SIGKILL after `gracePeriod`); `ContextExpiredException` |
| The context is cancelled (also via `requestCancel()` from a signal handler, e.g. [`rasuvaeff/context-cli`](https://github.com/rasuvaeff/context-cli)) | The process is stopped; `ContextCancelledException` with the reason as `previous` |
| The context is already terminated | The process is never started |
| The process's own `setTimeout()` / `setIdleTimeout()` fires | symfony/process stops it; its `ProcessTimedOutException` is rethrown unchanged |
| The callback throws | The process is stopped; the callback's exception propagates |
| The process was already started | `LogicException` |

The budget is not turned into the process's own timeout: symfony/process
kills on timeout with no grace period, while the context's deadline goes
through the same SIGTERM → grace → SIGKILL path as a cancellation.

### Public API

| Class | Description |
|---|---|
| `ContextProcess` | `run(Process $process, Context $context, ?callable $callback = null, float $gracePeriod = 2.0, float $pollInterval = 0.05): int` |

## Security

Arguments are passed as an argv array to symfony/process, never through a
shell. The package adds no input of its own to the command line.

## Examples

See [examples/](examples/) for runnable scripts.

| Script | Shows | Needs server? |
|---|---|---|
| `budget.php` | A 10 s command stopped by a 0.5 s budget; a command within the budget returning its exit code and output | no |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## License

[BSD-3-Clause](LICENSE.md)
