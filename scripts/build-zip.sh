#!/usr/bin/env bash
# Builds amboss-payments.zip from a git ref. Fails when the plugin version does not match the tag.
set -euo pipefail

tag="${1:?usage: scripts/build-zip.sh vX.Y.Z [git-ref]}"
ref="${2:-$tag}"
version="${tag#v}"

main_file="$(git show "$ref:amboss-payments.php")"
header_version="$(sed -n 's/^ \* Version: *//p' <<<"$main_file")"
constant_version="$(sed -n "s/^define( 'AMBOSS_VERSION', '\([^']*\)' );.*/\1/p" <<<"$main_file")"

if [[ "$header_version" != "$version" || "$constant_version" != "$version" ]]; then
  echo "Version mismatch: tag $version, plugin header $header_version, AMBOSS_VERSION $constant_version" >&2
  exit 1
fi

# The folder name must be the plugin slug, so WordPress updates the plugin in place.
git archive --format=zip --prefix=amboss-payments/ -o amboss-payments.zip "$ref" -- \
  . ':!tests' ':!scripts' ':!.github' ':!.gitignore' ':!CHANGELOG.md' ':!release-please-config.json' ':!.release-please-manifest.json' ':!README.md' ':!.wordpress-org'
echo "Built amboss-payments.zip for $tag"
