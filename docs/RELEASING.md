# Releasing and signing keys

> **Different from Kaleta.** Kaleta sites download and install signed packages from the administration. In Talea that
> updater is switched off (`Core\Updater::ENABLED = false`, HF-12): a release is a container image plus a signed release feed
> (HF-13, "A regular release" below), and a site only shows a notice. The signing keys and tooling come from Kaleta and sign
> the feed.

## Two signing keys

| key | private file | where it lives | purpose |
| --- | --- | --- | --- |
| **operating** | `tools/keys/publisher.key` | the publisher's computer + an encrypted backup | signs every release |
| **backup** | `tools/keys/backup.key` | **offline only** – password manager and a second copy on paper or USB | unused; replaces the operating key |

The public counterparts are in `system/update.pub` (one per line, an optional description after the key, `#` lines are notes).
A signature is valid when it matches **any** of them (`Core\Signature`). The file ships in the package, so every update replaces
it: new keys reach the installations and revoked keys disappear from them.

What is signed: the string `version|sha256 of the package|regular or security` and, separately, the list of core files. The
security flag is therefore covered by the signature: whoever controls only the website with the manifest cannot declare a
regular release a security release and force its automatic installation.

Private keys **never** belong in git (`.gitignore` guards it), in a package or in cloud sync. If the project folder lives in a
synced folder (iCloud Drive, Dropbox), `tools/keys/` is synced too: move the keys elsewhere and leave a symlink in `tools/keys/`,
or pass the key in the environment variable `TALEA_KEY`.

## Creating the backup key (once, before the first public release)

```bash
php tools/release.php --new-key=backup
```

1. Put `tools/keys/backup.key` into a password manager and keep a second copy off the computer. Then delete it from the disk.
2. Commit `system/update.pub` (a line was added). Installations recognise the backup key from the first release that contains it,
   so do this **before** the first public release.

## A regular release

Everything that goes to GitHub and into the installations is English: commit, tag, release notes and the change description.

0. Run `composer test` and `composer test:browser` (PHPUnit, including the English installation and the walk in Chrome).
1. Raise `TALEA_VERSION` in `system/bootstrap.php` on `main`, push, and wait for CI. Then tag that commit `vX.Y.Z` and push the tag
   (`git tag vX.Y.Z && git push origin vX.Y.Z`). A tag that does not match `TALEA_VERSION` fails the release.
2. The release workflow (`.github/workflows/release.yml`) runs the tests again only when CI has not passed for the tagged commit,
   builds and pushes the multi-arch image (amd64, arm64) to GHCR with the tags below, signs it with cosign and creates a draft
   release whose text carries the digest and the feed command (also in the job summary).
3. Sign the release feed locally: `php tools/release.php X.Y.Z --feed --image=ghcr.io/<owner>/talea:X.Y.Z --digest=sha256:<digest> --change="…" [--security]`.
   Upload `dist/update.json` to the draft release as the asset `update.json` and publish the release as **latest**; sites read
   `.../releases/latest/download/update.json` (`TALEA_UPDATE_FEED`, docs/DEPLOYMENT.md).

A pre-release is the same with a tag `vX.Y.Z-rc.N` (and `TALEA_VERSION = 'X.Y.Z-rc.N'`): it gets only its own image tag and a draft
marked as pre-release, which GitHub never serves as `latest`, so the feed of the sites stays where it is.

## Image tags

| tag | moves on | from |
| --- | --- | --- |
| `X.Y.Z`, `X.Y.Z-rc.N` | never | the release tag |
| `X.Y` | the newest stable release of that minor line | a stable release tag |
| `X` (not for 0.x) | the newest stable release of that major line | a stable release tag |
| `latest` | the newest stable release | a stable release tag |
| `edge`, `sha-<commit>` | every push to `main` that passed CI | `main` |

A patch of an older line (`3.6.2` after `3.7.0`) moves `3.6` but leaves `3` and `latest` alone; `tools/image-tags.sh` decides
(tested in `tests/Unit/ImageTagsTest.php`). `edge` is for a staging site, never for production. Every image is signed and carries
provenance and an SBOM (docs/DEPLOYMENT.md, "Verify an image").

## Build cache

The images build on the self-hosted runners, which share one host. Two caches keep a release to the changed layers:

- **The builder `talea-release`** (a BuildKit container on the runner host) is created by the first run and kept afterwards. Its
  state holds every layer of both architectures and the apt and Composer cache mounts; BuildKit's own garbage collection keeps it
  within its default share of the disk. The arm64 half runs under QEMU, so the PHP extensions are the slow step: they are cached
  in the base stage, and the Composer stage runs natively once for both architectures (`--platform=$BUILDPLATFORM`).
- **The registry cache `ghcr.io/<owner>/talea-buildcache:main`** (every layer, `mode=max`) refills a builder that starts empty: a
  new host, or after `docker buildx rm talea-release`.

To give the builder a newer BuildKit, remove it on the host with `docker buildx rm --keep-state talea-release`; the next run creates
it again with its state. `docker buildx du --builder talea-release` shows its size, `docker buildx prune --builder talea-release`
empties it.
4. Package mode (`php tools/release.php X.Y.Z --url=… --change=…`) still builds a signed zip for the switched-off in-app updater; it is not part of a release.

Use `--security` only for real security fixes. Sign **locally**, never in CI: anyone who may change a workflow could sign
otherwise, and the security of every installation would rest on one account. CI builds and tests; the signature is one
command on the publisher's computer.

## Replacing the operating key on a schedule

1. Move the old `tools/keys/publisher.key` to an archive (do not delete it before the replacement is done).
2. `php tools/release.php --new-key=operating` adds a new line to `system/update.pub`. Keep the old line for now.
3. Release a version signed with the **old** key (put it back temporarily or use `TALEA_KEY`). It brings the new key to the installations.
4. In the next release, already signed with the new key, remove the old line from `system/update.pub`.

## Losing the operating key

1. Fetch the backup key and save it as `tools/keys/backup.key`.
2. `php tools/release.php --new-key=operating`, and remove the lost key from `system/update.pub`.
3. `php tools/release.php X.Y.Z --key=backup --url=…`: a release signed with the backup key brings the new operating key.
4. Put the backup key back offline. The next release is signed with the new operating key.

## A leaked operating key (or just a suspicion)

Same procedure as for a loss, but **immediately**, and mark the release `--security` so it installs by itself. Until the
installations accept the update they still trust the compromised key; an attacker would additionally need to control the
release channel. Change the access to the hosting and to GitHub at the same time and inform the users.

If the **backup** key leaks, create a new one (`--new-key=backup` after moving the old file), remove the old line and release a
version signed with the operating key.

## Losing both keys

There is no automatic path then. Installations can be updated by hand (upload the files), and the first hand-uploaded version
brings the new `system/update.pub`. Keep the backup key in two independent places.

## From a finding to a patch

1. **Assess** (within 3 working days, see `SECURITY.md`): is it a real vulnerability, who is affected, can it be exploited
   without signing in? Close a false finding with a reason so it does not come back.
2. **Do not handle it in public.** Open a private draft advisory (*Security → Advisories → New draft*); the fix is made in the
   private branch GitHub offers. Reports from people arrive the same way (*Report a vulnerability*).
3. **Fix and test**: `composer test`, and add a test that would have caught it.
4. **Release the patch** from the maintained line (`1.0.x`); with `--security` when it is a security fix.
5. **Verify** on the demo that the patch installed itself.
6. **Publish the advisory** with a description, the affected versions and credit to the finder.

Fixes are made on `main` and cherry-picked to the maintenance branch from which patch versions are released; new features go to
`main` only.
