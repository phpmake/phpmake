# Software Requirements

**Product:** PHPMake 2.x  
**Platforms:** Windows and GNU/Linux  
**Runtime:** PHP 8.0+ (`^8.0`)

## Functional requirements

| ID | Requirement |
|---|---|
| FR-01 | Load a JSON build definition from the current working directory. |
| FR-02 | Validate configuration structure, properties, task types, and parameters before execution. |
| FR-03 | Execute a named target or the configured default target. |
| FR-04 | Resolve target dependencies and reject missing or circular dependencies. |
| FR-05 | Execute tasks in declared order and report failure when a task fails. |
| FR-06 | Provide the six built-in tasks: create_directory, delete, copy, exec, echo, archive. |
| FR-07 | Provide CLI help, version, diagnostics, validation, debug, silent, no-log, and init options. |
| FR-08 | Write logs with serialized updates and bounded rotation. |
| FR-09 | Bound external process duration and captured stdout/stderr. |
| FR-10 | Create ZIP archives with explicit input-count, depth, and size limits. |

## Non-functional requirements

### Security

- Built-in filesystem operations must remain within the working directory.
- Symlink and Windows junction escapes must be rejected.
- Destructive operations must protect the project root.
- External processes must be launched without implicit shell interpretation.
- Process cleanup must be attempted using supported platform mechanisms.
- PHPMake must clearly document that external commands are not sandboxed.

### Reliability

- Invalid configuration must fail before task execution.
- Task failures must propagate to the build result.
- Failed copy/archive publication must preserve an existing destination where transactional publication is supported.
- Timeouts and output-limit violations must fail the process task.
- Temporary process and staging files must be cleaned up on success and failure.

### Compatibility

- PHP constraint: `^8.0`.
- Composer 2 or newer.
- Required extensions: `ctype`, `json`, and `zip`.
- Supported operating systems: Windows and GNU/Linux.
- CI currently tests PHP 8.0–8.5.

### Maintainability

- Keep task metadata centralized and aligned with the published JSON Schema.
- Keep tests isolated, deterministic, bounded, and free of mocks.
- Update user documentation when CLI, task, configuration, or compatibility behavior changes.

## Interfaces

- CLI: command-line arguments, stdout, stderr, and process exit status.
- Configuration: JSON build file and published JSON Schema.
- Distribution: Composer package and executable proxy.
- Logging: console output and project-local log files.
