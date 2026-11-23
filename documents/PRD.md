# Product Requirements

**Product:** PHPMake  
**Release line:** 2.x

## Purpose

PHPMake is a command-line build automation tool for defining and executing project workflows from a JSON configuration file.

## Users

- Developers automating local build and packaging tasks.
- Build and DevOps engineers maintaining repeatable workflows.
- Teams using PHP-based projects and CI pipelines.

## Product capabilities

1. Define named build targets and dependencies in JSON.
2. Validate build configuration before execution.
3. Execute ordered tasks and stop when a task fails.
4. Provide filesystem, process, archive, and message-output tasks.
5. Expose CLI controls for validation, diagnostics, logging, and initialization.
6. Install and invoke the tool through Composer.

## Supported environment

- PHP 8.0 or newer (`^8.0`).
- Composer 2 or newer.
- PHP extensions: `ctype`, `json`, and `zip`.
- Microsoft Windows and GNU/Linux.

## Product constraints

- Filesystem tasks are restricted to the current working directory.
- External processes execute without implicit shell interpretation.
- Process execution has bounded timeouts and captured output.
- ZIP creation and build configuration input have explicit resource limits.
- PHPMake is not a sandbox; build definitions and `exec` commands must be trusted.

## Out of scope

PHPMake does not provide a third-party runtime task-plugin API, OS-level sandboxing, credential isolation, network egress filtering, DNS policy enforcement, or archive extraction.
