# User Guide

PHPMake runs named build targets from a JSON file in the current working directory.

**Requirements:** PHP 8.0+, Composer 2+, extensions `ctype`, `json`, and `zip`. Supported systems: Windows and GNU/Linux.

## Install and start

```sh
composer require --dev phpmake/phpmake
php vendor/bin/phpmake.php --init
php vendor/bin/phpmake.php --validate-build
php vendor/bin/phpmake.php
```

`--init` creates `build.json` without overwriting an existing path. Run PHPMake from the directory containing that file. Filesystem task paths use the same working directory.

## Build file

A build file is a JSON object with required `name`, `description`, `version`, `targets`, and `default_target` properties. Each target has a required ordered `tasks` array and optional `description` and `depends` array. Dependencies run before the target; a failed task stops execution.

```json
{
  "name": "Example",
  "description": "Build and package",
  "version": "1.0.0",
  "targets": {
    "build": {
      "tasks": [
        { "type": "create_directory", "params": { "name": "build" } },
        { "type": "copy", "params": { "source": "src", "dest": "build/src" } },
        { "type": "exec", "params": { "command": "php", "args": ["-l", "build/index.php"] } }
      ]
    },
    "package": {
      "depends": ["build"],
      "tasks": [
        { "type": "archive", "params": { "source": "build", "output": "dist/app.zip" } }
      ]
    }
  },
  "default_target": "package"
}
```

Unknown properties, task types, and task parameters are rejected. The published contract is [build-schema.json](../schema/build-schema.json).

## Built-in tasks

| Task | Required parameters | Behavior |
|---|---|---|
| `create_directory` | `name` | Create the directory and missing parents. Existing directory is success. |
| `delete` | `path` | Delete a file/tree. Missing path is success; links are removed without traversing targets. |
| `copy` | `source`, `dest` | Copy a file/tree using staged publication. Existing destination is preserved if publication fails. |
| `exec` | `command` | Run a process directly. Optional `args` array and `timeout` in seconds. |
| `echo` | `message` | Write an informational message; empty message is allowed. |
| `archive` | `source`, `output` | Create and publish a ZIP archive after successful completion. |

### Filesystem boundaries

Paths must resolve inside the working directory. Relative and absolute paths are accepted only when the resolved location remains inside it. Symlink/junction escapes are rejected. Destructive tasks cannot target the project root. Delete is not transactional: earlier removals may remain if a later deletion fails.

### Process limits

`exec` does not invoke a shell or expand pipes, redirects, wildcards, or environment variables. Put arguments in `args`; explicitly invoke a shell if required.

- Default timeout: 300 seconds; maximum: 86,400 seconds.
- Captured stdout and stderr: 1 MiB each.
- Non-zero exit, timeout, cancellation, or output overflow fails the task.

Windows uses Job Objects for process-tree cleanup. Linux uses POSIX process groups where available, with utility fallbacks. Without process-group support, only the direct process can be terminated. Detached descendants may escape cleanup. This is not a sandbox.

### ZIP limits

- 10,000 files and 20,000 filesystem entries.
- Directory depth: 256.
- Per-file input: 512 MiB.
- Total uncompressed input: 2 GiB.

Links are rejected. Extraction safety remains the responsibility of the application extracting the archive.

## CLI options

| Option | Alias | Purpose |
|---|---|---|
| `--help` | `-h` | Show usage |
| `--version` | `-v` | Show version |
| `--init` | — | Create sample `build.json` |
| `--validate-build` | `-vb` | Validate configuration only |
| `--diagnostics` | — | Show PHP/runtime information |
| `--debug` | `-d` | Enable diagnostics |
| `--silent` | `-s` | Suppress normal output; errors remain on STDERR |
| `--no-log` | `-nl` | Disable file logging |

Run a target by name:

```sh
php vendor/bin/phpmake.php test --debug
```

Only one target argument is accepted. Unknown options and conflicting action options are rejected.

## Logging

PHPMake writes `build.log` in the working directory by default. The active log rotates at 10 MiB and retains up to five backups. `--no-log` disables persistent logging. Debug output may include exception messages and stack traces; child output may contain secrets. With `--silent --debug`, diagnostics go to the log, while errors remain on STDERR.

## Troubleshooting

| Problem | Check |
|---|---|
| Build file not found | Run from the directory containing `build.json`. |
| Invalid configuration | Run `--validate-build`; check required fields, task types, and parameters. |
| Missing/circular dependency | Check every `depends` target. |
| Path safety error | Resolve the path from the working directory and inspect links/junctions. |
| Copy/archive failure | Check source, permissions, destination, links, and resource limits. |
| Command not found | Verify executable path and argument tokenization. |
| Timeout/output overflow | Adjust the timeout or reduce child-process output. |
| Task failure | Read STDERR and `build.log`; use `--debug`. |

## Adding a built-in task

There is no third-party runtime task plugin API. To add a task to PHPMake:

1. Add a class under `src/Task/Types` extending `BaseTask`.
2. Add its identifier and parameter contract to `TaskDefinitionRegistry`.
3. Add the matching definition to `schema/build-schema.json`.
4. Add PHPUnit tests for success, invalid input, failures, and boundaries.
5. Update this guide, README, FAQ, compatibility notes, and changelog.

[Architecture](ARCHITECTURE.md) describes internal components. [API compatibility](API-COMPATIBILITY.md) defines the supported public interface.

## Security

A build file is executable input. `exec` can run arbitrary programs with PHPMake's permissions and environment. Do not run untrusted build files with privileged accounts or deployment secrets. Use isolated CI runners and infrastructure-level network controls. See [SECURITY.md](../SECURITY.md).
