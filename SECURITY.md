# Security Policy

## Supported versions

The latest 2.x release and current `main` receive security fixes.

## Security model

PHPMake is a build runner, not a sandbox. A build file is executable input: `exec` launches arbitrary programs with the permissions and environment of the PHPMake process.

Built-in filesystem tasks enforce working-directory boundaries and reject symlink/junction escapes. These controls do not restrict child-process filesystem or network access, isolate credentials, or provide OS-level containment.

## Deployment

- Run only trusted build files.
- Use least-privilege, disposable CI runners for untrusted contributions.
- Do not expose deployment secrets to untrusted builds.
- Apply filesystem and network restrictions outside PHPMake.
- Treat process-tree cleanup as best-effort.
- Validate ZIP files at extraction time; extraction safety belongs to the consumer.
- Review logs before retaining them. Child output may contain secrets; use `--no-log` when appropriate.

See [the security review](documents/SECURITY-REVIEW.md) for implementation details.

## Report a vulnerability

Email **phpmake.github@gmail.com** with the affected version/commit, impact, prerequisites, reproduction steps, and suggested mitigation.

Do not publish exploit details or open a public issue before maintainers can investigate. Do not include real credentials or production data.
