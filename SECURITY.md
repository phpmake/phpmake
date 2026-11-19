# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 2.x.x   | :white_check_mark: |

## Security Model and Deployment

A `build.json` file is executable input. The `exec` task can launch arbitrary local programs with the permissions and environment of the PHPMake process. PHPMake does not sandbox child processes and does not enforce network egress, DNS/hostname allowlists, or API rate limits.

Only execute build configurations from trusted sources. In CI/CD:
- Run PHPMake with a least-privilege account.
- Do not expose deployment credentials or other secrets to builds from untrusted pull requests.
- Use isolated, disposable runners or containers when executing untrusted or third-party build logic.
- Apply outbound network restrictions at the runner, container, or infrastructure boundary when required.

Built-in filesystem tasks enforce project-working-directory containment, but that restriction does not constrain commands launched by `exec`. Process-tree cleanup is best-effort, not a security boundary: POSIX descendants can escape by creating a new session, and Windows cleanup cannot guarantee termination of detached descendants. Do not rely on PHPMake to contain hostile processes.

PHPMake has no Forgejo API integration; Forgejo server-version compatibility is not applicable.

## Reporting a Vulnerability

If you discover a potential vulnerability, email <phpmake.github@gmail.com> with a description, impact, and reproduction steps.

Please do not disclose the issue publicly until the maintainers have investigated it and coordinated a fix or disclosure timeline.
