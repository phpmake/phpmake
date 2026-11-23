# Technical Specification

**Product:** PHPMake 2.x  
**Runtime:** PHP 8.0+ (`^8.0`)  
**Platforms:** Windows and GNU/Linux

## Technology

| Area | Specification |
|---|---|
| Language | PHP 8.0+ |
| Package management | Composer 2+ |
| Configuration | JSON |
| Required extensions | ctype, json, zip |
| Archive format | ZIP via PHP ZipArchive |
| Tests | PHPUnit (`^9.6 || ^13.0`) |
| Interface | Command line |
| Distribution | Composer / Packagist |
| CI | GitHub Actions |
| License | GPL-3.0-only |

There are no third-party PHP package dependencies at runtime. Composer installs the package and generates its autoloader and executable proxy.

## Execution architecture

```text
CLI
 └─ BuildLoader / BuildValidator
     └─ BuildExecutor
         └─ DependencyResolver
             └─ TaskExecutor
                 └─ TaskFactory / TaskDefinitionRegistry
                     └─ Built-in task
```

The task registry defines the built-in task identifiers and parameter contracts. The published JSON Schema is checked against the runtime definitions by automated tests.

## Build configuration

The default file is `build.json`, a UTF-8 JSON object. It contains project metadata, named targets, and a default target. Targets declare ordered task arrays and optional dependencies. Unknown properties and unsupported task parameters are rejected.

See [USER-GUIDE.md](USER-GUIDE.md) and [schema/build-schema.json](../schema/build-schema.json) for the complete contract.

## Built-in tasks

| Task | Parameters | Function |
|---|---|---|
| `create_directory` | `name` | Create a directory |
| `delete` | `path` | Delete a file or directory tree |
| `copy` | `source`, `dest` | Copy files/directories transactionally |
| `exec` | `command`, optional `args`, `timeout` | Execute a process without a shell |
| `echo` | `message` | Output a message |
| `archive` | `source`, `output` | Create a ZIP archive |

## Operational limits

- Build configuration: 2 MiB.
- Process timeout: 300 seconds by default; maximum 86,400 seconds.
- Captured stdout: 1 MiB maximum.
- Captured stderr: 1 MiB maximum.
- ZIP creation: 10,000 files, 20,000 filesystem entries, depth 256, 512 MiB per file, and 2 GiB total uncompressed input.
- Log rotation: 10 MiB per active log; up to five rotated files.

## Security and platform behavior

Filesystem task paths must resolve inside the current working directory. Symlink/junction ancestors that escape that boundary are rejected. Destructive tasks cannot target the project root.

Processes are launched without shell interpretation. Windows uses Job Objects for process-tree cleanup. Linux uses a process group where supported, with documented fallback behavior. Cleanup is best-effort and does not sandbox child processes.

## Quality assurance

CI validates PHP 8.0–8.5 on Linux and runs Windows filesystem/process tests for PHP 8.0–8.5. The workflow includes PHPUnit, syntax/style/static checks, Composer validation and dependency checks, a clean consumer install, and a coverage report. Coverage reporting currently publishes statement coverage; branch coverage and an enforced 80% threshold are not yet configured.
