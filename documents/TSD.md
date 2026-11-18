# Technical Specification Document (TSD)

**Product:** PHPMake
**Version:** 2.0.x
**Document:** Technical Specification
**Status:** Draft

## 1. Technology Stack

| Component             | Technology           |
| --------------------- | -------------------- |
| Programming language  | PHP 8.3+             |
| Dependency management | Composer 2+          |
| Configuration         | JSON                 |
| Archive support       | PHP ZipArchive       |
| Testing               | PHPUnit              |
| Execution interface   | CLI                  |
| Distribution          | Composer / Packagist |
| CI/CD                 | GitHub Actions       |
| License               | GPL-3.0              |

## 2. Architecture

PHPMake follows a modular, object-oriented architecture.

**Main execution flow:**

CLI → BuildLoader → BuildValidator → BuildExecutor → DependencyResolver → TaskExecutor → TaskFactory → Task Implementation

### Core Components

| Component  | Responsibility                                            |
| ---------- | --------------------------------------------------------- |
| Config     | Central application configuration and version information |
| CLI        | Parse arguments and handle user commands                  |
| Build      | Load, validate, and execute build configurations          |
| Target     | Resolve target dependencies and execution order           |
| Task       | Define task contracts, creation, and execution            |
| Task Types | Implement individual build operations                     |
| Utilities  | Provide logging and supporting functionality              |

## 3. Configuration Specification

The build definition uses `build.json`.

Required top-level properties:

- `name`: Project name.
- `description`: Project description.
- `version`: Build configuration version.
- `targets`: Collection of build targets.
- `default_target`: Default execution target.

Each target supports:

- `depends`: Optional list of dependent targets.
- `tasks`: Ordered list of tasks.

Each task contains:

- `type`: Task identifier.
- `params`: Task-specific parameters.

## 4. Built-in Task Specifications

| Task               | Parameters         | Responsibility              |
| ------------------ | ------------------ | --------------------------- |
| `create_directory` | `name`             | Create directories          |
| `delete`           | `path`             | Delete files or directories |
| `copy`             | `source`, `dest`   | Copy files and directories  |
| `exec`             | `command`          | Execute external processes  |
| `echo`             | `message`          | Output messages             |
| `archive`          | `source`, `output` | Create ZIP archives         |

The `exec` task also supports optional arguments and timeout configuration.

## 6. Security and Reliability

- Restrict filesystem task paths to the project working directory.
- Reject paths escaping the permitted directory.
- Detect unsafe symbolic-link paths.
- Avoid shell interpretation when launching external processes.
- Enforce process execution timeouts.
- Handle task failures without silently reporting success.
- Protect log rotation with file locking.
- Validate external configuration before execution.

## 7. Quality Assurance

- PHPUnit unit, integration, system, and smoke tests.
- PHP syntax and code-style validation.
- JSON, YAML, and XML validation.
- Composer manifest validation.
- Dependency security auditing.
- CI-based build and test execution.
