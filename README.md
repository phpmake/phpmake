# PHPMake

[![Packagist](https://img.shields.io/packagist/v/phpmake/phpmake.svg)](https://packagist.org/packages/phpmake/phpmake)
[![Build and Test](https://github.com/phpmake/phpmake/actions/workflows/default.yml/badge.svg?branch=main)](https://github.com/phpmake/phpmake/actions/workflows/default.yml)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-617CBE)](https://php.net/)
[![License](https://img.shields.io/github/license/phpmake/phpmake)](LICENSE)

PHPMake runs repeatable project workflows from a JSON build file.

## Requirements

- PHP 8.0+ and Composer 2+
- PHP extensions: `ctype`, `json`, `zip`
- Windows or GNU/Linux

## Install and run

```sh
composer require --dev phpmake/phpmake
php vendor/bin/phpmake.php --init
php vendor/bin/phpmake.php --validate-build
php vendor/bin/phpmake.php
php vendor/bin/phpmake.php build
```

PHPMake reads `build.json` from the current working directory. Filesystem task paths are resolved relative to that directory.

## Tasks

| Task | Parameters | Purpose |
|---|---|---|
| `create_directory` | `name` | Create a directory |
| `delete` | `path` | Delete a file or directory tree |
| `copy` | `source`, `dest` | Copy files or directories |
| `exec` | `command`, `args?`, `timeout?` | Run a process without a shell |
| `echo` | `message` | Write a message |
| `archive` | `source`, `output` | Create a ZIP archive |

Filesystem tasks cannot escape the working directory; destructive tasks cannot target its root. ZIP creation is bounded to 10,000 files, 20,000 entries, depth 256, 512 MiB per file, and 2 GiB total input.

The `exec` task runs trusted programs with the caller's permissions. Its default timeout is 300 seconds (maximum 86,400); captured stdout and stderr are limited to 1 MiB each. Process cleanup is best-effort, not a sandbox.

## CLI

| Option | Purpose |
|---|---|
| `--help`, `-h` | Show usage |
| `--version`, `-v` | Show version |
| `--init` | Create a sample build file without overwriting |
| `--validate-build`, `-vb` | Validate the build file |
| `--diagnostics` | Show runtime information |
| `--debug`, `-d` | Enable diagnostics |
| `--silent`, `-s` | Suppress normal output; errors remain on STDERR |
| `--no-log`, `-nl` | Disable file logging |

## Development

```sh
composer install
composer test
composer lint
composer analyse
composer format-check
composer refactor-check
```

Tests use PHPUnit with Arrange / Act / Assert, real behavior, and no mocks. Classes and methods require short PHPDoc summaries. CI checks PHP 8.0–8.5 on Linux and Windows and enforces the coverage policy.

See [Contributing](documents/CONTRIBUTING.txt), the [Test Suite Audit](documents/TEST-SUITE-AUDIT.md), the [Code Quality Audit](documents/CODE-QUALITY-AUDIT.md), and the [User Guide](documents/USER-GUIDE.md).

## Documentation

- [User Guide](documents/USER-GUIDE.md)
- [FAQ](documents/FAQ.txt)
- [Architecture](documents/ARCHITECTURE.md)
- [API compatibility](documents/API-COMPATIBILITY.md)
- [Migration guide](documents/MIGRATION-GUIDE.md)
- [Security policy](SECURITY.md) and [security review](documents/SECURITY-REVIEW.md)
- [Coverage policy](documents/CODE-COVERAGE.md)
- [Test suite and quality policies](documents/TEST-SUITE-AUDIT.md) and [quality audit](documents/CODE-QUALITY-AUDIT.md)
- [Release process](documents/RELEASE-PROCESS.md) and [checklist](documents/RELEASE-CHECKLIST.txt)
- [Changelog](documents/CHANGELOG.txt)

A build file is executable input. Run only trusted configurations; see [SECURITY.md](SECURITY.md).

Report bugs through [GitHub Issues](https://github.com/phpmake/phpmake/issues). Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
