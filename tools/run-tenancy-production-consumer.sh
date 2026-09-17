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
    elif [[ "$profile" == 'auth-media-restore' ]]; then
        printf '%s\n' "${TENANCY_CONSUMER_RESTORE_DATABASE:?Set TENANCY_CONSUMER_RESTORE_DATABASE for native proof.}"
    elif [[ "$profile" == 'media-only' ]]; then
        printf '%s\n' "${TENANCY_CONSUMER_MEDIA_DATABASE:?Set TENANCY_CONSUMER_MEDIA_DATABASE for native proof.}"
    else
        printf '%s\n' "${TENANCY_CONSUMER_TAXONOMY_DATABASE:?Set TENANCY_CONSUMER_TAXONOMY_DATABASE for native proof.}"
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
    if [[ "$profile" == 'auth-media' || "$profile" == 'auth-media-restore' ]]; then
        cp -R "$fixture_root/app/." "$consumer_root/app/"
        cp -R "$fixture_root/config/." "$consumer_root/config/"
        cp -R "$fixture_root/database/." "$consumer_root/database/"
        cp "$fixture_root/bootstrap/providers.php" "$consumer_root/bootstrap/providers.php"

        return
    fi

    if [[ "$profile" == 'taxonomy-only' ]]; then
        local taxonomy_paths=(
            app/Console/Commands/TenancyConsumerSmokeCommand.php
            app/Consumers/TaxonomyOnlyConsumerWorkflow.php
            app/Contracts/TenancyConsumerWorkflow.php
            app/Models/TenantTaxonomyRecord.php
            app/Providers/TaxonomyOnlyTenancyConsumerServiceProvider.php
            app/Tenancy/ConsumerPlatformAccess.php
            app/Tenancy/HostMembershipAccess.php
            app/Tenancy/HostPrincipal.php
            app/Tenancy/HostTenantDirectory.php
            app/Tenancy/TenantTaxonomyRecordAdoptionAdapter.php
            config/tenancy-consumer.php
            database/migrations/2026_09_16_190001_create_tenant_probe_tables.php
        )
        local taxonomy_path
        for taxonomy_path in "${taxonomy_paths[@]}"; do
            mkdir -p "$consumer_root/$(dirname "$taxonomy_path")"
            cp "$fixture_root/$taxonomy_path" "$consumer_root/$taxonomy_path"
        done
        cp "$fixture_root/config/tenancy-taxonomy-only.php" "$consumer_root/config/tenancy.php"
        cp "$fixture_root/config/taxonomy-only.php" "$consumer_root/config/taxonomy.php"
        cp "$fixture_root/bootstrap/providers-taxonomy-only.php" "$consumer_root/bootstrap/providers.php"
        if grep -R 'use Nvl\\Auth\\\|use Nvl\\Media\\' "$consumer_root/app" "$consumer_root/config"; then
            echo 'The standalone Taxonomy profile contains a hidden NVL Auth or Media import.' >&2
            exit 1
        fi

        return
    fi

    local paths=(
        app/Console/Commands/TenancyConsumerSmokeCommand.php
        app/Consumers/MediaOnlyConsumerWorkflow.php
        app/Consumers/MediaProof.php
        app/Consumers/LegacyAdoptionProof.php
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
        legacy/adoption-manifest.json
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

configure_standalone_archives() {
    local profile="$1"
    shift
    local consumer_root="$consumer_workspace/$profile"
    local package_version="$artifact_version"
    if [[ "$package_version" == '1.99.0' ]]; then
        package_version='2.0.0'
    fi
    local package
    for package in "$@"; do
        local source="$consumer_workspace/artifact/packages/nvl/$package"
        local archive_dir="$consumer_workspace/$profile-archives"
        local extracted="$consumer_workspace/$profile-standalone/$package"
        mkdir -p "$archive_dir" "$extracted"
        (
            cd "$source"
            COMPOSER_ROOT_VERSION="$package_version" composer archive --format=zip --file="$package" --dir="$archive_dir" --no-interaction
        )
        unzip -q "$archive_dir/$package.zip" -d "$extracted"
        local repository_config
        repository_config="$(jq -nc --arg url "$extracted" --arg name "nvl/$package" --arg version "$package_version" '{"type":"path","url":$url,"options":{"symlink":false,"versions":{($name):$version}}}')"
        (cd "$consumer_root" && composer config "repositories.nvl-$package" "$repository_config")
    done
    (cd "$consumer_root" && composer require --no-interaction --no-scripts --with-all-dependencies "nvl/${!#}:$package_version")
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
    if [[ "$profile" == 'media-only' || "$profile" == 'taxonomy-only' ]]; then
        auth_dependency_absent=true
    fi
    jq -nc \
        --arg profile "$profile" \
        --argjson auth_dependency_absent "$auth_dependency_absent" \
        --argjson seed "$seed_json" \
        --argjson verify "$verify_json" \
        '{profile:$profile, sealed_archive:true, config_cache:true, route_cache:true, restarted_between_phases:true, real_worker:true, unsafe_downgrade_denied:true, unsafe_downgrade_returned_no_rows:true, auth_dependency_absent:$auth_dependency_absent, seed:$seed, verify:$verify}'
}

run_configuration_matrix() {
    local profile='auth-media'
    local cases=(
        disabled unresolved full host-uuid-custom-principals conflicting-platform-family
        sharing-none sharing-copy invalid-classes invalid-families invalid-custom-tables
        invalid-connection-aliases custom-tables custom-connection-aliases cached-process reused-process
    )
    local case
    local results='[]'
    for case in "${cases[@]}"; do
        rm -f "$consumer_workspace/$profile/bootstrap/cache/config.php"
        local result
        if TENANCY_CONSUMER_MATRIX_PROFILE="$case" consumer_artisan "$profile" config:cache >/dev/null 2>&1; then
            result="$(TENANCY_CONSUMER_MATRIX_PROFILE="$case" consumer_artisan "$profile" tenancy-consumer:configuration "$case" --format=json)"
        else
            result="$(jq -nc --arg profile "$case" --argjson expects_failure "$(case "$case" in conflicting-platform-family|invalid-classes|invalid-families|invalid-custom-tables|invalid-connection-aliases) echo true ;; *) echo false ;; esac)" '{profile:$profile,passed:$expects_failure,expects_failure:$expects_failure,boot_rejected:true}')"
        fi
        results="$(jq -nc --argjson previous "$results" --argjson result "$result" '$previous + [$result]')"
    done
    printf '%s\n' "$results"
}

run_competing_operations() {
    local races=(last-owner grant-revoke-import slug-handle-create media-slot-completion submission-idempotency)
    local race
    for race in "${races[@]}"; do
        consumer_artisan auth-media tenancy-consumer:race "$race" a --barrier="race-$race" >"$consumer_workspace/$race-a.json" &
        local pid_a=$!
        consumer_artisan auth-media tenancy-consumer:race "$race" b --barrier="race-$race" >"$consumer_workspace/$race-b.json" &
        local pid_b=$!
        wait "$pid_a" "$pid_b"
    done
}

prepare_application auth-media
configure_full_archive
install_fixture auth-media
full_result="$(run_profile auth-media)"
configuration_matrix="$(run_configuration_matrix)"
consumer_artisan auth-media tenancy-consumer:lifecycle backup --format=json
cp -R "$consumer_workspace/auth-media" "$consumer_workspace/auth-media-restore"
restore_source="$consumer_workspace/auth-media-restore-state"
if [[ "${TENANCY_CONSUMER_DB_CONNECTION:-sqlite}" == 'sqlite' ]]; then
    cp "$consumer_workspace/auth-media/database/database.sqlite" "$consumer_workspace/auth-media-restore/database/database.sqlite"
    cp "$consumer_workspace/auth-media/database/database.sqlite" "$restore_source"
else
    : "${TENANCY_CONSUMER_RESTORE_DATABASE:?Set TENANCY_CONSUMER_RESTORE_DATABASE for native proof.}"
    PGPASSWORD="${DB_PASSWORD:-}" pg_dump \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME:-}" \
        --format=custom \
        --file="$restore_source" \
        "${TENANCY_CONSUMER_FULL_DATABASE}"
    PGPASSWORD="${DB_PASSWORD:-}" pg_restore \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME:-}" \
        --dbname="${TENANCY_CONSUMER_RESTORE_DATABASE}" \
        --clean --if-exists "$restore_source"
fi
consumer_artisan auth-media tenancy-consumer:lifecycle adopt --format=json
run_competing_operations
consumer_artisan auth-media tenancy-consumer:query-plans
consumer_artisan auth-media tenancy-consumer:lifecycle suspend --format=json
consumer_artisan auth-media down --render='errors::503'
consumer_artisan auth-media tenancy-consumer:lifecycle cleanup --format=json
consumer_artisan auth-media tenancy-consumer:lifecycle cleanup --format=json
consumer_artisan auth-media up
consumer_artisan auth-media tenancy-consumer:lifecycle verify --format=json
TENANCY_CONSUMER_RESTORE_SOURCE="$restore_source" consumer_artisan auth-media-restore tenancy-consumer:lifecycle restore --format=json

prepare_application media-only
configure_media_archives
install_fixture media-only
media_result="$(run_profile media-only)"

prepare_application taxonomy-only
configure_standalone_archives taxonomy-only support data tenancy translatable taxonomy
install_fixture taxonomy-only
taxonomy_result="$(run_profile taxonomy-only)"

combined="$(jq -nc \
    --argjson full "$full_result" \
    --argjson media "$media_result" \
    --argjson taxonomy "$taxonomy_result" \
    --argjson matrix "$configuration_matrix" \
    '{passed:($full.verify.passed and $media.verify.passed and $taxonomy.verify.passed and ([ $matrix[].passed ] | all)), profiles:{auth_media:$full, media_only:$media, taxonomy_only:$taxonomy}, configuration_matrix:$matrix, lifecycle:true, competing_processes:true, query_plans:true}')"
printf '%s\n' "$combined"

if [[ -n "${TENANCY_CONSUMER_EVIDENCE_FILE:-}" ]]; then
    printf '%s\n' "$combined" > "$TENANCY_CONSUMER_EVIDENCE_FILE"
fi
