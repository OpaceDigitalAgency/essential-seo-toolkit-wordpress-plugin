#!/usr/bin/env bash
set -euo pipefail

version="2.0.0"
slug="opace-essential-seo-toolkit"
archive="release/${slug}-${version}.zip"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/$slug" release
rsync -a ./ "$stage/$slug/" \
  --exclude '.git/' --exclude '.github/' --exclude '.wordpress-org/' \
  --exclude '.cache/' --exclude 'node_modules/' \
  --exclude 'vendor/' --exclude 'release/' --exclude 'reports/' \
  --exclude 'scripts/' --exclude 'tests/' --exclude '.gitignore' \
  --exclude '.wp-env.json' --exclude 'composer.json' --exclude 'composer.lock' \
  --exclude 'package.json' --exclude 'package-lock.json' \
  --exclude 'phpunit.xml.dist' --exclude '.phpunit.result.cache' \
  --exclude 'CONTRIBUTING.md' \
  --exclude 'README.md' --exclude 'SECURITY.md'
rm -f "$archive"
(cd "$stage" && zip -X -q -r "$OLDPWD/$archive" "$slug")
echo "Built $archive"
