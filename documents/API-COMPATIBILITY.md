# API and Compatibility

**Package:** `phpmake/phpmake`  
**Current source version:** `2.0.2` (`VERSION.txt`)  
**Compatibility line:** 2.x

## Supported integration contract

The supported user-facing integration points are:

- **CLI:** Composer-installed `phpmake` executable, documented options, target selection, output/error behavior, and exit status.
- **Build configuration:** JSON structure and six task identifiers defined by `schema/build-schema.json` and the runtime task registry.
- **Composer:** package `phpmake/phpmake`, PSR-4 namespace `PhpMake\\` mapped to `src/`, executable `bin/phpmake.php`, PHP `^8.0`, and extensions `ext-ctype`, `ext-json`, and `ext-zip`.
- **Task interface:** `PhpMake\\Task\\TaskInterface`, with `execute(bool $debug, bool $silent): bool` and `getType(): string`.
- **Version:** `PhpMake\\Config::TOOL_VERSION`, which must match `VERSION.txt`.

The CLI and build-file format are the primary public APIs. `TaskInterface` is the only designated stable PHP extension interface.

## Task extension boundary

PHPMake currently supports six built-in task types. `TaskDefinitionRegistry` is static and does not provide runtime registration. Extending `BaseTask` or implementing `TaskInterface` does not register a third-party task type.

Instructions for adding a task describe contributions to PHPMake itself, not a third-party plugin API. A plugin API would require a separately designed registration, validation, schema, and compatibility contract.

## Compatibility policy

- Changes within 2.x must be additive or backward-compatible for the supported contract.
- Breaking changes to the supported contract require a new major version and migration guidance.
- Deprecate supported APIs before removal when practical.
- Security fixes may require exceptional behavior changes; document impact and mitigation.
- Changes to task identifiers/parameters, JSON structure, CLI options, exit behavior, Composer metadata, or `TaskInterface` require compatibility review and regression tests.

Implementation classes are not stable APIs merely because they are public or autoloadable.

## Runtime and platforms

- PHP constraint: `^8.0`.
- CI currently tests PHP 8.0–8.5.
- Supported operating systems: Windows and GNU/Linux.
- Required extensions: `ctype`, `json`, and `zip`.
- Composer 2 or newer is required for installation and development workflows.

The PHP constraint and CI matrix are different things: the package declares PHP `^8.0`; the currently exercised matrix is PHP 8.0–8.5.

## Compatibility checks

The test suite protects Composer metadata, platform support, the stable task interface, schema/registry parity, CLI behavior, and clean Composer consumer installation. GitHub Actions runs PHP compatibility tests on Linux and filesystem/process tests on Windows for PHP 8.0–8.5.

Update this document, the User Guide, schema, and changelog whenever the supported contract changes.
