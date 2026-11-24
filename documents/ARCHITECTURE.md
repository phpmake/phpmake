# Architecture

## Execution flow

```text
CLI
 └─ BuildLoader / BuildValidator
     └─ BuildExecutor
         └─ DependencyResolver
             └─ TaskExecutor
                 └─ TaskFactory / TaskDefinitionRegistry
                     └─ Built-in task
```

`TaskDefinitionRegistry` defines task identifiers and parameter contracts. `BuildSchemaParityTest` checks the published JSON Schema against the registry.

## Main boundaries

- **Build:** `BuildConfiguration`, `BuildLoader`, `BuildValidator`, `BuildExecutor`.
- **Tasks:** `TaskFactory`, `TaskExecutor`, and built-in task classes.
- **Filesystem:** `FilesystemTree`, `FilesystemIdentity`, and atomic publishers.
- **Processes:** `CommandLineParser`, platform launchers, and `ProcessSupervisor`.
- **CLI/logging:** `ConsoleOutput` separates stdout/stderr; `Logger` handles persistent logs and rotation.

## Constraints

PHP `^8.0`; Windows and GNU/Linux; no third-party runtime dependencies. Filesystem tasks stay inside the working directory. Child processes are launched without implicit shell interpretation. Process cleanup is best-effort and is not a sandbox.

For changes to these boundaries, run the checks in [Contributing](CONTRIBUTING.txt). Verify CI for the exact commit; this document is not evidence of a passing run.
