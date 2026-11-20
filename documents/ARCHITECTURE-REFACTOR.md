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
- `ProcessLauncher` selects the platform adapter.
- `WindowsProcessLauncher` prepares the PowerShell Job Object launcher.
- `PosixProcessLauncher` prepares the POSIX session/process-group launcher.
- `ProcessSupervisor` discovers and terminates process trees, including tracked descendants.
- `ExecTask` coordinates process lifecycle, bounded output capture, timeout handling, cancellation, and task-level logging.

All process launch paths continue to use argv arrays and `bypass_shell`; no shell command interpolation was introduced.

## 4. Typed configuration and static analysis

- `TaskConfiguration` is the typed boundary between decoded task arrays and task construction.
- `BuildConfiguration` documents the validated target/task array shape.
- `BuildLoader` annotates the validated decoded build shape before constructing `BuildConfiguration`.
- PHPStan is raised from level 1 to level 2. Further increases should be incremental and based on resolved findings rather than suppressing errors globally.

## 5. CLI output and error reporting

- `ConsoleOutput` owns standard-output and standard-error writes.
- `Logger` delegates console output while retaining log persistence, rotation, debug filtering, and silent-mode behavior.
- `TaskExecutor` delegates target/task status and error messages to the same output boundary.
- `CliParser` uses one declarative flag table and routes help/version/diagnostic output through the output service.
- The CLI entrypoint shares one output instance with the logger and task executor. The pre-autoload failure remains a direct stderr write because the Composer autoloader and output class are not available yet.

## Compatibility and behavior constraints

- PHP 8.0+ syntax only.
- Windows and Linux remain supported; macOS is not added to the supported matrix.
- No third-party process, filesystem, or console dependencies were added.
- Existing task identifiers, JSON configuration shape, process timeout/output limits, filesystem safety checks, and task exit-code conventions are intended to remain stable.

## Validation still required

Run PHPUnit, PHPStan level 2, PHP-CS-Fixer, PHP lint, and the full PHP 8.0–8.5 Windows/Linux workflow matrix. In particular, validate process-tree termination and transactional copy/archive replacement on both operating systems before merging.
