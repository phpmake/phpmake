# Code Quality

## Automated checks

| Command | Check |
|---|---|
| `composer test` | PHPUnit suite |
| `composer lint` | PHP syntax |
| `composer analyse` | PHPStan |
| `composer format-check` | PHP-CS-Fixer |
| `composer refactor-check` | Rector dry run |

CI also validates YAML, XML, JSON, Composer metadata, dependency declarations, advisories, and a clean Composer consumer installation. Linux PHP 8.0–8.5 and Windows PHP 8.0–8.5 are tested.

## Design controls

- Task metadata is centralized in `TaskDefinitionRegistry`.
- `schema/build-schema.json` is checked against the runtime registry.
- Filesystem operations use shared traversal, identity, and atomic-publication helpers.
- Process execution uses argument vectors, bounded output, timeouts, and platform-specific cleanup.
- Console output separates stdout and stderr; `Logger` handles persistent logs and rotation.

## Test rules

Use PHPUnit with Arrange / Act / Assert in every test method. Do not add mocks or mocking frameworks. Prefer deterministic tests that assert observable state and failure behavior. Keep tests bounded and portable. Every class and method must have a short, standard PHPDoc summary; add useful parameter and return annotations where applicable.

See [CODE-COVERAGE.md](CODE-COVERAGE.md) for coverage gates. A CI check is complete only when its run succeeds.
