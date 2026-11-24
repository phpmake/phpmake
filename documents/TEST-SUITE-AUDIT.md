# Test Suite Audit

**Scope:** PHPUnit unit, integration, system, and smoke tests, plus source-to-test mapping.  
**Supported matrix:** PHP 8.0–8.5; Windows and GNU/Linux.  
**Constraints:** PHP `^8.0`; PHPUnit retained; no mocks.

## Audit baseline

- 52 PHP files under `tests/` are included in the source inventory.
- The current source inventory contains 279 PHPUnit test methods across the configured unit, integration, system, and smoke suites. All test methods use explicit Arrange / Act / Assert sections.
- No PHPUnit mock objects, mock-builder usage, Mockery, or Prophecy references were found in the current test inventory.
- The source inventory is a static audit, not a fresh coverage measurement. Use the most recent successful CI Clover artifact for current line and branch percentages.

## Recent test and maintenance changes

- Improved the successful `ExecTask` test to assert the actual child-process output persisted by the logger, rather than only asserting a successful return value.
- Improved the `EchoTask` test to assert the exact observable log message.
- Improved the timeout test to assert the timeout diagnostic, not just a false return value.
- Improved malformed execution-order and task-list tests to assert their exact error output.
- Added exact 2 MiB `BuildLoader` input-boundary and 1 MiB `ExecTask` stdout capture-boundary tests. Existing over-limit tests continue to verify rejection above both limits.
- Added a task-definition registry contract test that checks all six identifiers, their concrete task classes, lookup consistency, and unknown-type behavior.
- Added a regression test for `BaseTask` directory-link detection after consolidating link/junction handling in `FilesystemTree`.
- Added a subprocess regression test for `ConsoleOutput::error()` to verify errors go to STDERR and do not contaminate STDOUT.
- Added direct tests for raw `ConsoleOutput::stdout()` output and `PlatformSupport::assertSupported()` on the current runtime.
- Added direct POSIX launcher tests for normal metadata, the no-`ext-posix` utility fallback, and the no-utility fallback.
- Added real-subprocess tests for `ProcessSupervisor::signalProcess()` and `signalProcessGroup()` fallback behavior without `ext-posix`.

## Source-to-test coverage findings

The source inventory contains 33 production PHP files. Most public behavior has at least one direct unit test or is exercised through the CLI integration/system suite. The following areas remain weaker or depend on indirect/platform-specific coverage:

| Production area | Current evidence | Remaining limitation |
|---|---|---|
| `BuildExecutor::execute()` | Missing target, numeric target, default target tests; end-to-end dependency execution | No dedicated assertion for every logger mode and resolver/factory failure interaction |
| `BuildLoader::load()` | Valid/invalid JSON shapes, missing file, malformed JSON, over-limit file, exact-limit file | Unreadable/open/read-stream failure paths are difficult to induce portably without mocks |
| `FilesystemIdentity::sameFile()` | Same and different file identity; exercised by copy/archive/publisher tests | Stat-failure fallback and Windows case-insensitive fallback are not exhaustively isolated |
| `FilesystemTree` and atomic publishers | Copy/remove/link/path, collision, rollback, and failure-path tests | OS-specific permission failures are not portable; some cleanup and rollback failure combinations remain difficult to induce deterministically |
| `ProcessSupervisor` | Real process termination, descendant discovery, tracked descendants, PATH-unavailable behavior, and no-`ext-posix` single-process/process-group signal fallbacks | Linux process-table and signal branches are Linux-only; Windows Job Object behavior is tested separately |
| `PosixProcessLauncher` | Direct metadata test, no-`ext-posix` `setsid`/`kill` fallback, and empty-PATH fallback | Not every partial PATH/setsid/kill availability combination has an isolated assertion |
| `WindowsProcessLauncher` | PowerShell argument payload and command construction test | Actual Job Object process-tree behavior can only execute on Windows CI |
| `ExecTask` | Success, non-zero exit, timeout, stdout/stderr overflow, cancellation, process-tree cleanup, argument secrecy | Process-start failure and every exact capture-buffer boundary are not independently isolated |
| `ZipTask` | Archive contents, output-inside-source, input limit, snapshots, cleanup, preservation of previous output | Not every archive count/depth/per-file/aggregate-size boundary is independently tested |
| `ConsoleOutput` | Direct raw stdout, informational/success output, and isolated STDERR routing | All public output methods have direct tests |

The table identifies remaining test depth, not confirmed production defects. The fresh CI coverage artifact should be used to refine this inventory before treating any method as untested.

## Methods with remaining weak or indirect coverage

The following methods or branches should be treated as coverage gaps until the current Clover/HTML report proves otherwise:

- `BuildLoader::load()`: failure to open an existing file and a failed stream read are not deterministically induced by the current portable tests.
- `FilesystemIdentity::sameFile()`: the stat-failure fallback and Windows case-insensitive fallback are not isolated in dedicated tests.
- `PosixProcessLauncher::findExecutable()`: the no-`ext-posix` tests cover executable discovery and an empty PATH; partial `setsid`/`kill` availability combinations remain untested.
- `ProcessSupervisor::signalProcess()` and `signalProcessGroup()`: real subprocess tests now cover both utility-based fallbacks without `ext-posix`; missing-utility and command-failure conditions remain untested.
- `AtomicFilePublisher::publish()` and `AtomicDirectoryPublisher::publish()`: the most severe double-failure paths, where publication and rollback both fail and a recovery backup must be retained, lack deterministic end-to-end tests.
- `ZipTask::addFilesToZip()`: all archive limits (file count, total entries, depth, per-file bytes, and aggregate bytes) are not each tested at both their exact boundary and one unit beyond.

These are prioritized test gaps, not claims that the methods are untested at every level. Several are exercised indirectly through integration tests or adjacent failure-path tests.

## Platform-dependent skips

The source search found 37 skip call sites across 10 test files. These are conditional skips: the affected test is skipped only when the host OS, filesystem semantics, or required operating-system capability is unavailable. They fall into these groups:

| Skip category | Test areas | Why it is skipped | Validation path |
|---|---|---|---|
| POSIX process-table discovery, descendant signaling, process groups, cancellation, and detached-session cleanup | `ProcessSupervisorTest`, `TaskExecutionTest` | Requires Linux process-table behavior and/or `posix`/`pcntl` functions | Linux test job; the no-`ext-posix` fallback smoke check runs separately |
| Windows Job Object descendant cleanup | `TaskExecutionTest` | Requires Windows process/job APIs | Windows test matrix |
| POSIX directory permission failures (four tests) | `FilesystemFailurePathsTest`, plus publisher validation | `chmod(0500)` and directory write-denial semantics are not equivalent on Windows | Linux test job; these tests remain legitimately skipped on Windows |
| Symbolic-link and junction creation | `CopyTaskSafetyTest`, `LoggerSecurityTest`, `InitSymlinkTest`, `PathSafetyTest`, `AtomicPublisherValidationTest`, `AtomicFilePublisherTest` | Link creation may require Windows privileges or filesystem support; dangling links and junctions also differ by OS | Windows and Linux jobs where creation is supported; capability failures are explicitly skipped |
| Hardlink creation | `CopyTaskSafetyTest`, `LoggerSecurityTest`, `PathSafetyTest` | The runtime or backing filesystem may not support hardlinks | Run where the filesystem supports hardlinks |
| FIFO/named-pipe creation | `CopyTaskSafetyTest`, `LoggerSecurityTest` | POSIX special-file creation is unavailable on Windows | Linux test job |
| Windows path syntax (UNC, extended UNC, drive-relative, extended-length drive paths) | `PathSafetyTest` | These path forms are Windows-specific | Windows test matrix |
| Case-only sibling paths | `PathSafetyTest` | A case-insensitive filesystem cannot represent two sibling entries differing only by case | Case-sensitive filesystem |
| Case-variant archive aliases | `ZipTaskTest` | Requires a case-insensitive filesystem so two path spellings identify one entry | Windows test matrix; case-sensitive filesystems skip it |

These skips are not evidence that the behavior passed on the skipped platform. The Windows and Linux jobs are complementary. The remaining gap is Windows-specific failure testing for filesystem operations that currently rely on POSIX permission denial; do not replace those tests with assumptions that NTFS permissions behave like POSIX modes.

## Assertion quality findings

The earlier suite included tests that asserted only a success boolean even when the behavior produced observable output. The ExecTask and EchoTask tests now verify actual output, timeout and task-executor failure tests verify diagnostics, and registry and size-boundary assertions cover explicit contracts.

Other tests that assert only an exit code are retained where the test's contract is the process result and the same behavior is verified more deeply by integration tests. Future additions should assert resulting files, archive entries, process state, preserved data, emitted diagnostics, or other observable outcomes whenever those are the actual contract.

## Historical coverage evidence

The older Phase 19 numeric baseline recorded in this repository is:

| Metric | Recorded result |
|---|---:|
| Tests | 263 |
| Assertions | 1,244 |
| Line coverage | 80.35% (1,280 / 1,593) |
| Method coverage | 52.14% (61 / 117) |
| Class coverage | 36.67% (11 / 30) |
| Platform skips | 6 |

This Phase 19 result is historical and predates the current branch-coverage reporting and coverage gates. Current CI generates JUnit, Clover, and HTML reports, publishes line and branch coverage, enforces at least 80% overall executable-line coverage, and requires 100% line coverage in seven designated critical contract files. See `CODE-COVERAGE.md` for the enforced list and current policy.

## Current coverage policy

- Overall executable-line coverage is enforced at a minimum of 80% by `tools/check-coverage.php`.
- Xdebug branch coverage is collected in the dedicated PHP 8.3 coverage job and published separately in the CI summary and Clover report.
- Seven critical contract files have a 100% executable-line coverage requirement enforced by the coverage gate. The exact list and rationale are documented in `CODE-COVERAGE.md`.
- Branch coverage is reported but does not yet have a project-wide percentage gate. The first stable branch measurement will be used to set a defensible target.
- A pre-Phase-28 Clover artifact measured 1,284 / 1,594 statements (80.55%). Branch coverage was not collected. This is a historical baseline, not a current-main measurement.
- A current-main percentage must be taken from a successful CI coverage artifact. No current-main percentage is claimed until that artifact is available.

## Remaining work

- Review the fresh CI Clover and HTML reports for exact uncovered source lines and branches.
- Report current line, branch, method, and class coverage separately.
- Add deterministic tests for uncovered high-risk branches, especially process startup failures, archive resource limits, and rollback/cleanup failures.
- Add Windows-specific filesystem failure tests where a deterministic Windows-native failure condition can be created without mocks.
- Keep platform-specific tests assigned to the matching CI runner; do not count a skipped test as platform validation.
- Extend hard 100% file-level gates to additional security-critical filesystem, process, and archive paths as their tests reach the required coverage.

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

# After generating build/coverage.xml with Xdebug
composer coverage-check
```

The GitHub Actions workflow additionally validates Composer metadata and dependencies, performs a clean consumer installation, runs PHP 8.0–8.5 compatibility tests, executes Windows filesystem/process tests, and collects branch coverage on PHP 8.3 with Xdebug.
