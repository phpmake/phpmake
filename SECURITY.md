# Security Policy

## Supported versions

| Version | Security fixes |
|---|---|
| 2.x | Supported |

Report issues against the latest available 2.x release or current `main`.

## Security model

PHPMake is a local build automation tool, not a sandbox. A `build.json` file is executable input: its `exec` task can launch arbitrary programs with the permissions and inherited environment of the PHPMake process.

Built-in filesystem tasks enforce project-directory path boundaries and reject symlink/junction escapes. Those checks do not constrain commands started by `exec`. PHPMake does not isolate credentials, restrict network egress, enforce DNS policies, or provide OS-level process containment.

## Deployment guidance

- Execute only build configurations from trusted sources.
- Run builds as a least-privilege user.
- Do not expose deployment credentials or secrets to untrusted pull requests.
- Use disposable, isolated CI runners or containers for third-party build logic.
- Apply filesystem and outbound-network restrictions at the runner/container/host layer.
- Treat process-tree cleanup as best-effort; detached descendants may escape cleanup.
- Treat generated ZIP files as untrusted at extraction boundaries. The extracting application must enforce path, link, and decompressed-size limits.
- Review build output before retaining logs; child-process output and exception messages may contain secrets. Use `--no-log` when persistent logs are not appropriate.

See [the security review](documents/SECURITY-REVIEW.md) for implementation details and residual limitations.

## Report a vulnerability

Email **phpmake.github@gmail.com** with:
- A concise description and affected version/commit.
- Security impact and attack prerequisites.
- Reproduction steps or a proof of concept.
- Any suggested mitigation.

Do not publish exploit details or open a public issue before maintainers have had an opportunity to investigate and coordinate disclosure. Avoid sending real credentials or sensitive production data.
