# Technical Specification

## Runtime

- PHP `^8.0`; Composer 2+.
- Required extensions: `ctype`, `json`, `zip`.
- Windows and GNU/Linux.
- No third-party runtime PHP dependencies.
- PHPUnit tests; GitHub Actions CI; GPL-3.0-only.

## Components

```text
CLI -> BuildLoader / BuildValidator -> BuildExecutor
    -> DependencyResolver -> TaskExecutor
    -> TaskFactory / TaskDefinitionRegistry -> task
```

The registry defines task metadata; `schema/build-schema.json` is checked for parity.

## Tasks

| Task | Parameters |
|---|---|
| `create_directory` | `name` |
| `delete` | `path` |
| `copy` | `source`, `dest` |
| `exec` | `command`, optional `args`, `timeout` |
| `echo` | `message` |
| `archive` | `source`, `output` |

## Limits and safety

- Build file: 2 MiB.
- Process: 300-second default timeout, 86,400-second maximum; 1 MiB per output stream.
- ZIP: 10,000 files, 20,000 entries, depth 256, 512 MiB per file, 2 GiB total input.
- Filesystem tasks are confined to the working directory.
- Commands run without implicit shell interpretation.
- Process cleanup is best-effort; PHPMake is not a sandbox.

## CI

Linux PHP 8.0–8.5 compatibility, Windows PHP 8.0–8.5 filesystem/process tests, quality checks, consumer installation, and PHP 8.3 Xdebug coverage. See [CODE-COVERAGE.md](CODE-COVERAGE.md).
