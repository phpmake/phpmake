# Software Requirements Specification (SRS)

**Product:** PHPMake
**Version:** 2.0.x
**Document:** Software Requirements Specification
**Status:** Active

## 1. Purpose

This document specifies the functional and non-functional requirements of PHPMake, a PHP-based build automation system.

## 2. Functional Requirements

| ID    | Requirement                                                                         |
| ----- | ----------------------------------------------------------------------------------- |
| FR-01 | The system shall load build definitions from `build.json`.                          |
| FR-02 | The system shall validate configuration structure and required properties.          |
| FR-03 | The system shall support multiple named build targets.                              |
| FR-04 | The system shall execute a specified target or the default target.                  |
| FR-05 | The system shall resolve target dependencies before execution.                      |
| FR-06 | The system shall detect circular and invalid dependencies.                          |
| FR-07 | The system shall execute tasks in their declared order.                             |
| FR-08 | The system shall support all six built-in task types.                               |
| FR-09 | The system shall stop the build when a task fails.                                  |
| FR-10 | The system shall provide CLI help and version information.                          |
| FR-11 | The system shall generate a sample configuration using `--init`.                    |
| FR-12 | The system shall provide build validation through `--validate-build`.               |
| FR-13 | The system shall provide environment diagnostics.                                   |
| FR-14 | The system shall support debug, silent, and no-log options.                         |
| FR-15 | The system shall support custom task implementations through its task architecture. |

## 3. Non-Functional Requirements

### 3.1 Security

- Filesystem operations must remain within the permitted working directory.
- Unsafe paths and symbolic-link escapes must be rejected.
- External processes must be executed without unintended shell interpretation.
- Process execution must support configurable timeouts.
- Errors must not be silently converted into successful execution results.

### 3.2 Performance

- Dependency resolution should avoid unnecessary repeated work.
- Filesystem operations should handle normal project-sized workloads efficiently.
- Logging should avoid unnecessary overhead.

### 3.3 Reliability

- Invalid configurations must be rejected before build execution.
- Task failures must propagate to the build result.
- Partial filesystem and archive operations must be handled safely.
- Dependency cycles must be detected.

### 3.4 Compatibility

- Active development version must support PHP 8.3 or later.
- Composer 2 or later must be supported.
- The product targets Windows, Linux, and macOS environments.

### 3.5 Maintainability

- The architecture must remain modular and object-oriented.
- Tasks must follow a common task interface.
- Components must be independently testable.
- Code changes must include appropriate regression tests.

### 3.6 Usability

- Build definitions must use readable JSON.
- CLI commands and options must be documented.
- Errors must provide useful diagnostic information.
- Installation must be straightforward through Composer.

## 4. External Interfaces

**User Interface:** Command-line interface.

**Configuration Interface:** JSON build definition.

**Package Interface:** Composer package and executable.

**Execution Interface:** Operating-system process execution.

**Logging Interface:** Console output and project-level log files.
