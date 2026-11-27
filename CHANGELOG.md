# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.3 — 2026-11-27

- Requires `symfony/process` `^7.1.7 || ^8.0.5`: versions with security advisories are no longer allowed.

## 0.1.2 — 2026-11-22

- **Changed:** PHP 8.6 is supported: the `php` constraint is widened to
  `8.3 - 8.6` (#5), and `rasuvaeff/context` may now be `^0.3 || ^0.5` —
  the 0.3 line stays for prefer-lowest, the 0.5 line carries 8.6. CI runs
  PHP 8.6 as a regular matrix entry; the backward-compatibility job runs
  on PHP 8.5.

## 0.1.1 — 2026-11-20

- Changed: the wait between polls starts at 1 ms and doubles up to
  `pollInterval`, which stays the upper bound — a short command no longer
  costs a full interval (#3).
- Mutation testing now counts code the tests execute but do not name in `#[Covers]` (`infection --with-uncovered`); the `PublicVisibility` mutator is off and default values in parameter lists are ignored. No `src/` changes.

## 0.1.0 — 2026-10-15

- Initial release: `ContextProcess::run()` runs a symfony/process `Process`
  under a `rasuvaeff/context` budget; on the deadline or a cancellation the
  process is stopped (SIGTERM, then SIGKILL after the grace period) and the
  context exception is rethrown.
