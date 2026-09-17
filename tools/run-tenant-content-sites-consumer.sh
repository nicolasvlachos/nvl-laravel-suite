#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
fixture_root="$repository_root/tools/fixtures/tenant-content-sites-consumer"
consumer_root="$(mktemp -d "${TMPDIR:-/tmp}/nvl-tenant-content-sites.XXXXXX")"
artifact_version="${NVL_CANDIDATE_VERSION:-1.99.0}"
candidate_archive="${NVL_CANDIDATE_ARCHIVE:-}"
trap 'rm -rf "$consumer_root"' EXIT

mkdir -p "$consumer_root/archives" "$consumer_root/artifact"
if [[ -n "$candidate_archive" ]]; then
    cp "$candidate_archive" "$consumer_root/archives/"
else
    (cd "$repository_root" && COMPOSER_ROOT_VERSION="$artifact_version" composer archive --format=zip --dir="$consumer_root/archives")
fi
archive="$(find "$consumer_root/archives" -maxdepth 1 -name 'nvl-laravel-suite-*.zip' -print -quit)"
test -n "$archive"
unzip -q "$archive" -d "$consumer_root/artifact"
composer create-project --no-interaction --no-dev --prefer-dist 'laravel/laravel:^13.0' "$consumer_root/app"
cd "$consumer_root/app"
repository_config="$(jq -nc --arg url "$consumer_root/artifact" --arg version "$artifact_version" '{type:"path",url:$url,options:{symlink:false,versions:{"nvl/laravel-suite":$version}}}')"
composer config repositories.nvl-tenant-content-sites "$repository_config"
composer require --no-interaction --update-no-dev --with-all-dependencies "nvl/laravel-suite:$artifact_version"
cp -R "$fixture_root/app/." app/
cp -R "$fixture_root/config/." config/
cp "$fixture_root/bootstrap/providers.php" bootstrap/providers.php
cp .env.example .env
touch database/database.sqlite
composer dump-autoload --no-dev --no-interaction
php artisan key:generate --force
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan down --retry=60
php artisan tenant-content-sites:proof
php artisan up
php artisan config:cache
php artisan route:cache
composer audit --locked --no-interaction
