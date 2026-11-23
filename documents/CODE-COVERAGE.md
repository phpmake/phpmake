# Code Coverage Policy

## Measurement

The dedicated GitHub Actions coverage job runs PHPUnit on PHP 8.3 with Xdebug enabled. It generates:

- Clover XML at `build/coverage.xml`
- HTML line-coverage report at `build/coverage-html/`
- JUnit test report at `build/junit.xml`
- GitHub Actions summary with line and branch metrics

The coverage job enables PHPUnit's `--branch-coverage` option. Branch coverage requires Xdebug; PCOV alone is not sufficient. The PHP 8.0–8.5 compatibility jobs continue to run the test suite without changing the supported PHP matrix.

## Enforced thresholds

| Metric | Requirement | Enforcement |
|---|---:|---|
| Overall executable-line coverage | At least 80% | CI hard failure |
| Critical contract files | 100% executable-line coverage per file | CI hard failure |
| Overall branch coverage | Reported; no percentage gate yet | Visible in CI summary and Clover report |

The overall line percentage is calculated from Clover's `coveredstatements / statements` metrics. Clover statements represent executable source lines. The gate validates that the report is present and structurally valid; missing statement or branch metrics fail the job rather than silently passing.

Run the gate locally after generating a Clover report:

```sh
composer coverage-check
```

## Critical contracts with a 100% line-coverage gate

The following files are enforced by `tools/check-coverage.php`:

| File | Critical contract |
|---|---|
| `src/Build/BuildExecutor.php` | Build target execution and dispatch |
| `src/Build/BuildValidator.php` | Build configuration validation |
| `src/Cli/CliParser.php` | CLI input and option parsing |
| `src/Process/CommandLineParser.php` | Shell-free command argument parsing |
| `src/Process/WindowsProcessLauncher.php` | Windows process argument transport |
| `src/Target/DependencyResolver.php` | Dependency graph resolution and cycle detection |
| `src/Task/TaskDefinitionRegistry.php` | Built-in task type and parameter contracts |

Any new executable line in these files must have a test that executes it. This is a file-level executable-line requirement, not a claim that all possible input combinations or execution paths have been proven.

## Additional security-critical paths with a 100% target

These modules handle destructive filesystem operations, untrusted paths, process lifecycle, and archive resource limits. They require 100% line and branch coverage as the test suite is expanded. They are deliberately not yet hard-gated at 100% because the current measured baseline shows significant uncovered code; turning on that gate before closing those gaps would leave CI permanently red.

| File / area | Why 100% is required | Baseline line coverage |
|---|---|---:|
| `src/Filesystem/AtomicFilePublisher.php` | Atomic replacement, rollback, and recovery preservation | 53.1% |
| `src/Filesystem/AtomicDirectoryPublisher.php` | Directory publication, rollback, and recovery preservation | 61.5% |
| `src/Filesystem/FilesystemTree.php` | Recursive copy/removal, link handling, and path safety | 72.4% |
| `src/Process/ProcessSupervisor.php` | Process-tree termination and signal fallbacks | 61.8% |
| `src/Process/PosixProcessLauncher.php` | POSIX process-group setup and fallback behavior | 57.5% |
| `src/Process/ProcessLauncher.php` | Platform launcher selection and fallback behavior | 28.6% |
| `src/Task/BaseTask.php` | Shared task validation and execution lifecycle | 74.0% |
| `src/Task/Types/CopyTask.php` | Copy safety, staging, and failure recovery | 73.6% |
| `src/Task/Types/ExecTask.php` | Command execution, cancellation, timeouts, and bounded output | 83.6% |
| `src/Task/Types/ZipTask.php` | Archive input validation, resource limits, snapshots, and publication | 82.5% |

The percentages above are from the pre-Phase-28 Clover report at commit `ec78e61509dae3fcec59243f4a5e6243db215ac0`. They are a baseline for prioritization, not current coverage after the Phase 28 test additions or this coverage-policy change. The fresh report produced by this PR's CI run supersedes them.

## Branch coverage priorities

Branch coverage is reported project-wide and in Clover. Tests should exercise both outcomes of security and correctness decisions, especially:

1. Filesystem identity, symlink/junction rejection, path containment, and special-file rejection.
2. Atomic file/directory publication success, replacement, rollback, backup retention, and double-failure recovery.
3. Process startup, process-group creation, timeout, cancellation, graceful termination, forced termination, and utility fallbacks.
4. Archive file-count, entry-count, depth, per-file size, aggregate size, snapshot consistency, and cleanup limits.
5. Build configuration validation for missing, malformed, unknown, invalid-type, and boundary values.

Branch coverage is not currently assigned an arbitrary project-wide pass percentage. First collect a stable branch baseline and use uncovered branch data to add behavior-focused tests. Do not treat a high line-coverage percentage as proof that both sides of a conditional were exercised.

## Baseline measurement before this phase

The available pre-Phase-28 Clover artifact was generated from commit `ec78e61509dae3fcec59243f4a5e6243db215ac0`:

| Metric | Result |
|---|---:|
| Executable statements | 1,594 |
| Covered statements | 1,284 |
| Line coverage | 80.55% |
| Branch coverage | Not collected |

The 80% line threshold is therefore based on an observed baseline that was already slightly above the required minimum. The Phase 28 tests and this phase's escaped-argument test are included in the next CI measurement. No post-change percentage is claimed until that coverage job completes and its Clover artifact is inspected.

## Constraints

- PHP requirement remains `^8.0`.
- PHPUnit remains the test framework.
- No mocks are introduced.
- Branch measurement runs on PHP 8.3 with Xdebug; the broader PHP 8.0–8.5 test matrix remains intact.
- A skipped platform-specific test is not counted as evidence that the skipped platform behavior was covered.
