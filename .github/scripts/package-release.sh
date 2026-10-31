#!/usr/bin/env bash
set -euo pipefail

# Package committed application inputs only, never files from a local installation.
version="${1:-dev}"
if [[ "$version" != dev && ! "$version" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo 'Usage: bash .github/scripts/package-release.sh [dev|vMAJOR.MINOR.PATCH]' >&2
    exit 1
fi

cd "$(git rev-parse --show-toplevel)"
mkdir -p dist
archive="organilog-${version}.zip"
git archive --format=zip --prefix=organilog/ --output="dist/$archive" HEAD -- \
    .htaccess config.example.php \
    src public data \
    bin/setup.php bin/keygen.php bin/router.php \
    deploy/nginx.conf \
    README.md README.fr.md docs LICENSE NOTICE SECURITY.md CODE_OF_CONDUCT.md CONTRIBUTING.md

unzip -t "dist/$archive"
(
    cd dist
    sha256sum "$archive" > "$archive.sha256"
)
