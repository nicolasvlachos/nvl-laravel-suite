#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
fixture_root="$repository_root/tools/fixtures/content-production-consumer"
consumer_workspace="$(mktemp -d "${TMPDIR:-/tmp}/nvl-content-consumer.XXXXXX")"
artifact_version="${NVL_CANDIDATE_VERSION:-1.99.0}"
candidate_archive="${NVL_CANDIDATE_ARCHIVE:-}"

cleanup() {
    rm -rf "$consumer_workspace"
}

trap cleanup EXIT

mkdir -p "$consumer_workspace/archives" "$consumer_workspace/artifact"

if [[ -n "$candidate_archive" ]]; then
    test -f "$candidate_archive"
    cp "$candidate_archive" "$consumer_workspace/archives/"
else
    (
        cd "$repository_root"
        COMPOSER_ROOT_VERSION="$artifact_version" composer archive \
            --format=zip \
            --dir="$consumer_workspace/archives"
    )
fi

archive="$(find "$consumer_workspace/archives" -maxdepth 1 -name 'nvl-laravel-suite-*.zip' -print -quit)"

if [[ -z "$archive" ]]; then
    echo 'The sealed NVL Suite archive was not created.' >&2
    exit 1
fi

unzip -q "$archive" -d "$consumer_workspace/artifact"

run_consumer_mode() {
    local migration_mode="$1"
    local package_migrations="$2"
    local consumer_root="$consumer_workspace/$migration_mode"

    composer create-project \
        --no-interaction \
        --prefer-dist \
        'laravel/laravel:^13.0' \
        "$consumer_root"

    cd "$consumer_root"

    local repository_config
    repository_config="$(jq -nc \
        --arg url "$consumer_workspace/artifact" \
        --arg version "$artifact_version" \
        '{"type":"path","url":$url,"options":{"symlink":false,"versions":{"nvl/laravel-suite":$version}}}')"
    composer config repositories.nvl-content-consumer "$repository_config"
    composer require \
        --no-interaction \
        --with-all-dependencies \
        --dry-run \
        "nvl/laravel-suite:$artifact_version"
    composer require \
        --no-interaction \
        --with-all-dependencies \
        "nvl/laravel-suite:$artifact_version"
    test ! -L vendor/nvl/laravel-suite

    cp -R "$fixture_root/app/." app/
    cp -R "$fixture_root/config/." config/
    cp -R "$fixture_root/database/." database/
    cp -R "$fixture_root/lang/." lang/
    cp "$fixture_root/bootstrap/providers.php" bootstrap/providers.php
    mkdir -p content-consumer-types
    cp -R "$fixture_root/typescript/." content-consumer-types/
    cp .env.example .env
    touch database/database.sqlite
    composer dump-autoload --no-interaction
    php artisan key:generate --force

    content_consumer_artisan() {
        APP_ENV=production \
        APP_DEBUG=false \
        APP_URL=https://content-consumer.test \
        CACHE_STORE=database \
        CACHE_LIMITER=database \
        CONTENT_CONSUMER_PACKAGE_MIGRATIONS="$package_migrations" \
        DB_CONNECTION=sqlite \
        DB_DATABASE="$consumer_root/database/database.sqlite" \
        DB_QUEUE_RETRY_AFTER=1200 \
        FILESYSTEM_DISK=local \
        MEDIA_FILESYSTEM_DISK=local \
        MEDIA_QUEUE_CONNECTION=database \
        QUEUE_CONNECTION=database \
        SESSION_DRIVER=database \
            php artisan "$@"
    }

    if [[ "$migration_mode" == 'application_owned' ]]; then
        content_consumer_artisan vendor:publish --tag=content-migrations --force
        content_consumer_artisan vendor:publish --tag=media-migrations --force
        content_consumer_artisan vendor:publish --tag=metafields-migrations --force
        content_consumer_artisan vendor:publish --tag=pages-migrations --force
        content_consumer_artisan vendor:publish --tag=seo-migrations --force
        content_consumer_artisan vendor:publish --tag=translations-migrations --force
    fi

    content_consumer_artisan config:clear
    content_consumer_artisan route:clear
    content_consumer_artisan config:cache
    content_consumer_artisan route:cache
    content_consumer_artisan migrate --force
    content_consumer_artisan nvl:content:definitions:sync --no-interaction
    content_consumer_artisan nvl:suite:skills:publish --format=json
    content_consumer_artisan nvl:data:types:generate
    content_consumer_artisan nvl:data:types:check
    content_consumer_artisan nvl:suite:doctor --strict --production --format=json
    content_consumer_artisan nvl:suite:consumer-audit --strict --format=json
    content_consumer_artisan nvl:media:owner-slots:prune
    content_consumer_artisan content-consumer:smoke --format=json

    local document_relative_path
    document_relative_path="$(tr -d '\n' < storage/app/content-consumer-document-path)"
    local document_absolute_path="$consumer_root/storage/app/private/$document_relative_path"
    local cover_relative_path
    cover_relative_path="$(tr -d '\n' < storage/app/content-consumer-cover-path)"
    local cover_absolute_path="$consumer_root/storage/app/private/$cover_relative_path"
    test -f "$document_absolute_path"
    test -f "$cover_absolute_path"

    content_consumer_artisan queue:work --stop-when-empty --queue=media,default --max-jobs=20 --tries=1
    content_consumer_artisan content-consumer:smoke --verify-queue --format=json
    test -f "$document_absolute_path"
    test ! -e "$cover_absolute_path"

    npm install --ignore-scripts --no-save 'typescript@^5.9.3'
    ./node_modules/.bin/tsc --noEmit -p content-consumer-types/tsconfig.json
    composer audit --locked --no-interaction
    content_consumer_artisan migrate:rollback --force --step=999
    test -f "$document_absolute_path"
    test ! -e "$cover_absolute_path"
    rm -- "$document_absolute_path"
    test ! -e "$document_absolute_path"
}

run_consumer_mode package_owned true
run_consumer_mode application_owned false
