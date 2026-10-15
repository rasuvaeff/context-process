# AGENTS.md — context-process

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/context-process` runs a symfony/process `Process` under a
`rasuvaeff/context` budget (namespace `Rasuvaeff\Context\Process`). The only
public API is `ContextProcess::run()`: on the deadline or a cancellation the
process is stopped (SIGTERM, SIGKILL after the grace period) and the
context's exception is rethrown.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never leave a process behind.** Every path out of `run()` — the
   context's exception, the process's own timeout, a throwing callback — must
   end with the process stopped (`finally`). Tests assert `isRunning() ===
   false` after each scenario; keep it that way.
4. **Preserve the public contract.** Update README + README.ru + llms.txt +
   tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make test`, `make test-coverage`,
`make mutation`, `make release-check`.

## Invariants & gotchas

- **The budget is NOT the process timeout.** symfony/process's
  `checkTimeout()` calls `stop(0)` — an instant kill with no grace. The
  deadline is watched through `Context::sleep()` between polls and goes
  through `stop($gracePeriod)` like a cancellation. Do not "simplify" by
  `setTimeout(remaining)`.
- `isRunning()` reads the pipes and feeds the callback; the final output is
  delivered when the process exits (`theOutputReachesTheCallbackIncludingTheTail`).
- Tests use real processes (`sleep`, `sh -c "trap '' TERM; …"`) and real time
  with wide margins. `getTermSignal()` is unreliable after SIGKILL (the handle
  may be closed before the status is read) — assert timing, not the signal.
  The `SIG*` constants need ext-pcntl, which this package does not require:
  tests use the POSIX numbers.
- No CPU-bound code → `benchmarks/.gitkeep` (testo.php declares the suite; the
  directory must be tracked).
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit
  SHA with a `# vN` comment; `permissions: { contents: read }`,
  `persist-credentials: false`; never put `${{ }}` straight into `run:` (pass
  it through `env:`). Verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
