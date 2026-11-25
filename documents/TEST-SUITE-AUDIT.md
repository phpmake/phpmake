# Test Suite

The suite contains PHPUnit unit, integration, system, and smoke tests. CI exercises PHP 8.0–8.5 on Linux and Windows.

## Test policy

- Apply Arrange / Act / Assert to every test method. Keep setup and input construction in Arrange, the operation under test in Act, and observable checks in Assert.
- Do not use mocks or mocking frameworks. Use real objects, subprocesses, and temporary filesystem fixtures where appropriate.
- Give every test class and method a short, standard PHPDoc summary. Add parameter and return annotations when they clarify the contract.
- Assert observable output, state, side effects, and failure behavior rather than implementation details.
- Keep tests deterministic, isolated, bounded, and platform-aware. Restore process-wide state and clean up temporary resources.
- A skipped test is not evidence that the skipped behavior passed.

## Coverage

The PHP 8.3 Xdebug job publishes JUnit, Clover, and HTML reports. It enforces at least 80% overall executable-line coverage and 100% for seven critical files. Branch coverage is reported without a global threshold.

See [CODE-COVERAGE.md](CODE-COVERAGE.md) for the exact gate. Use a successful CI artifact for current coverage percentages; static test counts are not coverage measurements.

## Review focus

Prioritize filesystem path/link handling, atomic publication and rollback, process startup/timeout/cancellation/termination, ZIP resource limits, malformed configuration, and logger failure/concurrency paths.

Run `composer test` locally. Do not describe the suite as passing until the relevant CI run completes.
