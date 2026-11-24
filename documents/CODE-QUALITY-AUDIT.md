# Code Quality Audit

**Scope:** current source architecture, test quality, configuration contracts, filesystem safety, process execution, and CI checks.

## Current architecture

- `TaskDefinitionRegistry` is the source of truth for built-in task metadata. Runtime validation and the published JSON Schema are checked for parity.
- `BuildConfiguration` and `TaskConfiguration` define typed configuration boundaries.
- `FilesystemIdentity`, `FilesystemTree`, and the atomic publishers centralize shared filesystem operations.
- `CommandLineParser`, platform process launchers, and `ProcessSupervisor` separate command parsing and process lifecycle concerns.
- `ConsoleOutput` centralizes CLI output; `Logger` handles persistent logging and rotation.
- `DependencyResolver` keeps traversal state local to each resolution call.

## Quality controls

The Composer scripts provide:

- PHPUnit tests: `composer test`
- PHP syntax lint: `composer lint`
- PHPStan: `composer analyse`
- PHP-CS-Fixer check: `composer format-check`
- Rector dry run: `composer refactor-check`
- Coverage enforcement: `composer coverage-check` (after generating a Clover report with Xdebug)
- Performance measurements: `composer benchmark`

CI also validates YAML, XML, and JSON files; Composer metadata and dependency declarations; unused Composer dependencies (`composer-unused`); dependency advisories; and a clean Composer consumer installation.

## Cleanup findings

- Removed the unused `ConsoleOutput::stderr()` wrapper; error output is already centralized by `ConsoleOutput::error()`.
- Removed the one-call `ZipTask::publishArchive()` wrapper and call the shared atomic publisher directly.
- Consolidated task-level link/junction detection and removal in `FilesystemTree`, eliminating duplicate platform-specific logic from `BaseTask`.
- Simplified test bootstrap autoload path construction; `addslashes()` was not needed for a literal filesystem path.
- Reviewed private/protected source methods for unreferenced declarations. No additional unused private/protected methods were identified.
- Reviewed overlapping tests. The similar-looking publisher, filesystem-task, and helper tests exercise different layers or failure guarantees, so they were retained rather than deleting useful regression coverage.
- The current test inventory contains 272 test methods across 45 PHPUnit test files; the 50 PHP files under `tests/` also include support scripts, helpers, and the bootstrap.

## Confirmed test-quality baseline

The Phase 26 audit covered 50 PHP test files and 268 test methods. All audited methods had explicit Arrange / Act / Assert sections. No PHPUnit mock objects or mocking-framework usage were found. These are structural audit results, not proof of complete behavioral coverage.

## Coverage policy

- CI collects line and branch coverage with Xdebug on the PHP 8.3 coverage job.
- Overall executable-line coverage must remain at or above 80%; the coverage job fails below this threshold.
- The following critical contracts must retain 100% executable-line coverage: build execution dispatch, build configuration validation, CLI parsing, shell-free command parsing, Windows process argument transport, dependency resolution, and the task-definition registry. The enforced file list lives in `tools/check-coverage.php`.
- Branch coverage is reported separately. No global branch percentage threshold is imposed yet; the report is used to prioritize untested decisions.
- High-risk filesystem, archive, and process lifecycle paths are 100% coverage targets, but they are not yet included in the hard 100% file gate because their current coverage is materially below target. See `documents/CODE-COVERAGE.md`.

## Remaining work

1. Raise coverage of atomic publishers, filesystem traversal, process supervision, and archive creation toward 100% line and branch coverage.
2. Review skipped tests and platform-specific execution evidence.
3. Complete a focused security review of filesystem operations, process execution, archive creation, and JSON configuration validation.
4. Keep documentation and examples aligned with the runtime, schema, Composer metadata, and CI matrix.

## Constraints

- Minimum PHP version remains 8.0 (`^8.0`).
- Supported operating systems are Windows and GNU/Linux.
- PHPUnit remains the test framework; do not add mocks.
- Avoid production refactors unless a concrete correctness, security, or maintainability issue justifies them.
- A CI check is complete only when its workflow result is successful; static inspection is not a substitute.
