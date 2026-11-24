# Architecture

PHPMake separates build configuration, task metadata, filesystem operations, process execution, and CLI output while retaining PHP 8.0 compatibility.

## Build and task flow

`CliParser` loads and validates the build through `BuildLoader` and `BuildValidator`. `BuildExecutor` resolves target dependencies and delegates ordered task execution to `TaskExecutor` and `TaskFactory`.

`TaskDefinitionRegistry` is the canonical source for task identifiers, constructors, and parameter requirements. `BuildSchemaParityTest` checks that the published `schema/build-schema.json` remains aligned with the runtime registry.

## Filesystem operations

- `FilesystemIdentity` compares filesystem entries.
- `FilesystemTree` handles recursive copy, link detection, and cleanup without traversing links.
- `AtomicFilePublisher` and `AtomicDirectoryPublisher` stage and publish replacements with rollback/recovery handling.
- Tasks retain responsibility for their own path-containment and operation-specific rules.
- ZIP traversal and resource limits remain in the archive task.

Filesystem task paths must remain inside the working directory. Symlink/junction escapes are rejected, and destructive operations protect the project root.

## Process execution

- `CommandLineParser` validates and tokenizes command strings.
- `ProcessLauncher` selects the platform-specific launcher.
- `WindowsProcessLauncher` uses a kill-on-close Job Object.
- `PosixProcessLauncher` creates a POSIX session/process group where supported.
- `ProcessSupervisor` monitors and terminates process trees.
- `ExecTask` coordinates timeout, cancellation, bounded output capture, and logging.

Commands are launched without shell interpretation. Windows cleanup and POSIX process-group cleanup have documented platform limitations; neither is a security sandbox.

## Configuration and output

- `BuildConfiguration` and `TaskConfiguration` provide typed configuration boundaries.
- `ConsoleOutput` writes informational/success messages to stdout and errors to stderr.
- `Logger` provides persistent logging, locking, and rotation.
- `DependencyResolver` keeps dependency traversal state local to each invocation.

## Platform and quality constraints

- PHP syntax/runtime floor: 8.0.
- Supported operating systems: Windows and GNU/Linux.
- No third-party runtime process/filesystem/console dependencies.
- Keep task identifiers, configuration shape, CLI behavior, filesystem boundaries, process limits, and exit-code conventions compatible.

## Change validation

For changes to these boundaries, run PHPUnit, PHP lint, PHPStan, formatting, Rector dry-run, Composer checks, and the relevant Windows/Linux CI jobs. Process-tree cleanup and transactional filesystem behavior must be exercised on the affected platforms. Do not treat this document as evidence that a particular branch or commit has passed CI.
