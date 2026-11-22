# PHPMake Release Process

This document describes the release procedure for maintainers. The release workflow
publishes a GitHub Release only after it validates the exact tagged commit.

## Before selecting a version

1. Start from an up-to-date `main` branch and create a reviewed release candidate.
2. Choose a stable SemVer version that is not already present as a Git tag or on
   Packagist. Do not assume the current source version is available for publication.
3. Update `VERSION.txt` and `PhpMake\\Config::TOOL_VERSION` to the same version.
4. Move the completed entries from the `Unreleased` section in
   `documents/CHANGELOG.txt` into a new `PHPMake VERSION - YYYY-MM-DD` section.
5. Review README examples, migration notes, API compatibility, security policy,
   and the release checklist.
6. Run the complete validation gates in
   [RELEASE-CHECKLIST.txt](RELEASE-CHECKLIST.txt). Merge the candidate into `main`.
   Wait for the push-triggered `Build and Test` run for that exact `main` commit
   to complete successfully before pushing its release tag.

## Publish

Create an annotated tag on the reviewed `main` commit. Replace the example version
with the version selected above:

```sh
VERSION=2.1.0
git switch main
git pull --ff-only
git tag -a "$VERSION" -m "PHPMake $VERSION"
git push origin "$VERSION"
```

The `.github/workflows/release.yml` workflow runs when a stable SemVer tag is pushed.
It refuses to publish unless all of the following are true:

- The tag is an annotated tag and points to a commit reachable from `main`.
- The tag, `VERSION.txt`, and `PhpMake\\Config::TOOL_VERSION` match exactly.
- The changelog contains a non-empty section for that version.
- The exact commit has a successful `Build and Test` workflow run on `main`.
- Composer metadata validation, the complete PHPUnit suite, and Composer security
  audit pass again on the tagged source.

On success, the workflow creates a GitHub Release with:

- A source `.tar.gz` archive and a source `.zip` archive generated from the tag.
- `SHA256SUMS` checksums for both archives.
- `RELEASE-MANIFEST.txt` containing the package, version, commit, tag, and workflow URL.
- Release notes extracted from the matching changelog section.

The workflow uses the repository-provided `GITHUB_TOKEN`; no long-lived publishing
token is required for GitHub Releases. It does not create tags, change source versions,
or publish to Packagist on its own.

## After publication

1. Verify the GitHub Release assets and compare their checksums with `SHA256SUMS`.
2. Verify Packagist lists the selected version and that a fresh consumer can install it.
   Packagist synchronization is external to the release workflow; investigate its
   repository webhook or trigger a package update through the Packagist account if
   the version does not appear.
3. Verify the README version badge and the installed `php vendor/bin/phpmake.php --version`
   output agree with the release.
4. Retain the release workflow URL, commit SHA, CI run, coverage artifact, audit result,
   and consumer-install evidence.

## Rollback

Do not move or overwrite an already-published version tag. If a release is defective,
publish a corrected patch version after following the complete release procedure.
