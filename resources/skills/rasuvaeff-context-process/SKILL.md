---
name: rasuvaeff-context-process
description: >-
  Run external processes under a request or job budget with
  rasuvaeff/context-process — ContextProcess::run() stops a symfony/process
  Process (SIGTERM, then SIGKILL after a grace period) when the
  rasuvaeff/context deadline passes or the context is cancelled. Use when code
  shells out to ImageMagick, wkhtmltopdf, git, ffmpeg or any CLI tool inside an
  HTTP request, queue job or worker that has a time budget.
---

# rasuvaeff/context-process

Namespace `Rasuvaeff\Context\Process\`. One entry point:
`ContextProcess::run(Process $process, Context $context, ?callable $callback = null, float $gracePeriod = 2.0, float $pollInterval = 0.05): int`.

## Safety rules — verify these on every change

1. **argv array, never a shell string.** `new Process(['convert', $in, $out])`.
   With `Process::fromShellCommandline()` SIGTERM goes to the shell and its
   children may keep running — and a shell string is an injection surface.
2. **Pass the request/job context, not `Context::background()`** — otherwise
   there is no budget and nothing stops the process.
3. **Do not also set `Process::setTimeout()` to the budget.** symfony/process
   kills on its own timeout with no grace period; the context deadline already
   goes through SIGTERM → grace → SIGKILL. Use `setTimeout()` only for a limit
   independent of the budget.
4. **Catch the context exceptions where the work ends**:
   `ContextExpiredException` (budget ran out) / `ContextCancelledException`
   (cancelled — e.g. a worker got SIGTERM via `rasuvaeff/context-cli`); the
   process is already stopped. Clean up its partial output yourself.
5. **Choose `gracePeriod` for the tool**: it is not bounded by the budget —
   long enough for the tool to flush/clean up on SIGTERM, short enough for the
   worker's own shutdown grace (Kubernetes `terminationGracePeriodSeconds`).

## Canonical usage

```php
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Process\ContextProcess;
use Symfony\Component\Process\Process;

try {
    $code = ContextProcess::run(new Process(['wkhtmltopdf', $url, $pdf]), $jobContext, gracePeriod: 3.0);
} catch (ContextExpiredException) {
    @unlink($pdf);   // partial output
    throw new PdfTimedOut();
}
```

## Full API

Read `vendor/rasuvaeff/context-process/llms.txt`; the context itself:
`vendor/rasuvaeff/context/llms.txt`.
