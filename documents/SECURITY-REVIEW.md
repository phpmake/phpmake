# Security Review

**Scope:** source-level review of configuration validation, filesystem tasks, process execution, ZIP creation, and logging. This is not an external penetration test or security certification.

## Security model

A build file is trusted executable input. The `exec` task launches arbitrary programs with PHPMake's permissions and inherited environment. PHPMake is not a sandbox and does not isolate credentials or restrict network access.

## Controls

- Filesystem paths are checked against the working directory; symlink/junction escapes and destructive project-root targets are rejected.
- Copy and archive output use staged/atomic publication where supported.
- ZIP creation rejects links and enforces file-count, entry-count, depth, per-file, and total-size limits.
- Commands use argument vectors, bounded stdout/stderr, and timeouts.
- Windows cleanup uses Job Objects; Linux uses process groups and documented fallbacks.
- Logger writes are serialized and rotated; local log/lock paths are checked for unsafe filesystem entries.
- CI runs dependency audit and cross-platform regression tests.

## Limitations

Filesystem races cannot be eliminated completely in user space. POSIX process cleanup has a narrow fork/exit race; without process-group support, only the direct process can be terminated. Detached descendants may escape cleanup. Child output and exception messages may contain secrets. ZIP extraction safety belongs to downstream consumers.

## Operational guidance

Use trusted build files, least-privilege accounts, disposable CI runners, restricted secrets, and infrastructure-level network controls. Review logs before retaining them. Use an extractor that rejects traversal and links and enforces decompression limits.

## Validation

The PHPUnit and CI suites cover filesystem boundaries, publication failures, process limits/cleanup, archive limits, and logger safety. Review the successful workflow for the exact commit before claiming validation. No third-party penetration test is claimed.
