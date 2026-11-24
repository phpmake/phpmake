# API and Compatibility

## Supported contract

The stable integration surface is:

- CLI options, output streams, target selection, and exit status.
- `build.json` structure and the six task types in `schema/build-schema.json`.
- Composer package `phpmake/phpmake`, executable `bin/phpmake.php`, and namespace `PhpMake\\`.
- `PhpMake\\Task\\TaskInterface`: `execute(bool $debug, bool $silent): bool` and `getType(): string`.
- `PhpMake\\Config::TOOL_VERSION`, which must match `VERSION.txt`.

The task registry is static. Implementing `TaskInterface` or extending `BaseTask` does not register a third-party task.

## Compatibility policy

- PHP `^8.0`, Composer 2+, and extensions `ctype`, `json`, and `zip`.
- CI tests PHP 8.0–8.5 on Windows and Linux.
- Prefer additive changes within 2.x. Breaking public-contract changes require a major release and migration notes.
- Security fixes may require documented behavior changes.
- Changes to task names, parameters, schema, CLI behavior, Composer metadata, or `TaskInterface` require regression tests.

Implementation classes are not stable APIs unless listed above.

## Validation

`BuildSchemaParityTest` checks the schema against `TaskDefinitionRegistry`. Update the schema, User Guide, README, FAQ, migration guide, and changelog when the build-file contract changes.
