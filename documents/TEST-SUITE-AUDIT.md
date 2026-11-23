# Test Suite Audit

**Baseline:** `main` at `41864c31ef5a2a4c9b2e106a634006bfaf920d58`  
**Current follow-up branch:** `quality/phase-19-ci-failure-triage`  
**Scope:** PHPUnit unit, integration, system, and smoke tests.  
**Compatibility:** PHP 8.0–8.5; Windows and Linux.  
**Constraints:** PHPUnit retained; no mocks introduced; production source unchanged.

## Changes in this pass

- Phase 19 fixes the YAML lint failure in the coverage-summary step by shortening the overlong diagnostic line.
- The Windows process-tree helper regression test now allows a bounded startup window for the PowerShell Job Object launcher before timing out; its child marker deadline remains later than the parent timeout so descendant cleanup is still verified.
- CI run #587 confirmed the PHP 8.3 coverage report generation, Clover summary, and artifact upload steps succeed. Linux PHP 8.0–8.5 compatibility and the performance matrix passed.
- CI run #587 exposed the YAML line-length issue and the Windows process-helper test's 0.4-second startup assumption on PHP 8.3 and 8.4. The changes above address those two observed failures; a fresh CI run is required to verify the fixes.

- Phase 17 routes test subprocesses through the existing `ProcessLauncher` and `ProcessSupervisor`: Windows uses the kill-on-close Job Object launcher; POSIX uses an isolated session/process group where supported, with descendant discovery as a fallback. Timed-out processes and remaining descendants are terminated before returning.
- Validate subprocess command lists, working directories, and finite positive timeout values before creating output files or launching a process.
- Added regression coverage for descendant termination on timeout and invalid process arguments/timeouts.
- Retained file-backed stdout/stderr capture, monotonic deadlines, and finally-based temporary output cleanup from Phase 16.

## Previous phases

- Phase 16 added `ProcessTestHelper` for real subprocess tests. It captures stdout/stderr to temporary files to avoid pipe-buffer deadlocks, applies a monotonic execution deadline, terminates timed-out processes, and removes capture files in a finally block.
- Migrated CLI build, Composer-installed binary, and `--init` integration tests to the shared bounded subprocess runner; each now fails explicitly if the process exceeds its deadline.
- Added helper regression tests for output larger than a typical pipe buffer and for forced timeout termination.
- Tightened the POSIX process-tree discovery test prerequisite so it skips when descendant cleanup cannot be guaranteed without `posix_kill`.
- Removed `BuildSmokeTest`, which duplicated build validation, dependency resolution, and build execution tests already owned by focused test classes.
- Moved its only distinct contract coverage (all `BuildConfiguration` getters) into `BuildConfigurationTest`.
- Removed `LoggerTest`, whose logger assertions are covered more thoroughly by `LoggerPersistenceTest`, while its task behavior cases are covered by dedicated task/filesystem tests.
- Added `EchoTaskOutputTest` to retain explicit coverage that a non-silent echo task writes the configured message.
- Removed obsolete no-op PHPUnit lifecycle hooks and redundant `DoesNotPerformAssertions`/legacy test attributes from the focused test classes updated in this pass.
- Added strict-types declarations to the updated legacy test files where missing.
- Removed ZIP-extension skip guards. `ext-zip` is a required Composer runtime dependency, so absence must fail the test environment rather than silently skip archive tests.
- Phase 12 refactored `BuildValidatorTest`, `TaskFactoryTest`, and `TaskInterfaceTest`: renamed generic methods, added behavior-focused docblocks, normalized Arrange / Act / Assert ordering, removed obsolete test attributes and empty docblocks, and kept the existing scenarios and assertions.
- Phase 13 introduced `TemporaryDirectoryTestHelper` to create unique canonical temporary workspaces and perform shared recursive cleanup through `FilesystemTree::remove`.
- Migrated `CopyTaskSafetyTest`, `FilesystemTaskTest`, `FilesystemTreeTest`, and `FilesystemFailurePathsTest` to the shared fixture lifecycle; removed the duplicate recursive cleanup implementation from `FilesystemTaskTest`.
- The migrated tests continue to restore the original working directory before cleanup; platform-specific directory-link handling remains in `DirectoryLinkTestHelper`.
- Phase 14 migrated `ZipTaskTest`, `CliBuildIntegrationTest`, `InitSymlinkTest`, and `ComposerBinTest` to the shared temporary-directory lifecycle.
- Phase 15 migrated `PathSafetyTest`, `SmokeTest`, and `TaskExecutionTest` to isolated temporary project roots with shared failure-safe cleanup.
- Replaced silent platform-only passing assertions with explicit PHPUnit skips for tests whose OS or filesystem prerequisites are unavailable.
- Renamed generic dependency-resolution and task-execution tests to describe the behavior and expected outcome; removed obsolete PHPUnit attributes and empty docblocks from the legacy task tests.
- Normalized Arrange / Act / Assert ordering in dependency, system-requirement, archive-publication, and initialization integration tests.
- Corrected the missing `FilesystemTree` import exposed by Phase 14 CI in `FilesystemFailurePathsTest`.
- Replaced the duplicated recursive CLI integration cleanup implementation with `TemporaryDirectoryTestHelper`; ZIP test workspaces now run from an isolated temporary project root.

## Audit observations requiring follow-up

- AAA markers and ordering remain inconsistent in several legacy test classes. In particular, exception expectation setup is frequently placed before Arrange comments, some methods combine multiple scenarios, and several fixture assertions are interleaved with behavior assertions.
- Continue the remaining suite-wide review for AAA ordering and test names; this pass addressed the remaining generic dependency-resolution and built-in task execution names identified in the legacy unit tests.
- Path-safety and smoke-test fixtures now use the shared workspace lifecycle; keep platform-specific junction creation/removal in `DirectoryLinkTestHelper` rather than merging it into generic directory cleanup.
- Process-tree and filesystem capability tests use explicit skips when platform prerequisites are absent; `failOnSkipped` is disabled so justified platform skips do not fail otherwise-valid matrix jobs. CI must still exercise each supported platform-specific path.

## Validation

The repository-connected editing environment does not provide a local PHP runtime or Composer installation. Run the complete PHPUnit suite and formatter/linter on the branch, including the PHP 8.0–8.5 Windows/Linux matrix, before treating this audit as complete. Do not infer a passing test result from the edits alone. Phase 13–17 changes require CI confirmation of fixture cleanup and bounded subprocess behavior on both Windows and Linux.

## Completion criteria

- [ ] Every test method follows a clear Arrange / Act / Assert structure across the full suite.
- [ ] Every test name describes the behavior and expected outcome across the full suite.
- [ ] Duplicate coverage is removed without losing unique behavior coverage.
- [ ] Fixtures are isolated and reliably cleaned up on success and failure.
- [ ] No mocks; platform-specific tests use explicit, justified skips only when their prerequisites are unavailable.
- [ ] Full PHPUnit, formatting, lint, and supported-platform matrix pass.
- [ ] Review the retained JUnit and HTML coverage artifacts; set a coverage threshold only after reviewing a stable baseline.
