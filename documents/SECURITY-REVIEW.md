# PHPMake Security Review

**Scope:** Source-level review of the local build tool, its filesystem tasks, external process execution, archive creation, configuration validation, and logging.

**Review type:** Independent review of the implementation and its regression-test strategy. This is not a third-party penetration test, formal security certification, or review of the host operating system.

## Security model

PHPMake executes build definitions supplied by the operator. The `exec` task intentionally launches arbitrary programs with the current user's privileges. A build definition must therefore be treated as trusted code. PHPMake is not a sandbox and does not make an untrusted `build.json` safe to execute.

The project protects its own filesystem task operations and attempts to contain process trees. It does not provide OS-level isolation, network egress filtering, credential isolation, or privilege separation.

## Review findings

| Area | Assessment | Remaining limitation |
| --- | --- | --- |
| External process invocation | Argument-vector execution without shell interpretation | The launched program still has the invoking user's permissions |
| Windows process containment | Job Object with kill-on-close; target starts suspended and is assigned before resume | Requires Windows PowerShell and permission to create/assign a Job Object; assignment failure is fail-closed |
| POSIX process containment | Dedicated process group plus descendant sampling and individual termination | A narrow fork-and-exit race remains; without process-group support, fallback is direct-process termination |
| Filesystem task boundaries | Canonical path checks, link/junction boundary checks, and pre-execution revalidation | Filesystem races cannot be eliminated completely in user space |
| Copy operations | Staged/transactional publication | Host-level failures and filesystem semantics remain relevant |
| ZIP creation | Rejects links and removes partial output on failure | Extraction safety belongs to the downstream extractor; consumers must defend against traversal, symlink, and decompression-bomb attacks |
| Output capture | Independent 1 MiB stdout/stderr limits | This bounds captured output, not total CPU, memory, disk, or network use by a child |
| Logging | Serialized writes and rotation | Logs may contain sensitive build output; deployment permissions and retention remain operator responsibilities |
| Dependency integrity | Composer audit is run in CI | CI audit is point-in-time and does not replace ongoing dependency review |

## Dynamic regression coverage

The test suite exercises the following security-sensitive behavior:

- Rejecting filesystem paths that traverse symlink/junction boundaries.
- Revalidating paths immediately before filesystem task execution.
- Preserving existing destinations when copy/archive operations fail.
- Rejecting ZIP inputs that contain links.
- Enforcing process timeouts and per-stream output limits.
- Terminating child processes on timeout and output overflow.
- Terminating Windows background descendants when the configured command exits, through Job Object close semantics.
- Exercising POSIX descendant cleanup, including descendants that create a new session.
- Exercising concurrent and partial logger writes.

CI runs PHP compatibility tests on Linux and process/filesystem tests on Windows across PHP 8.0–8.5. These jobs provide repeatable dynamic regression evidence; results must be checked on the pull request before the changes are considered validated.

## Residual risks and recommendations

1. Run PHPMake only with trusted build definitions. For untrusted contributions, execute in a disposable VM/container with restricted credentials, filesystem mounts, and network access.
2. Apply network egress restrictions at the OS/container/CI runner layer. PHPMake does not implement a network sandbox.
3. Keep POSIX process-tree cleanup documented as best-effort until a stronger OS-native containment mechanism is available.
4. Treat archives as untrusted at extraction boundaries. Use an extractor that rejects path traversal and symlinks and enforces decompressed-size/file-count limits.
5. Perform a third-party penetration test if PHPMake is deployed to process build definitions from less-trusted users.

## Validation status

The Windows Job Object changes are covered by automated regression tests and Windows CI jobs. A successful CI run is required before claiming the dynamic validation is complete. No external penetration test is claimed by this review.
