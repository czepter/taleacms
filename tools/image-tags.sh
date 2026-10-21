#!/usr/bin/env bash
# The container image tags of a release (docs/RELEASING.md, "Image tags"); run by .github/workflows/release.yml.
#   git tag -l 'v*' | tools/image-tags.sh 3.8.0      prints one tag per line: 3.8.0, then 3.8, 3 and latest where they apply
# X.Y.Z always. A stable release also moves X.Y, X (not for 0.x) and latest, each only while it is the newest stable release of that
# line: a patch of an older line (3.6.2 after 3.7.0) keeps 3.6 but leaves 3 and latest where they are. A pre-release (3.8.0-rc.1) gets
# its own tag only. The existing tags come on stdin; pre-release tags among them are ignored.
set -euo pipefail
version=${1#v}
[[ $version =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)(-[0-9A-Za-z.-]+)?$ ]] || { echo "not a version: $1" >&2; exit 1; }
major=${BASH_REMATCH[1]}
minor=${BASH_REMATCH[2]}
echo "$version"
[ -z "${BASH_REMATCH[4]}" ] || exit 0
stable=$({ sed 's/^v//' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' || true; echo "$version"; } | sort -uV)
newest() { [ "$(grep -E "^$1" <<<"$stable" | tail -n1)" = "$version" ]; }
if newest "$major\.$minor\."; then echo "$major.$minor"; fi
if [ "$major" != 0 ] && newest "$major\."; then echo "$major"; fi
if newest ''; then echo latest; fi
