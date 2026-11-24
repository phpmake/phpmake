# Migration Guide

The 2.x package remains `phpmake/phpmake`, requires PHP `^8.0`, Composer 2+, and extensions `ctype`, `json`, and `zip`.

## Configuration

Validation rejects malformed JSON shapes, unknown properties, task types, and parameters. Build files are limited to 2 MiB.

Validate before upgrading:

```sh
php vendor/bin/phpmake.php --validate-build
```

## Filesystem tasks

Paths are resolved from the process working directory and must remain inside it after normalization and link/junction checks. Destructive tasks cannot target the project root. Review paths used by `copy`, `delete`, `create_directory`, and `archive`.

## Process execution

`exec` does not interpret shell syntax. Put arguments in `args`; explicitly invoke a shell only when needed. The default timeout is 300 seconds, maximum 86,400; stdout and stderr are limited to 1 MiB each. Process-tree cleanup is platform-dependent and best-effort.

## ZIP creation

ZIP inputs are limited to 10,000 files, 20,000 entries, depth 256, 512 MiB per file, and 2 GiB total uncompressed input. PHPMake creates archives; extraction safety belongs to the consumer.

## PHP API

The stable PHP extension point is `PhpMake\\Task\\TaskInterface`. No runtime third-party task registration API exists. See [API-COMPATIBILITY.md](API-COMPATIBILITY.md).

## Upgrade

1. Back up `build.json`.
2. Install the new package in a test environment.
3. Run `--validate-build`.
4. Run the default and packaging targets with non-production inputs.
5. Review output and archives before deployment.

Never run untrusted build files with privileged credentials. PHPMake is not a sandbox.
