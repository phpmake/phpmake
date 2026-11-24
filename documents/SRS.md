# Software Requirements

## Functional

1. Load `build.json` from the current working directory.
2. Validate configuration structure, properties, task types, and parameters.
3. Run a named target or the configured default target.
4. Resolve dependencies and reject missing or circular targets.
5. Execute tasks in order and stop on failure.
6. Support `create_directory`, `delete`, `copy`, `exec`, `echo`, and `archive`.
7. Provide help, version, diagnostics, validation, debug, silent, no-log, and init options.
8. Write serialized logs with bounded rotation.

## Non-functional

- PHP `^8.0`; Composer 2+; extensions `ctype`, `json`, and `zip`.
- Windows and GNU/Linux; CI tests PHP 8.0–8.5.
- Filesystem tasks remain inside the working directory and reject symlink/junction escapes.
- Processes run without implicit shell interpretation, with timeouts and bounded output.
- ZIP creation and build configuration input have explicit resource limits.
- Task failures propagate; temporary process and staging data are cleaned up.
- Tests use PHPUnit, AAA, deterministic behavior, and no mocks.

## Interfaces

CLI arguments/stdout/stderr/exit status; JSON build file and schema; Composer package and executable; project-local logs.
