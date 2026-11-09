#!/bin/sh
# Builds the distributable Pro plugin from the committed HEAD into $1 (default: ../corinthian-pro-build).
set -eu
src=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-"$src/../corinthian-pro-build"}
mkdir -p "$out"
[ -e "$out/corinthian-pro" ] && { echo "Refusing to overwrite $out/corinthian-pro; pass an empty folder." >&2; exit 1; }
mkdir "$out/corinthian-pro"
git -C "$src" archive HEAD | tar -x -C "$out/corinthian-pro"
cd "$out/corinthian-pro"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
( cd "$src" && npm run build --silent >/dev/null )
mkdir -p assets && cp -R "$src/assets/build" assets/build
mkdir "$out/.dev-files"
for entry in tests bin .wp-env.json phpunit.xml.dist phpunit-multisite.xml.dist phpstan.neon.dist package.json package-lock.json composer.lock assets/src; do
	[ -e "$entry" ] && mv "$entry" "$out/.dev-files/$(echo "$entry" | tr / _)"
done
( cd "$out" && zip -qr corinthian-pro.zip corinthian-pro )
echo "$out/corinthian-pro.zip"
