# PHPMake User Guide

This guide documents the build-file format, built-in tasks, command-line interface, common failures, and built-in task development for PHPMake 2.x.

- Runtime: PHP 8.0 or newer
- Package manager: Composer 2 or newer
- Required PHP extensions: `ctype`, `json`, and `zip`
- Supported operating systems: Windows and GNU/Linux

For security boundaries and deployment guidance, read [SECURITY.md](../SECURITY.md). For concise answers, see [FAQ.txt](FAQ.txt). The supported integration surface and deprecation policy are defined in [API-COMPATIBILITY.md](API-COMPATIBILITY.md).

## 1. Installation and first run

Install PHPMake in the project whose build you want to automate:

```sh
composer require --dev phpmake/phpmake
```

Create a starter configuration in the current working directory:

```sh
php vendor/bin/phpmake.php --init
```

The command creates `build.json` and refuses to overwrite an existing file. Review the generated file and replace its sample steps with commands appropriate to your project.

Validate the configuration before executing tasks:

```sh
php vendor/bin/phpmake.php --validate-build
```

Run the configured default target, or name a target explicitly:

```sh
php vendor/bin/phpmake.php
php vendor/bin/phpmake.php build
```

The examples use the project-local Composer binary. A global Composer installation can also be used; invoke the installed `phpmake` executable from your shell.

**Working-directory rule:** PHPMake loads `build.json` from the process's current working directory. Filesystem task paths are interpreted relative to that same directory, not relative to the location of the PHPMake package.

## 2. Build configuration

A build file is a JSON object with five required top-level properties:

| Property | Type | Meaning |
|---|---|---|
| `name` | non-empty string | Project/build name |
| `description` | non-empty string | Human-readable description |
| `version` | non-empty string | Version of this build configuration/project; not the PHPMake package version |
| `targets` | object | Named target definitions |
| `default_target` | string | Name of the target run when no target argument is supplied |

A target supports:

| Property | Type | Meaning |
|---|---|---|
| `description` | string, optional | Target description |
| `depends` | array of strings, optional | Targets that must execute before this target |
| `tasks` | array | Ordered task definitions; required |

Each task is an object containing a `type` and a `params` object. Unknown properties, task types, and task parameters are rejected.

### Complete example

```json
{
  "name": "ExampleProject",
  "description": "Build and package the project",
  "version": "1.0.0",
  "targets": {
    "clean": {
      "description": "Remove previous build output",
      "tasks": [
        { "type": "delete", "params": { "path": "build" } }
      ]
    },
    "build": {
      "description": "Prepare build output",
      "depends": ["clean"],
      "tasks": [
        { "type": "create_directory", "params": { "name": "build" } },
        { "type": "copy", "params": { "source": "src", "dest": "build" } },
        { "type": "exec", "params": {
          "command": "php",
          "args": ["-l", "build/bootstrap.php"],
          "timeout": 60
        } },
        { "type": "echo", "params": { "message": "Build completed." } }
      ]
    },
    "package": {
      "description": "Create a ZIP package",
      "depends": ["build"],
      "tasks": [
        { "type": "archive", "params": { "source": "build", "output": "dist/example.zip" } }
      ]
    }
  },
  "default_target": "package"
}
```

Dependencies are resolved before a target runs. Tasks within a target execute in the order listed. A failed task causes the build to fail; later tasks in that target are not treated as successful work.

Target names are strings. Numeric-looking names are supported, but quote them in JSON as object keys. The value of `default_target` must match an existing target name.

## 3. Built-in task reference

All filesystem task paths must remain within the current working directory. Paths may be relative or absolute if their resolved location remains inside that directory. A `..` component is not automatically forbidden; it is rejected when resolution escapes the project boundary. Symlink/junction ancestors that redirect outside the project are rejected. Destructive tasks cannot target the project root.

### `create_directory`

Creates a directory and any missing parent directories. An already-existing directory is treated as success.

| Parameter | Required | Type |
|---|---:|---|
| `name` | yes | non-empty string |

```json
{ "type": "create_directory", "params": { "name": "build/generated" } }
```

A regular file or unsafe path at the requested location causes failure.

### `delete`

Deletes a file or directory tree. A path that does not exist is a successful no-op. Symbolic links and Windows junctions are removed as links; their targets are not recursively deleted.

| Parameter | Required | Type |
|---|---:|---|
| `path` | yes | non-empty string |

```json
{ "type": "delete", "params": { "path": "build/generated" } }
```

The project root is protected. A permission error, unreadable directory, or failed child deletion makes the task fail; deletion is not an all-or-nothing transaction, so earlier successful removals may already have occurred.

### `copy`

Copies a regular file or a directory tree. Missing destination parent directories are created. Directory copying merges source content into an existing destination tree. Copy operations stage their output and publish it transactionally; an existing destination is preserved when staging/publication fails. Symbolic links, junctions, and unsupported filesystem entries are not followed.

| Parameter | Required | Type |
|---|---:|---|
| `source` | yes | non-empty string |
| `dest` | yes | non-empty string |

Copy one file:

```json
{ "type": "copy", "params": { "source": "config/app.json", "dest": "build/config/app.json" } }
```

Copy a directory:

```json
{ "type": "copy", "params": { "source": "src", "dest": "build/src" } }
```

The source must exist and be a regular file or directory. Copying a directory into itself or one of its descendants is rejected. A copy failure returns a failed task result; cleanup failure may additionally raise a diagnostic because incomplete staging data could not be removed.

### `exec`

Runs an external process directly, without invoking a command shell. The `command` value is tokenized into the executable and any command-line tokens. Prefer putting arguments in the separate `args` array, especially when values contain spaces or shell-significant characters.

| Parameter | Required | Type | Default / limit |
|---|---:|---|---|
| `command` | yes | non-empty string | — |
| `args` | no | array of scalar values | empty array |
| `timeout` | no | number, finite and greater than 0 | 300 seconds; maximum 86,400 seconds |

Example:

```json
{
  "type": "exec",
  "params": {
    "command": "php",
    "args": ["-d", "display_errors=1", "scripts/build.php", "--release"],
    "timeout": 900
  }
}
```

Shell syntax is not interpreted: pipes, redirection, wildcard expansion, environment-variable expansion, and shell built-ins are not available unless you explicitly launch a shell as the executable and provide its arguments. Doing so executes that shell with the permissions of PHPMake.

Standard output and standard error are captured separately, each with a 1 MiB limit. Exceeding either limit fails the task and triggers process termination. A non-zero process exit code, timeout, or cooperative cancellation also fails the task.

On Windows, PHPMake uses a kill-on-close Job Object for the launched process tree. On Linux, it uses a POSIX process group through the POSIX extension or available `setsid`/`kill` utilities. If neither POSIX mechanism is available, only the direct process can be terminated. Descendants that deliberately detach or create a new session may escape cleanup. These mechanisms are reliability features, not a security sandbox.

### `echo`

Writes the configured message to PHPMake's informational output. An empty string is allowed.

| Parameter | Required | Type |
|---|---:|---|
| `message` | yes | string |

```json
{ "type": "echo", "params": { "message": "Compiling assets..." } }
```

### `archive`

Creates a ZIP archive from a regular file or directory. For a file source, the archive entry uses the file's basename. For a directory source, entries use paths relative to the source directory. Symbolic links and junctions are rejected. The archive is built in a temporary file beside the destination and published only after successful completion, preserving an existing archive on failure.

| Parameter | Required | Type |
|---|---:|---|
| `source` | yes | non-empty string |
| `output` | yes | non-empty string |

```json
{ "type": "archive", "params": { "source": "build", "output": "dist/release.zip" } }
```

Current archive input limits:

- 10,000 files
- 20,000 filesystem entries
- Directory depth of 256
- 512 MiB per input file
- 2 GiB total uncompressed input

The output path must remain within the project working directory and must not overwrite the source.

## 4. Command-line reference

Run `php vendor/bin/phpmake.php --help` to print the same option definitions used by the argument parser.

| Option | Alias | Description |
|---|---|---|
| `--help` | `-h` | Print usage and options |
| `--version` | `-v` | Print PHPMake version |
| `--diagnostics` | — | Print PHP version, architecture, OS, and loaded extensions |
| `--validate-build` | `-vb` | Load and validate `build.json`, then exit without running tasks |
| `--debug` | `-d` | Enable debug logging |
| `--silent` | `-s` | Suppress normal output; errors remain visible on STDERR |
| `--no-log` | `-nl` | Disable file logging |
| `--init` | — | Create a sample `build.json` without overwriting an existing entry |

A target name may be supplied as the positional argument:

```sh
php vendor/bin/phpmake.php test --debug
```

Only one target argument is accepted. Conflicting action options (for example, `--help` and `--version`) and unknown options are rejected.

### Logging

By default, PHPMake writes `build.log` in the current working directory. Log writes and rotation use `build.log.lock`. The active log rotates at 10 MiB and retains up to five rotated files (`build.log.1` through `build.log.5`). Use `--no-log` to disable file logging. Debug mode adds diagnostic messages. For caught exceptions, diagnostics include the exception class, source location, and stack trace; stack frames do not include argument values. Exception messages and child-process output may still contain secrets. With `--silent --debug`, debug details are retained in `build.log` but are not printed to the console. Silent mode suppresses normal console output, but errors remain visible on STDERR.

## 5. Troubleshooting

| Symptom | Likely cause | What to check |
|---|---|---|
| `Build file ... not found or unreadable` | No readable `build.json` in the current directory | Run PHPMake from the project directory or place the file there |
| `Invalid build configuration` | Missing/unknown property, malformed target/task, invalid parameter | Run `--validate-build`; compare with the schema and task reference |
| Unknown task type | The task identifier is not registered | Use one of the six built-in identifiers; third-party runtime task registration is not currently supported |
| Dependency/cycle error | Missing dependency or circular target dependency | Check every `depends` name and remove cycles |
| Safety violation / path escapes project | A filesystem path resolves outside CWD, crosses an unsafe link, or targets a protected root | Resolve the path relative to the invocation directory and inspect symlink/junction ancestors |
| Copy/archive fails | Source missing, unsupported link/entry, permission issue, size/count limit, or publication error | Use `--debug`; verify source readability and destination writability |
| External command does not start | Executable unavailable or invalid command tokenization | Check executable availability and quote handling; use an explicit executable path |
| External command times out | Runtime exceeds configured timeout | Increase `timeout` only when justified; inspect child processes and command behavior |
| External command reports output-limit failure | STDOUT or STDERR exceeded 1 MiB | Reduce command verbosity or redirect output within the child command's own supported interface |
| Build stops after a task | The task returned failure or threw an exception | Read console STDERR and `build.log`; use `--debug` for exception class, source location, and stack trace; PHPMake stops the current build on task failure |
| `--init` says build.json already exists | A file, directory, or link already occupies the destination | Back up or rename it manually; PHPMake intentionally does not overwrite it |

Use `--diagnostics` when investigating runtime/environment problems. It reports the PHP runtime and loaded extensions; it does not run a build or validate the configuration.

## 6. Extending PHPMake

Built-in task metadata is centralized in `PhpMake\\Task\\TaskDefinitionRegistry`. `TaskFactory` creates the class registered for a task identifier, while `BuildValidator` consumes the registry's required and allowed parameter lists. The published JSON Schema is maintained at `schema/build-schema.json`; `BuildSchemaParityTest` protects consistency between the registry, schema, and runtime validation. The registry is static and has no public registration method: extending `BaseTask` or implementing `TaskInterface` alone does not make a new task type available to build files. See [API-COMPATIBILITY.md](API-COMPATIBILITY.md) for the supported API boundary.

To contribute a new built-in task to PHPMake itself:

1. Add a class under `src/Task/Types` extending `BaseTask`.
2. Implement `validateParams()`, `runTask()`, and `getType()`; validate types and reject unsupported values before side effects.
3. Add the task identifier, class, required parameters, and allowed parameters to `TaskDefinitionRegistry`.
4. Add the corresponding parameter definition and task identifier to `schema/build-schema.json`.
5. Add unit tests for successful execution, invalid input, failure behavior, boundary cases, and platform-specific behavior where applicable.
6. Update this guide, the README task summary, and FAQ when the user-visible contract changes.
7. Run the full test and quality-check commands described in [Contributing](CONTRIBUTING.txt).

The current architecture is described in [Architecture](ARCHITECTURE.md). It covers task registration, shared filesystem primitives, process launch/supervision, typed configuration, CLI output, and operational benchmarking.

## 7. Security and trust boundary

A build configuration is executable input. In particular, `exec` launches arbitrary local programs with the same operating-system permissions and inherited environment as PHPMake. Filesystem containment applies to built-in filesystem tasks; it does not sandbox child processes or restrict their network access.

Do not run untrusted build files with deployment credentials or privileged accounts. Use isolated CI runners/containers and enforce outbound network restrictions at the infrastructure layer when needed. See [SECURITY.md](../SECURITY.md) for the project's security policy and reporting process.
