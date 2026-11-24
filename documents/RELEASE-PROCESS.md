# Release Process

1. Start from reviewed, up-to-date `main`.
2. Select an unused stable SemVer version.
3. Set `VERSION.txt` and `PhpMake\\Config::TOOL_VERSION` to the same value.
4. Move completed Unreleased notes into a dated changelog section.
5. Review migration notes, compatibility, security policy, examples, and the [release checklist](RELEASE-CHECKLIST.txt).
6. Merge the candidate and wait for the main-branch workflow to pass on that exact commit.

Create an annotated tag on the validated commit:

```sh
VERSION=2.1.0
git switch main
git pull --ff-only
git tag -a "$VERSION" -m "PHPMake $VERSION"
git push origin "$VERSION"
```

The tag workflow validates the tag, source version, changelog, build, coverage, Linux PHP 8.0–8.5, and Windows PHP 8.0–8.5 jobs. It then creates a GitHub Release with source archives, SHA-256 checksums, a manifest, and changelog notes.

The workflow does not bump versions, create tags, or publish to Packagist. Verify Packagist separately after release. Never move or replace a published version tag; issue a new patch release to correct a defect.
