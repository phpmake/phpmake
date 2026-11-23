# Test Suite Audit

**Scope:** PHPUnit unit, integration, system, and smoke tests.  
**Supported matrix:** PHP 8.0–8.5; Windows and GNU/Linux.  
**Constraints:** PHP `^8.0`; PHPUnit retained; no mocks.

## Confirmed work

- 50 PHP files under `tests/` were audited.
- All 268 test methods in that audit contain explicit Arrange, Act, and Assert sections.
- The audit found no PHPUnit mock objects, mock-builder usage, Mockery, or Prophecy references. Concrete stream-wrapper fixtures are used for filesystem failure scenarios.
- Previously undocumented named test classes and methods were documented.
- Integration subprocesses use bounded execution and temporary output capture.
- Filesystem tests use isolated temporary workspaces and shared cleanup helpers.
- ZIP tests require `ext-zip`, which is a declared runtime dependency; they do not silently skip when it is missing.

## Coverage evidence and gaps

The latest numeric baseline recorded in this repository is from Phase 19:

| Metric | Recorded result |
|---|---:|
| Tests | 263 |
| Assertions | 1,244 |
| Line coverage | 80.35% (1,280 / 1,593) |
| Method coverage | 52.14% (61 / 117) |
| Class coverage | 36.67% (11 / 30) |
| Platform skips | 6 |

This is a historical measurement, not a current measurement of the merged `main` branch. The CI coverage job generates JUnit, Clover, and HTML reports and publishes statement coverage. It does not currently publish branch coverage or enforce an 80% threshold.

## Remaining audit work

- Generate a fresh coverage report from current `main`.
- Report line, branch, method, and class coverage separately.
- Inventory source classes and methods with no tests or insufficient meaningful assertions.
- Review every skip and retain only skips justified by unavailable platform capabilities. Ensure the Windows and Linux jobs collectively exercise platform-specific paths.
- Add missing edge-case and failure-path tests based on the coverage report and security review.
- Identify critical paths and define explicit coverage expectations for them.

## Test requirements

- Use Arrange / Act / Assert sections and behavior-focused test names.
- Assert observable results, state changes, errors, and side effects; avoid assertions that merely prove a code path ran.
- Do not introduce mocks or mocking frameworks.
- Keep tests deterministic, isolated, bounded, and safe to run on Windows and Linux.
- Do not claim the suite passes until the relevant GitHub Actions run completes successfully.

## Validation commands

```sh
composer test
composer lint
composer analyse
composer format-check
composer refactor-check
```

The GitHub Actions workflow additionally validates Composer metadata and dependencies, performs a clean consumer installation, runs PHP 8.0–8.5 compatibility tests, and executes Windows filesystem/process tests.
