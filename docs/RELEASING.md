# Releasing

A release is a container image. Sites are deployed and updated by pulling a new image (docs/DEPLOYMENT.md); nothing is signed
by hand, no package is uploaded anywhere and a site never updates itself or asks for new versions.

## A release

Everything that goes to GitHub is English: commit, tag and release notes.

1. Raise `TALEA_VERSION` in `system/bootstrap.php` on `main`, push, and wait for CI. A commit that changes only that line counts
   as tested when its parent passed CI, so no test job runs again, neither in CI nor for the tag.
2. Wait until the Release run of that push (the edge image) is done, then tag the commit and push the tag:
   `git tag vX.Y.Z && git push origin vX.Y.Z`. A tag that does not match `TALEA_VERSION` fails the release. The tag run builds
   nothing when the commit has its edge image (`sha-<commit>`): it puts the release tags on that image and signs it. A tag pushed
   earlier, or on a commit outside `main`, builds the image itself.
3. The release workflow (`.github/workflows/release.yml`) runs the tests again only when CI has not passed for the tagged commit,
   builds the multi-arch image (amd64, arm64) unless it reuses the edge image, pushes it to GHCR with the tags below, signs it with cosign and publishes the GitHub
   release with generated notes, the `docker pull` line and the digest. Each architecture builds natively: amd64 on the self-hosted
   runners, arm64 on a GitHub-hosted `ubuntu-24.04-arm` runner (free while the repository is public); the job `image` joins both
   digests under one index with the tags and signs that.

A pre-release is the same with a tag `vX.Y.Z-rc.N` (and `TALEA_VERSION = 'X.Y.Z-rc.N'`): it gets only its own image tag and a GitHub
release marked as pre-release.

A security fix is an ordinary patch release; say so at the top of its release notes.

## Image tags

| tag | moves on | from |
| --- | --- | --- |
| `X.Y.Z`, `X.Y.Z-rc.N` | never | the release tag |
| `X.Y` | the newest stable release of that minor line | a stable release tag |
| `X` (not for 0.x) | the newest stable release of that major line | a stable release tag |
| `latest` | the newest stable release | a stable release tag |
| `edge`, `sha-<commit>` | every push to `main` that passed CI | `main` |

A patch of an older line (`3.6.2` after `3.7.0`) moves `3.6` but leaves `3` and `latest` alone, and GitHub's "latest release" with
them; `tools/image-tags.sh` decides (tested in `tests/Unit/ImageTagsTest.php`). `edge` is for a staging site, never for production.
Every image is signed with cosign (keyless: no key to keep) and carries provenance and an SBOM (docs/DEPLOYMENT.md, "Verify an image").

## Build cache

The amd64 image builds on the self-hosted runners, which share one host; the arm64 image builds on a GitHub-hosted runner. Two
caches keep a release to the changed layers:

- **The builder `talea-release`** (a BuildKit container on the runner host, amd64 only) is created by the first run and kept
  afterwards. Its state holds every layer and the apt and Composer cache mounts; BuildKit's own garbage collection keeps it within
  its default share of the disk.
- **The registry cache `ghcr.io/<owner>/talea-buildcache:main-<arch>`** (every layer, `mode=max`) fills a builder that starts empty:
  the arm64 builder on every run, the amd64 one on a new host or after `docker buildx rm talea-release`.

Trust: the builder's state lives on the host that also runs CI. CI builds with the Docker daemon's own builder, not this one, and
its PHP containers get no Docker socket; pull requests from forks never run on the self-hosted runners. The arm64 build runs on a
GitHub-hosted runner, a fresh machine per job. Whoever can change a workflow in this repository could still reach the host, and
with it the builder, but could change `release.yml` just as well. If people outside that circle ever get CI on these runners, give
the release jobs their own runner group, or set `builder` of amd64 in the build matrix to `''` (a fresh builder per run, only the
registry cache, which only this workflow can write).

To give the builder a newer BuildKit, remove it on the host with `docker buildx rm --keep-state talea-release`; the next run creates
it again with its state. `docker buildx du --builder talea-release` shows its size, `docker buildx prune --builder talea-release`
empties it.

## From a finding to a patch

1. **Assess** (within 3 working days, see `SECURITY.md`): is it a real vulnerability, who is affected, can it be exploited
   without signing in? Close a false finding with a reason so it does not come back.
2. **Do not handle it in public.** Open a private draft advisory (*Security → Advisories → New draft*); the fix is made in the
   private branch GitHub offers. Reports from people arrive the same way (*Report a vulnerability*).
3. **Fix and test**: `composer test`, and add a test that would have caught it.
4. **Release the patch** from the maintained line (`1.0.x`) as above.
5. **Verify** that the demo runs the new image (`docker compose pull && docker compose up -d`, then the version in the admin).
6. **Publish the advisory** with a description, the affected versions, the fixed image tag and credit to the finder.

Fixes are made on `main` and cherry-picked to the maintenance branch from which patch versions are released; new features go to
`main` only.
