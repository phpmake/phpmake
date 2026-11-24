# Product Requirements

PHPMake is a Composer-distributed CLI tool for repeatable build workflows defined in JSON.

## Users and outcomes

Developers and build engineers define named targets, dependencies, ordered tasks, and packaging steps; validate configuration; and run workflows locally or in CI.

## Scope

- JSON configuration and dependency resolution.
- Six built-in filesystem, process, archive, and output tasks.
- CLI initialization, validation, diagnostics, logging, and target selection.
- Composer installation on PHP 8.0+ and Windows/Linux.

## Constraints

Filesystem tasks are confined to the working directory. External commands run without implicit shell interpretation but retain the caller's permissions. Process and archive inputs have explicit limits.

PHPMake does not provide sandboxing, credential isolation, network policy, archive extraction, or a third-party task plugin API.
