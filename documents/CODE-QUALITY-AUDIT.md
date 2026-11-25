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
