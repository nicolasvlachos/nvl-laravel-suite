#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
fixture_root="$repository_root/tools/fixtures/tenancy-production-consumer"
consumer_workspace="$(mktemp -d "${TMPDIR:-/tmp}/nvl-tenancy-consumer.XXXXXX")"
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

database_for_profile() {
    local profile="$1"
    if [[ "${TENANCY_CONSUMER_DB_CONNECTION:-sqlite}" == 'sqlite' ]]; then
        printf '%s\n' "$consumer_workspace/$profile/database/database.sqlite"
    elif [[ "$profile" == 'auth-media' ]]; then
        printf '%s\n' "${TENANCY_CONSUMER_FULL_DATABASE:?Set TENANCY_CONSUMER_FULL_DATABASE for native proof.}"
    else
        printf '%s\n' "${TENANCY_CONSUMER_MEDIA_DATABASE:?Set TENANCY_CONSUMER_MEDIA_DATABASE for native proof.}"
    fi
}

prepare_application() {
    local profile="$1"
    local consumer_root="$consumer_workspace/$profile"

    composer create-project --no-interaction --prefer-dist 'laravel/laravel:^13.0' "$consumer_root"
    cp "$consumer_root/.env.example" "$consumer_root/.env"
    mkdir -p "$consumer_root/storage/app/private/tenancy-consumer" "$consumer_root/storage/app/tenant-media"
    if [[ "${TENANCY_CONSUMER_DB_CONNECTION:-sqlite}" == 'sqlite' ]]; then
        touch "$consumer_root/database/database.sqlite"
    fi
}

install_fixture() {
    local profile="$1"
    local consumer_root="$consumer_workspace/$profile"
    if [[ "$profile" == 'auth-media' ]]; then
        cp -R "$fixture_root/app/." "$consumer_root/app/"
        cp -R "$fixture_root/config/." "$consumer_root/config/"
        cp -R "$fixture_root/database/." "$consumer_root/database/"
        cp "$fixture_root/bootstrap/providers.php" "$consumer_root/bootstrap/providers.php"

        return
    fi

    local paths=(
        app/Console/Commands/TenancyConsumerSmokeCommand.php
        app/Consumers/MediaOnlyConsumerWorkflow.php
        app/Consumers/MediaProof.php
        app/Contracts/TenancyConsumerWorkflow.php
        app/Jobs/TenantProbeJob.php
        app/Models/TenantArticle.php
        app/Providers/MediaOnlyTenancyConsumerServiceProvider.php
        app/Tenancy/ConsumerPlatformAccess.php
        app/Tenancy/HostMembershipAccess.php
        app/Tenancy/HostPrincipal.php
        app/Tenancy/HostTenantDirectory.php
        app/Tenancy/TenantArticleAdoptionAdapter.php
        config/filesystems.php
        config/media.php
        config/tenancy-consumer.php
        database/migrations/2026_09_16_190001_create_tenant_probe_tables.php
    )
    local path
    for path in "${paths[@]}"; do
        mkdir -p "$consumer_root/$(dirname "$path")"
        cp "$fixture_root/$path" "$consumer_root/$path"
    done
    cp "$fixture_root/config/tenancy-media-only.php" "$consumer_root/config/tenancy.php"
    cp "$fixture_root/bootstrap/providers-media-only.php" "$consumer_root/bootstrap/providers.php"
    test ! -d "$consumer_root/app/Auth"
    test ! -f "$consumer_root/app/Consumers/AuthMediaConsumerWorkflow.php"
    if grep -R 'use Nvl\\Auth\\' "$consumer_root/app" "$consumer_root/config"; then
        echo 'The standalone Media profile contains a hidden NVL Auth import.' >&2
        exit 1
    fi
}

configure_full_archive() {
    local consumer_root="$consumer_workspace/auth-media"
    local repository_config
    repository_config="$(jq -nc \
        --arg url "$consumer_workspace/artifact" \
        --arg version "$artifact_version" \
        '{"type":"path","url":$url,"options":{"symlink":false,"versions":{"nvl/laravel-suite":$version}}}')"
    (
        cd "$consumer_root"
        composer config repositories.nvl-tenancy-consumer "$repository_config"
        composer require --no-interaction --no-scripts --with-all-dependencies "nvl/laravel-suite:$artifact_version"
        test ! -L vendor/nvl/laravel-suite
    )
}

configure_media_archives() {
    local consumer_root="$consumer_workspace/media-only"
    local package_version="$artifact_version"
    if [[ "$package_version" == '1.99.0' ]]; then
        package_version='2.0.0'
    fi
    local packages=(support data filterable tenancy translatable media)
    local package
    for package in "${packages[@]}"; do
        local source="$consumer_workspace/artifact/packages/nvl/$package"
        local archive_dir="$consumer_workspace/standalone-archives"
        local extracted="$consumer_workspace/standalone/$package"
        mkdir -p "$archive_dir" "$extracted"
        (
            cd "$source"
            COMPOSER_ROOT_VERSION="$package_version" composer archive \
                --format=zip \
                --file="$package" \
                --dir="$archive_dir" \
                --no-interaction
        )
        unzip -q "$archive_dir/$package.zip" -d "$extracted"
        local repository_config
        repository_config="$(jq -nc \
            --arg url "$extracted" \
            --arg name "nvl/$package" \
            --arg version "$package_version" \
            '{"type":"path","url":$url,"options":{"symlink":false,"versions":{($name):$version}}}')"
        (
            cd "$consumer_root"
            composer config "repositories.nvl-$package" "$repository_config"
        )
    done
    (
        cd "$consumer_root"
        composer require --no-interaction --no-scripts --with-all-dependencies "nvl/media:$package_version"
        test ! -L vendor/nvl/media
        test ! -L vendor/nvl/tenancy
        test ! -d vendor/nvl/auth
        if composer show nvl/auth >/dev/null 2>&1; then
            echo 'The standalone Media profile unexpectedly installed NVL Auth.' >&2
            exit 1
        fi
    )
}

consumer_artisan() {
    local profile="$1"
    shift
    local consumer_root="$consumer_workspace/$profile"
    local database
    database="$(database_for_profile "$profile")"
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL="https://$profile.tenancy-consumer.test" \
    CACHE_STORE="${TENANCY_CONSUMER_CACHE_STORE:-file}" \
    DB_CONNECTION="${TENANCY_CONSUMER_DB_CONNECTION:-sqlite}" \
    DB_DATABASE="$database" \
    DB_HOST="${DB_HOST:-127.0.0.1}" \
    DB_PORT="${DB_PORT:-5432}" \
    DB_USERNAME="${DB_USERNAME:-}" \
    DB_PASSWORD="${DB_PASSWORD:-}" \
    REDIS_HOST="${REDIS_HOST:-127.0.0.1}" \
    REDIS_PORT="${REDIS_PORT:-6379}" \
    QUEUE_CONNECTION=database \
    DB_QUEUE_RETRY_AFTER=120 \
    SESSION_DRIVER=file \
    TENANCY_CONSUMER_ENABLED="${TENANCY_CONSUMER_ENABLED:-true}" \
    TENANCY_CONSUMER_PACKAGE_MIGRATIONS=true \
    TENANCY_CONSUMER_PROFILE="$profile" \
    TENANCY_CONSUMER_MEDIA_DRIVER="${TENANCY_CONSUMER_MEDIA_DRIVER:-local}" \
    AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-}" \
    AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-}" \
    AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-us-east-1}" \
    AWS_BUCKET="${AWS_BUCKET:-}" \
    AWS_ENDPOINT="${AWS_ENDPOINT:-}" \
        php "$consumer_root/artisan" "$@"
}

run_profile() {
    local profile="$1"
    local consumer_root="$consumer_workspace/$profile"
    (
        cd "$consumer_root"
        composer dump-autoload --no-interaction --no-scripts
    )
    consumer_artisan "$profile" key:generate --force
    consumer_artisan "$profile" package:discover --ansi
    consumer_artisan "$profile" config:clear
    consumer_artisan "$profile" route:clear
    consumer_artisan "$profile" config:cache
    consumer_artisan "$profile" route:cache
    consumer_artisan "$profile" migrate --force
    consumer_artisan "$profile" down --render='errors::503'
    local seed_json
    seed_json="$(consumer_artisan "$profile" tenancy-consumer:smoke --phase=seed --format=json)"
    jq -e '.passed == true and ([.checks[]] | all)' <<< "$seed_json" >/dev/null
    consumer_artisan "$profile" up

    TENANCY_CONSUMER_ENABLED=false consumer_artisan "$profile" config:clear
    local disabled_output="$consumer_workspace/$profile-disabled.out"
    local unsafe_downgrade_denied=false
    if ! TENANCY_CONSUMER_ENABLED=false consumer_artisan "$profile" config:cache >"$disabled_output" 2>&1; then
        unsafe_downgrade_denied=true
    elif ! TENANCY_CONSUMER_ENABLED=false consumer_artisan "$profile" tenancy-consumer:smoke --phase=verify --format=json >>"$disabled_output" 2>&1; then
        unsafe_downgrade_denied=true
    fi
    if [[ "$unsafe_downgrade_denied" != true ]]; then
        echo "The adopted [$profile] consumer accepted an unsafe disabled downgrade." >&2
        exit 1
    fi
    if grep -q '"checks"' "$disabled_output"; then
        echo "The adopted [$profile] consumer returned resource rows during an unsafe disabled downgrade." >&2
        exit 1
    fi
    rm -f "$consumer_root/bootstrap/cache/config.php"
    TENANCY_CONSUMER_ENABLED=true consumer_artisan "$profile" config:cache

    consumer_artisan "$profile" queue:work database \
        --queue=tenancy-proof \
        --stop-when-empty \
        --max-jobs=4 \
        --tries=1 \
        --timeout=60 \
        --sleep=0
    local verify_json
    verify_json="$(consumer_artisan "$profile" tenancy-consumer:smoke --phase=verify --format=json)"
    jq -e '.passed == true and ([.checks[]] | all)' <<< "$verify_json" >/dev/null
    local auth_dependency_absent=false
    if [[ "$profile" == 'media-only' ]]; then
        auth_dependency_absent=true
    fi
    jq -nc \
        --arg profile "$profile" \
        --argjson auth_dependency_absent "$auth_dependency_absent" \
        --argjson seed "$seed_json" \
        --argjson verify "$verify_json" \
        '{profile:$profile, sealed_archive:true, config_cache:true, route_cache:true, restarted_between_phases:true, real_worker:true, unsafe_downgrade_denied:true, unsafe_downgrade_returned_no_rows:true, auth_dependency_absent:$auth_dependency_absent, seed:$seed, verify:$verify}'
}

prepare_application auth-media
configure_full_archive
install_fixture auth-media
full_result="$(run_profile auth-media)"

prepare_application media-only
configure_media_archives
install_fixture media-only
media_result="$(run_profile media-only)"

combined="$(jq -nc \
    --argjson full "$full_result" \
    --argjson media "$media_result" \
    '{passed:($full.verify.passed and $media.verify.passed), profiles:{auth_media:$full, media_only:$media}}')"
printf '%s\n' "$combined"

if [[ -n "${TENANCY_CONSUMER_EVIDENCE_FILE:-}" ]]; then
    printf '%s\n' "$combined" > "$TENANCY_CONSUMER_EVIDENCE_FILE"
fi
