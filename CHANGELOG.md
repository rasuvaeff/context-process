# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-10-15

- Initial release: `ContextProcess::run()` runs a symfony/process `Process`
  under a `rasuvaeff/context` budget; on the deadline or a cancellation the
  process is stopped (SIGTERM, then SIGKILL after the grace period) and the
  context exception is rethrown.
