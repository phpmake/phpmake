# Migration Guide: PHPMake 2.0.2 to the Next 2.x Release

This guide records compatibility-sensitive behavior added after the 2.0.2 baseline.
It does not assume a release version or date; those are selected during final release
preparation.

## PHP and installation

- PHP 8.0 or newer remains supported.
- Composer 2 or newer is required.
- Runtime PHP extensions remain `ctype`, `json`, and `zip`.
- The Composer package remains `phpmake/phpmake`; the CLI entry point remains
  `vendor/bin/phpmake.php` (or the global Composer binary).

No Composer package rename or PHP minimum-version increase is planned by this release
readiness work.

## Build configuration validation

Build files are now checked more strictly against the JSON object/array shapes expected
by the schema and runtime:

- The top-level build configuration must be a JSON object, not an array.
- `targets` must be a JSON object; each target must be a JSON object.
- `depends` and `tasks` must be JSON arrays.
- Each task must be a JSON object and its `params` must be a JSON object.
- `exec.params.args`, when present, must be a JSON array.
- Unknown top-level, target, task, and task-parameter properties are rejected.
- Required task parameters and their value types are validated before execution.
- Build configuration files are limited to 2 MiB.

**Action:** Run `php vendor/bin/phpmake.php --validate-build` before upgrading a
build pipeline. Correct malformed JSON shapes, unsupported fields, and task parameters
reported by validation.

## Filesystem task boundaries

Filesystem task paths are interpreted relative to the process working directory and
must remain within that project directory after normalization and link/junction checks.
UNC and drive-relative Windows paths are rejected. A `..` segment is accepted only
when its normalized destination remains inside the project directory. Destructive tasks
cannot target the project root.

**Action:** Run PHPMake from the intended project directory and review all paths in
`copy`, `delete`, `create_directory`, and `archive` tasks. Do not rely on symlinks
or junctions to access locations outside the project directory.

## Archive limits

ZIP creation rejects link inputs and enforces these limits:

| Limit | Maximum |
|---|---:|
| Files | 10,000 |
| Filesystem entries | 20,000 |
| Directory depth | 256 |
| Individual input file | 512 MiB |
| Total uncompressed input | 2 GiB |

Archives are staged and published only after successful completion; an existing output
archive is preserved when creation fails.

**Action:** Split very large packaging jobs into smaller archives if they exceed these
limits. Ensure downstream extraction tools also defend against traversal, symlink, and
decompression-bomb attacks.

## External process execution

The `exec` task passes an argument vector directly to the process; it does not perform
shell expansion, pipelines, or shell redirection. The default timeout is 300 seconds,
the maximum is 86,400 seconds, and each captured output stream is limited to 1 MiB.
Windows process cleanup uses Job Objects. Linux uses a POSIX process group when
available, with a `setsid`/`kill` fallback; otherwise only the direct process can be
terminated. Descendants that deliberately detach may escape cleanup.

**Action:** Review `exec` commands that depended on implicit shell syntax. Invoke the
required shell explicitly when shell features are genuinely needed, and do not treat
process cleanup as a sandbox or security boundary.

## PHP API and extension points

The documented stable PHP interface is `PhpMake\\Task\\TaskInterface`. The built-in
task registry is static and does not expose a runtime registration method. Implementing
`TaskInterface` or extending `BaseTask` does not register a third-party task type.

**Action:** Treat custom task implementation instructions as contribution guidance for
PHPMake itself, not as a supported third-party plugin API. Review
[API and Backward Compatibility](API-COMPATIBILITY.md) before depending on implementation
classes.

## Migration summary

A valid existing build that already uses the documented task identifiers, parameter
names, JSON shapes, project-contained filesystem paths, and bounded archive/process
inputs should not require a structural migration. The safest upgrade path is:

1. Back up the current build configuration.
2. Install the new PHPMake version in a test environment.
3. Run `--validate-build`.
4. Run the default target and package target with non-production inputs.
5. Review logs and generated archives before promoting the upgrade.

PHPMake does not sandbox `exec`; continue to run only trusted build configurations with
least-privilege credentials and appropriate CI runner isolation.
