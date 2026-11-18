# Technical Specification Document (TSD)

**Product:** PHPMake  
**Version:** 2.0.x  
**Document:** Technical Specification  
**Status:** Active

## 1. Technology Stack

| Component | Technology |
| --- | --- |
| Programming language | PHP 8.3+ |
| Dependency management and installation | Composer 2+ |
| Configuration | JSON |
| Required PHP extensions | ctype, json, random, zip |
| Archive support | PHP ZipArchive |
| Testing | PHPUnit 12 |
| Execution interface | CLI |
| Distribution | Composer / Packagist |
| CI/CD | GitHub Actions |
| License | GPL-3.0-only |

PHPMake has no third-party PHP package dependencies at runtime. Composer is used to install the package and generate its autoloader and executable proxy.

## 2. Architecture

PHPMake follows a modular, object-oriented architecture.

**Main execution flow:**

CLI → BuildLoader → BuildValidator → BuildExecutor → DependencyResolver → TaskExecutor → TaskFactory → Task Implementation

| Component | Responsibility |
| --- | --- |
| Config | Application configuration and version information |
| CLI | Parse arguments and dispatch CLI operations |
| Build | Load, validate, and execute build configurations |
| Target | Resolve dependency order and detect cycles |
| Task | Define task contracts, creation, and execution |
| Task Types | Implement individual build operations |
| Utilities | Logging and supporting functionality |

## 3. Configuration Specification

The build definition is a UTF-8 JSON object, normally named `build.json`.

Required top-level properties:

- `name`: Non-empty project name.
- `description`: Non-empty project description.
- `version`: Non-empty build/project version; it is independent of the PHPMake package version.
- `targets`: Object containing named targets.
- `default_target`: Name of an existing target.

Each target is an object with:

- `description`: Optional string.
- `depends`: Optional array of existing target names.
- `tasks`: Required ordered array of task objects.

Each task is an object containing:

- `type`: One of the six built-in task identifiers.
- `params`: Object containing only parameters supported by that task type.

Unknown properties are rejected. The runtime validator and `schema/build-schema.json` define the configuration contract. The loader also checks raw JSON object/array shapes before associative decoding can erase those distinctions.

## 4. Built-in Task Specifications

| Task | Required parameters | Optional parameters | Responsibility |
| --- | --- | --- | --- |
| `create_directory` | `name` | — | Create a directory |
| `delete` | `path` | — | Delete a file or directory tree |
| `copy` | `source`, `dest` | — | Copy files or directory trees transactionally |
| `exec` | `command` | `args`, `timeout` | Execute a process without a shell |
| `echo` | `message` | — | Output a message |
| `archive` | `source`, `output` | — | Create a ZIP archive |

### 4.1 External Process Execution

- `command` is a non-empty executable/command string tokenized into an argument vector.
- `args` is an optional array of scalar values appended to the argument vector.
- The command is launched without shell expansion, pipelines, or shell operators.
- `timeout` is an optional numeric duration in seconds. The default is 300 seconds; values must be greater than 0 and no greater than 86400.
- Captured stdout and stderr are each limited to 1 MiB. Exceeding either limit fails the task.
- On timeout or output overflow, Windows uses `taskkill /T` to terminate the process tree. POSIX systems use a dedicated process group when the POSIX extension is available. Without process-group support, termination falls back to the direct process.

## 5. Platform Compatibility

- Supported runtime: PHP 8.3+ with Composer 2+.
- Required extensions: `ctype`, `json`, and `zip`.
- Supported operating systems: Microsoft Windows, GNU/Linux, and macOS.
- Path comparisons follow host filesystem semantics: Windows is treated as case-insensitive; macOS volumes may be case-sensitive or case-insensitive.
- Windows junctions and POSIX symbolic links are treated as path redirection boundaries.
- Filesystem and process behavior is exercised in Linux, Windows, and macOS CI jobs.

## 6. Security and Reliability

- Filesystem task paths are normalized and must remain within the current working directory.
- Existing path prefixes are resolved to detect symbolic-link and junction escapes.
- Deletion and directory-copy destinations must not target the project root.
- Directory copies are staged and published only after successful copying; failed copies must preserve an existing destination.
- ZIP creation rejects links and removes partial archives after failure.
- External processes are launched without a shell, have bounded captured output, and enforce execution deadlines.
- Process-tree termination is attempted using the platform facilities described in Section 4.1.
- Task failures remain visible on stderr even when normal console output is silent.
- Log writes and rotation are serialized using a persistent `build.log.lock` file.
- Logs rotate at 10 MiB and retain up to five numbered backups.

## 7. Quality Assurance

- PHPUnit unit, integration, system, and smoke tests.
- Regression tests for project-root protection, symlink/junction traversal, UNC paths, and filesystem case semantics.
- Regression tests for process timeouts, descendant termination, and output limits.
- Regression tests for concurrent and partial log writes.
- Runtime/schema parity tests for all built-in task parameter definitions.
- PHP syntax, YAML, XML, and JSON validation.
- Composer manifest, dependency, requirement, normalization, and security checks.
- CI-based build and test execution on Linux, Windows, and macOS.
