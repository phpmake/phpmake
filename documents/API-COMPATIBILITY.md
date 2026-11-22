# API and Backward Compatibility Contract

**Package:** `phpmake/phpmake`  
**Current release baseline:** 2.0.2  
**Compatibility line:** 2.x  
**Status:** Active

This document identifies the supported integration surface, records the current PHP class/method inventory, and defines how future changes must preserve compatibility.

## 1. Supported compatibility contract

The following are the supported, stable integration points for the 2.x line:

1. **CLI:** the Composer-installed `phpmake` executable and the documented command-line options, target selection, standard output/error behavior, and process exit status.
2. **Build files:** the JSON configuration format and the six built-in task identifiers documented in the User Guide and defined by `schema/build-schema.json`. Runtime validation and the published schema must remain aligned.
3. **Composer installation:** package name `phpmake/phpmake`, PSR-4 root `PhpMake\` mapped to `src/`, the `bin/phpmake.php` executable, PHP requirement `^8.0`, and runtime extension requirements `ext-ctype`, `ext-json`, and `ext-zip`.
4. **Task interface:** `PhpMake\Task\TaskInterface` with `execute(bool $debug, bool $silent): bool` and `getType(): string`.
5. **Version constant:** `PhpMake\Config::TOOL_VERSION`, which must match the repository's `VERSION.txt`.

The CLI and build-file contracts are the primary user-facing API. The TaskInterface is the only currently designated stable PHP interface.

## 2. PHP class and method inventory

All classes under the Composer PSR-4 namespace are currently autoloadable. Public visibility is not, by itself, a promise that every implementation class or method is a stable library API. Unless listed in Section 1, the following classes and methods are implementation-facing and may be refactored with appropriate internal regression coverage.

| Namespace / class | Current public methods and constants |
|---|---|
| `PhpMake\Config` | `TOOL_VERSION` |
| `PhpMake\Build\BuildConfiguration` | `__construct`, `getName`, `getDescription`, `getVersion`, `getTargets`, `getDefaultTarget` |
| `PhpMake\Build\BuildExecutor` | `__construct`, `execute` |
| `PhpMake\Build\BuildLoader` | `load` |
| `PhpMake\Build\BuildValidator` | `validate` |
| `PhpMake\Cli\CliParser` | `parse`, `showHelp`, `showVersion`, `showDiagnostics` |
| `PhpMake\Cli\ConsoleOutput` | `stdout`, `stderr`, `info`, `error`, `success` |
| `PhpMake\Filesystem\AtomicDirectoryPublisher` | `publish` |
| `PhpMake\Filesystem\AtomicFilePublisher` | `publish` |
| `PhpMake\Filesystem\FilesystemIdentity` | `sameFile` |
| `PhpMake\Filesystem\FilesystemTree` | `copyDirectory`, `remove`, `isLink` |
| `PhpMake\Process\CommandLineParser` | `hasBalancedQuotes`, `tokenize` |
| `PhpMake\Process\PosixProcessLauncher` | `prepare` |
| `PhpMake\Process\ProcessLauncher` | `prepare` |
| `PhpMake\Process\ProcessSupervisor` | `getDescendantProcessIds`, `terminate` |
| `PhpMake\Process\WindowsConsoleSignalHandler` | `__construct`, `register`, `unregister`, `handleEvent` |
| `PhpMake\Process\WindowsProcessLauncher` | `build` |
| `PhpMake\Target\DependencyResolver` | `resolve` |
| `PhpMake\Task\TaskInterface` | `execute`, `getType` |
| `PhpMake\Task\BaseTask` | `__construct`, `execute`, `getType` |
| `PhpMake\Task\TaskConfiguration` | `__construct`, `fromArray`, `getType`, `getParams` |
| `PhpMake\Task\TaskDefinitionRegistry` | `all`, `find`, `types` |
| `PhpMake\Task\TaskExecutor` | `__construct`, `executeTasks` |
| `PhpMake\Task\TaskFactory` | `__construct`, `create` |
| `PhpMake\Task\Types\CopyTask` | `getType` (also inherits `execute`) |
| `PhpMake\Task\Types\CreateDirectoryTask` | `getType` (also inherits `execute`) |
| `PhpMake\Task\Types\DeleteTask` | `getType` (also inherits `execute`) |
| `PhpMake\Task\Types\EchoTask` | `getType` (also inherits `execute`) |
| `PhpMake\Task\Types\ExecTask` | `getType` (also inherits `execute`) |
| `PhpMake\Task\Types\ZipTask` | `getType` (also inherits `execute`) |
| `PhpMake\Utilities\Logger` | `__construct`, `info`, `debug`, `error`, `isDebugEnabled`, `isSilent` |

This inventory describes the current source surface; it does not promote the implementation-facing classes to stable extension points.

## 3. Task extension boundary

PHPMake currently supports the six built-in task types only. `TaskDefinitionRegistry` is a final, static registry with no public registration method. Extending `BaseTask` or implementing `TaskInterface` does not register a new task identifier with the build validator or factory.

The task implementation steps in the User Guide describe how to contribute a new built-in task to PHPMake itself. They are not a third-party plugin API. A runtime plugin/registration API would require a separate design, schema/validation integration, and an explicit compatibility commitment.

## 4. Backward-compatibility and deprecation policy

- Within 2.x, changes to the supported contract must be additive or backward-compatible.
- A breaking change to a supported contract requires a new major version and a migration guide.
- When practical, deprecate a supported API in a minor release before removing it in the next major release. Document the replacement and the earliest planned removal version.
- Security fixes may require an exceptional behavior change; document the impact and mitigation in the changelog and security advisory.
- Changes to build-file fields, task identifiers, task parameters, CLI options, exit behavior, Composer metadata, or the stable TaskInterface require compatibility review and regression tests.
- Internal implementation classes may be reorganized, but must retain their documented user-visible behavior.

No public API deprecation annotations or migration steps are declared in the current 2.0.2 source baseline.

## 5. Runtime and platform compatibility

The supported validation matrix is PHP 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5 on Linux; the Windows test matrix covers the same PHP versions. Supported operating systems are Windows and GNU/Linux.

Composer declares PHP `^8.0`, Composer 2 as the package-management requirement, and the runtime extensions `ctype`, `json`, and `zip`. PHPStan and Rector target PHP 8.0 so that analysis and automated refactoring do not introduce syntax newer than the minimum runtime.

The Composer constraint intentionally remains `^8.0`; CI currently verifies the explicit 8.0–8.5 matrix. A future PHP 8.x release should be added to CI before claiming it has been tested.

## 6. Compatibility verification

The following automated checks protect the contract:

- `ComposerPlatformConstraintTest`: Composer package identity, runtime requirements, autoload mapping, executable metadata, and version-file consistency.
- `PublicApiCompatibilityTest`: stable TaskInterface method names, parameter names/types, and return types.
- `BuildSchemaParityTest`: task identifiers and parameter definitions remain aligned across the registry, runtime validator, and JSON Schema.
- CLI, configuration, task, and Composer consumer integration tests: preserve documented command behavior and build-file compatibility.
- GitHub Actions: run PHP 8.0–8.5 compatibility tests and Windows tests.

Before merging a change that affects the supported contract, run the full test and quality-check suite and inspect the Linux/Windows and PHP-version workflow results. Update this document, the User Guide, schema, and changelog whenever the supported contract changes.
