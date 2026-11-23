# Test Suite Audit

**Baseline:** `main` at `ef95755743dbbb78e1eb8d2233aff53c2d7e551b`  
**Current follow-up branch:** `quality/phase-13-test-fixture-isolation`  
**Scope:** PHPUnit unit, integration, system, and smoke tests.  
**Compatibility:** PHP 8.0–8.5; Windows and Linux.  
**Constraints:** PHPUnit retained; no mocks introduced; production source unchanged.

## Changes in this pass

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

## Audit observations requiring follow-up

- AAA markers and ordering remain inconsistent in several legacy test classes. In particular, exception expectation setup is frequently placed before Arrange comments, some methods combine multiple scenarios, and several fixture assertions are interleaved with behavior assertions.
- Generic test names and inconsistent AAA structure remain in legacy classes outside the Phase 12 scope; continue the same focused refactor across the remaining unit and integration tests.
- Filesystem fixtures in older path-safety and ZIP tests remain candidates for migration; keep platform-specific junction creation/removal in `DirectoryLinkTestHelper` rather than merging it into generic directory cleanup.
- Some process-tree and filesystem capability tests remain platform/extension conditional. These are not ZIP dependency skips and need platform-aware CI validation.
- Continue auditing test methods that create their own nested fixtures, especially `PathSafetyTest`, `ZipTaskTest`, and integration process tests, for failure-safe cleanup and working-directory restoration.
- No production implementation changes are included in this test-only pass.

## Validation

The repository-connected editing environment does not provide a local PHP runtime or Composer installation. Run the complete PHPUnit suite and formatter/linter on the branch, including the PHP 8.0–8.5 Windows/Linux matrix, before treating this audit as complete. Do not infer a passing test result from the edits alone. Phase 13 changes require CI confirmation of fixture cleanup on both Windows and Linux.

## Completion criteria

- [ ] Every test method follows a clear Arrange / Act / Assert structure across the full suite.
- [ ] Every test name describes the behavior and expected outcome across the full suite.
- [ ] Duplicate coverage is removed without losing unique behavior coverage.
- [ ] Fixtures are isolated and reliably cleaned up on success and failure.
- [ ] No mocks and no skipped tests.
- [ ] Full PHPUnit, formatting, lint, and supported-platform matrix pass.
