# PHPMake

[![Packagist](https://img.shields.io/packagist/v/phpmake/phpmake.svg)](https://packagist.org/packages/phpmake/phpmake)
[![Build and Test](https://github.com/phpmake/phpmake/actions/workflows/default.yml/badge.svg?branch=main)](https://github.com/phpmake/phpmake/actions/workflows/default.yml)
[![CodeQL](https://github.com/phpmake/phpmake/actions/workflows/github-code-scanning/codeql/badge.svg?branch=main)](https://github.com/phpmake/phpmake/actions/workflows/github-code-scanning/codeql)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-617CBE)](https://php.net/)
[![License](https://img.shields.io/github/license/phpmake/phpmake)](LICENSE)

PHPMake is a lightweight PHP build automation tool. Define named targets, dependencies, and tasks in a JSON file, then run them from the command line.

## Requirements and support

- PHP **8.0 or newer** (Composer constraint: `^8.0`).
- Composer 2 or newer.
- PHP extensions: `ctype`, `json`, and `zip`.
- Supported operating systems: **Windows and GNU/Linux**.
- CI matrix: PHP 8.0–8.5 on Linux and Windows.

## Install

Project-local: `composer require --dev phpmake/phpmake`

Global: `composer global require phpmake/phpmake`

## Quick start

Create a sample `build.json` in the current working directory:

```sh
php vendor/bin/phpmake.php --init
php vendor/bin/phpmake.php --validate-build
php vendor/bin/phpmake.php
php vendor/bin/phpmake.php build
```

PHPMake reads `build.json` and resolves filesystem task paths relative to the process's current working directory.

## Built-in tasks

| Task | Purpose | Parameters |
|---|---|---|
| `create_directory` | Create a directory | `name` |
| `delete` | Delete a file or directory tree | `path` |
| `copy` | Copy a file or directory tree | `source`, `dest` |
| `exec` | Run a process without a shell | `command`, optional `args`, `timeout` |
| `echo` | Write a message | `message` |
| `archive` | Create a ZIP archive | `source`, `output` |

Filesystem tasks require paths to remain inside the working directory. Symlink/junction escapes are rejected, and destructive tasks cannot target the project root.

The `exec` task passes arguments directly; shell operators and expansions are not interpreted. Its default timeout is 300 seconds (maximum 86,400 seconds). Captured stdout and stderr are each limited to 1 MiB. Process-tree cleanup is platform-dependent and best-effort, not a security sandbox.

ZIP creation is bounded to 10,000 files, 20,000 filesystem entries, depth 256, 512 MiB per input file, and 2 GiB total uncompressed input. Build configuration files are limited to 2 MiB.

## CLI options

| Option | Alias | Purpose |
|---|---|---|
| `--help` | `-h` | Show usage |
| `--version` | `-v` | Show version |
| `--diagnostics` | — | Show runtime information |
| `--validate-build` | `-vb` | Validate `build.json` |
| `--debug` | `-d` | Enable debug diagnostics |
| `--silent` | `-s` | Suppress normal output; errors remain on STDERR |
| `--no-log` | `-nl` | Disable file logging |
| `--init` | — | Create a sample configuration without overwriting an existing file |

## Development

```sh
composer install
composer test
composer lint
composer analyse
composer format-check
composer refactor-check
```

Run performance benchmarks with `composer benchmark`.

## Documentation

- [User Guide](documents/USER-GUIDE.md) — configuration, tasks, CLI, troubleshooting.
- [FAQ](documents/FAQ.txt)
- [Security Policy](SECURITY.md)
- [Security Review](documents/SECURITY-REVIEW.md)
- [API Compatibility](documents/API-COMPATIBILITY.md)
- [Migration Guide](documents/MIGRATION-GUIDE.md)
- [Contributing](documents/CONTRIBUTING.txt)
- [Release Checklist](documents/RELEASE-CHECKLIST.txt)
- [Release Process](documents/RELEASE-PROCESS.md)
- [Changelog](documents/CHANGELOG.txt)

## Security

A build configuration is executable input. The `exec` task can launch arbitrary programs with the permissions and environment of PHPMake. Run only trusted configurations, use least-privilege isolated runners, and do not expose secrets to untrusted builds. See [SECURITY.md](SECURITY.md).

## Support and license

Report bugs and request features through [GitHub Issues](https://github.com/phpmake/phpmake/issues). Report vulnerabilities using the private process in [SECURITY.md](SECURITY.md).

PHPMake is distributed under [GPL-3.0-only](LICENSE).
