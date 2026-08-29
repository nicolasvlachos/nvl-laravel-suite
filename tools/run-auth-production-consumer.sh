#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
fixture_root="$repository_root/tools/fixtures/auth-production-consumer"
consumer_workspace="$(mktemp -d "${TMPDIR:-/tmp}/nvl-auth-consumer.XXXXXX")"
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
    composer config repositories.nvl-auth-consumer "$repository_config"
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
    cp -R "$fixture_root/resources/." resources/
    cp "$fixture_root/bootstrap/providers.php" bootstrap/providers.php
    mkdir -p auth-consumer-types
    cp -R "$fixture_root/typescript/." auth-consumer-types/
    cp .env.example .env
    touch database/database.sqlite
    composer dump-autoload --no-interaction
    php artisan key:generate --force

    auth_consumer_artisan() {
        APP_ENV=production \
        APP_DEBUG=false \
        APP_URL=https://auth-consumer.test \
        AUTH_CONSUMER_PACKAGE_MIGRATIONS="$package_migrations" \
        CACHE_STORE=database \
        CACHE_LIMITER=database \
        DB_CONNECTION=sqlite \
        DB_DATABASE="$consumer_root/database/database.sqlite" \
        DB_QUEUE_RETRY_AFTER=1200 \
        MAIL_MAILER=array \
        MAIL_NOTIFICATIONS_WEBHOOKS_ENABLED=false \
        QUEUE_CONNECTION=database \
        SESSION_DRIVER=database \
            php artisan "$@"
    }

    if [[ "$migration_mode" == 'application_owned' ]]; then
        auth_consumer_artisan vendor:publish --tag=auth-migrations --force
        auth_consumer_artisan vendor:publish --tag=settings-migrations --force
        auth_consumer_artisan vendor:publish --tag=activity-migrations --force
        auth_consumer_artisan vendor:publish --tag=mail-notifications-migrations --force
    fi

    auth_consumer_artisan config:clear
    auth_consumer_artisan route:clear
    auth_consumer_artisan config:cache
    auth_consumer_artisan route:cache
    auth_consumer_artisan migrate --force
    auth_consumer_artisan nvl:settings:validate
    auth_consumer_artisan nvl:settings:sync
    auth_consumer_artisan nvl:suite:skills:publish --format=json
    auth_consumer_artisan nvl:data:types:generate
    auth_consumer_artisan nvl:data:types:check
    auth_consumer_artisan nvl:suite:doctor --strict --production --format=json
    auth_consumer_artisan nvl:suite:consumer-audit --strict --format=json
    auth_consumer_artisan auth-consumer:smoke --format=json
    auth_consumer_artisan queue:work --stop-when-empty --max-jobs=10 --tries=1
    auth_consumer_artisan auth-consumer:smoke --verify-queued-mail --format=json

    npm install --ignore-scripts --no-save 'typescript@^5.9.3'
    ./node_modules/.bin/tsc --noEmit -p auth-consumer-types/tsconfig.json
    composer audit --locked --no-interaction
    auth_consumer_artisan migrate:rollback --force --step=999
}

run_consumer_mode package_owned true
run_consumer_mode application_owned false
