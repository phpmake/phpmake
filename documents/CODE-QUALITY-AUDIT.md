# Code Quality Audit

**Repository:** PHPMake  
**Baseline reviewed:** \`main\` at \`db412b6a1d182fa8dce815d5b253a4bac7bb3edb\`  
**Scope:** application source, CLI entry point, Composer configuration, static-analysis configuration, and representative unit tests.  
**Compatibility constraint:** PHP 8.0–8.5; Windows and Linux.

## Executive summary

The project has clear high-level layers (build loading/validation, dependency resolution, task creation/execution, task implementations, and logging). The main quality risks are duplicated task metadata, repeated filesystem transaction code, oversized task implementations, mixed output/error responsibilities, and weakly typed array-based configuration boundaries.

No broad rewrite is justified. Filesystem and process-management code is security-sensitive; it should be decomposed behind tested contracts in small steps.

## Findings

| Priority | Area | Finding | Recommended action |
|---|---|---|---|
| High | Task metadata | Supported task types and their parameter rules are independently declared in \`BuildValidator\` and \`TaskFactory\`. The JSON schema is a further source of truth. These can drift. | Introduce a PHP 8.0-compatible task-definition registry and parity tests against the JSON schema. Keep task construction and validation separate from task implementations. |
| High | Filesystem tasks | \`CopyTask\` and \`ZipTask\` each implement filesystem identity checks and similar temporary-file/backup/rename/rollback flows. | Extract a narrowly scoped filesystem publication/identity utility, then test rollback and cleanup failures before migrating callers. |
| High | Process execution | \`ExecTask\` owns command parsing, platform-specific launchers, process discovery, signal handling, output capture, timeouts, and termination. | Split command parsing, process supervision, and platform adapters. Preserve argv-based execution and existing process-tree guarantees. |
| Medium | Configuration loading | \`BuildLoader\` performs a JSON object-shape pass, decodes the same document again into arrays, then invokes \`BuildValidator\`. The first pass is needed to distinguish JSON objects from arrays, but the conversion boundary is implicit. | Keep object/array distinction explicit and convert once into a documented internal representation. Add parity tests for empty objects, empty arrays, numeric target names, and malformed nested values. |
| Medium | CLI parsing | \`CliParser::parse()\` keeps a known-flag list, an action map, and a separate switch statement. Adding a flag requires edits in multiple places. | Use one declarative option table containing aliases and effects; retain current conflict semantics. |
| Medium | Error handling | Several boundaries use generic \`Exception\`; \`BaseTask::execute()\` catches every \`Throwable\` and reduces it to a boolean plus a message. This loses exception category and diagnostic context. | Use specific exception types for invalid configuration, missing targets, and runtime failures. Preserve user-facing concise output while making debug diagnostics retain exception class and trace. |
| Medium | Type safety | Build configuration and task parameters cross multiple layers as unshaped arrays. PHPStan is configured at level 1 and most public array contracts lack array-shape/generic PHPDoc. | Add PHPDoc array shapes and list annotations incrementally; raise PHPStan level after each subsystem is clean. Avoid introducing PHP 8.1+ syntax. |
| Low | Naming/documentation | Some classes retain verbose legacy class-level templates while newer classes use concise descriptions. CLI result keys use mixed naming conventions such as \`showVersion\` alongside lower-camel keys. | Standardize terminology and result-key naming in a separately tested compatibility change. |
| Low | Dead code | No definite unused production method was established from source inspection alone. Several task methods accept the common \`debug\`/\`silent\` contract while using the injected logger instead; this is interface consistency, not dead code. | Confirm dead code with static-analysis/unused-symbol tooling before deleting anything. |

## Architecture and SOLID observations

- **Single Responsibility:** \`ExecTask\` is the clearest SRP pressure point. \`BuildLoader\` also combines bounded file I/O, JSON shape checks, validation orchestration, and object construction.
- **Open/Closed:** adding a task currently requires coordinated edits to the validator, factory, schema, and tests.
- **Dependency Inversion:** orchestration classes depend on concrete collaborators (\`TaskFactory\`, \`TaskExecutor\`, \`DependencyResolver\`). Interfaces may help if alternative implementations are genuinely needed; adding interfaces solely for abstraction would add noise.
- **Separation of concerns:** \`TaskExecutor\` writes directly to global STDOUT/STDERR while task implementations write through \`Logger\`. Output policy is therefore split across layers.
- **State management:** dependency traversal state was stored on the resolver instance even though it is only needed during one resolution call. This has been changed to invocation-local state.

## Changes made in this audit branch

1. \`DependencyResolver\` now keeps traversal state local to \`resolve()\` and passes it through the DFS. This removes mutable per-instance state and makes each resolution invocation self-contained.
2. \`BuildExecutor\` now throws \`OutOfBoundsException\` when the requested target does not exist.
3. \`TaskFactory\` now throws \`InvalidArgumentException\` for an unknown task type, identifying it as invalid build input rather than an unspecified failure.

These are deliberately small behavior-preserving changes except for the more precise exception classes (both remain subclasses of \`Exception\`).

## Validation still required

- Run the complete PHPUnit suite on PHP 8.0–8.5, Linux and Windows.
- Run PHP-CS-Fixer, PHPLint, PHPStan, Rector dry-run, and Composer dependency checks.
- Add focused tests for resolver reuse and the new exception contracts.
- Do not merge this branch until all matrix checks pass.

## Suggested follow-up sequence

1. Centralize task definitions and enforce parity with \`schema/build-schema.json\`.
2. Extract shared filesystem identity/publication primitives with failure-injection tests.
3. Decompose \`ExecTask\` into parser, supervisor, and OS-specific process-control components.
4. Introduce typed configuration DTO boundaries and raise PHPStan gradually.
5. Consolidate CLI/output/error reporting and remove only dead code confirmed by tooling.
