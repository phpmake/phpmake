# Architecture Refactor

This change separates configuration contracts, task registration, filesystem publication, process execution, and CLI output while retaining the PHP 8.0 runtime floor.

## 1. Task definitions and schema parity

- `TaskDefinitionRegistry` is the canonical runtime mapping for task identifiers, constructors, required parameters, and allowed parameters.
- `TaskFactory` creates task implementations from that registry.
- `BuildValidator` uses the same required/allowed parameter metadata instead of maintaining a second task-type match table.
- `BuildSchemaParityTest` verifies that registry task identifiers and parameter contracts match `schema/build-schema.json`, and continues to exercise runtime validation against schema-valid and invalid configurations.

The JSON Schema remains a published static artifact. The parity test intentionally fails if it drifts from the runtime registry.

## 2. Shared filesystem primitives

- `FilesystemIdentity` centralizes device/inode comparisons used by copy and archive operations, with the archive task retaining its Windows case-insensitive fallback.
- `FilesystemTree` centralizes recursive directory copying, link/junction detection, and cleanup without traversing links. Project-root containment and source/destination relationship checks remain with the task that owns those policies.
- `AtomicFilePublisher` centralizes same-directory staged-file publication, backup preservation, rollback, and recovery-path reporting.
- `AtomicDirectoryPublisher` centralizes same-parent directory publication, backup preservation, rollback, and recovery-path reporting.
- `CopyTask` coordinates staging and merges source content into the staged copy of an existing destination. Archive traversal and ZIP limits remain in `ZipTask` because those algorithms have different invariants.

## 3. Process execution boundaries

- `CommandLineParser` owns quote validation and shell-free command tokenization.
- `ProcessLauncher` selects the platform-specific adapter.
- `WindowsProcessLauncher` prepares the PowerShell Job Object launcher.
- `WindowsConsoleSignalHandler` translates Windows CLI Ctrl+C and Ctrl+Break events into cooperative cancellation requests; the handler is unregistered after process cleanup.
- `PosixProcessLauncher` prepares the POSIX session/process-group launcher.
- `ProcessSupervisor` discovers and terminates process trees, including tracked descendants.
- `ExecTask` coordinates process lifecycle, bounded output capture, timeout handling, cancellation, and task-level logging.

All process launch paths continue to use argv arrays and `bypass_shell`; no shell command interpolation was introduced. On Windows, the Job Object's kill-on-close behavior terminates descendants when the launcher exits. On POSIX, process-group termination is supplemented by periodic descendant sampling.

## 4. Typed configuration and static analysis

- `TaskConfiguration` is the typed boundary between decoded task arrays and task construction.
- `BuildConfiguration` documents the validated target/task array shape.
- `BuildLoader` annotates the validated decoded build shape before constructing `BuildConfiguration`.
- PHPStan is configured at level 3 with the PHP 8.0 compatibility target. Keep the level and target synchronized with `phpstan.neon`; resolve findings rather than suppressing errors globally.

## 5. CLI output and error reporting

- `ConsoleOutput` owns standard-output and standard-error writes.
- `Logger` delegates console output while retaining log persistence, rotation, debug filtering, and silent-mode behavior.
- `TaskExecutor` delegates target/task status and error messages to the same output boundary.
- `CliParser` uses one declarative flag table and routes help/version/diagnostic output through the output service.
- The CLI entrypoint shares one output instance with the logger and task executor. The pre-autoload failure remains a direct stderr write because the Composer autoloader and output class are not available yet.

## 6. Operational reliability

- The benchmark records filesystem write throughput, ZIP throughput, process-start median latency, concurrent worker wall time, peak memory, PHP version, and OS family.
- CI runs the benchmark on Windows and Linux for PHP 8.0–8.5 and retains each JSON result as a 90-day artifact.
- Benchmark workers are terminated and reaped if a worker fails, preventing a failed benchmark from leaving background processes behind.
- Benchmark values are observational baselines, not hard pass/fail performance thresholds; shared-runner variance must be accounted for before introducing regression gates.

## Compatibility and behavior constraints

- PHP 8.0+ syntax only.
- Supported operating systems are Microsoft Windows and GNU/Linux only.
- No third-party process, filesystem, or console dependencies were added.
- Existing task identifiers, JSON configuration shape, process timeout/output limits, filesystem safety checks, and task exit-code conventions are intended to remain stable.

## Validation still required

Run PHPUnit, PHPStan level 3, PHP-CS-Fixer, PHP lint, Rector dry-run, the performance benchmark matrix, and the full PHP 8.0–8.5 Windows/Linux workflow matrix. In particular, validate process-tree termination and transactional copy/archive replacement on both operating systems before merging.
