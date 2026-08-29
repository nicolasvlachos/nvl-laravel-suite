#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
fixture_root="$repository_root/tools/fixtures/auth-production-consumer"
prepared_source_commit="d8feceecc02f436772dca74b260704a535bceca6"
previous_version="dev-final-1x-prepared"
candidate_version="${NVL_CANDIDATE_VERSION:-2.0.0}"
candidate_archive="${NVL_CANDIDATE_ARCHIVE:-}"
rehearsal_workspace="$(mktemp -d "${TMPDIR:-/tmp}/nvl-final-1x-upgrade.XXXXXX")"

cleanup() {
    rm -rf "$rehearsal_workspace"
}

trap cleanup EXIT

mkdir -p \
    "$rehearsal_workspace/prepared-source" \
    "$rehearsal_workspace/prepared-archives" \
    "$rehearsal_workspace/prepared-artifact" \
    "$rehearsal_workspace/candidate-archives" \
    "$rehearsal_workspace/candidate-artifact"

git -C "$repository_root" archive "$prepared_source_commit" \
    | tar -x -C "$rehearsal_workspace/prepared-source"
(
    cd "$rehearsal_workspace/prepared-source"
    COMPOSER_ROOT_VERSION="$previous_version" composer archive \
        --format=zip \
        --dir="$rehearsal_workspace/prepared-archives"
)

previous_archive="$(find "$rehearsal_workspace/prepared-archives" -maxdepth 1 -name 'nvl-laravel-suite-*.zip' -print -quit)"

if [[ -z "$previous_archive" ]]; then
    echo 'The prepared final-1.x archive was not created.' >&2
    exit 1
fi

if [[ -z "$candidate_archive" ]]; then
    (
        cd "$repository_root"
        COMPOSER_ROOT_VERSION="$candidate_version" composer archive \
            --format=zip \
            --dir="$rehearsal_workspace/candidate-archives"
    )
    candidate_archive="$(find "$rehearsal_workspace/candidate-archives" -maxdepth 1 -name 'nvl-laravel-suite-*.zip' -print -quit)"
fi

if [[ ! -f "$candidate_archive" ]]; then
    echo "The candidate archive [$candidate_archive] does not exist." >&2
    exit 1
fi

unzip -q "$previous_archive" -d "$rehearsal_workspace/prepared-artifact"
unzip -q "$candidate_archive" -d "$rehearsal_workspace/candidate-artifact"

consumer_root="$rehearsal_workspace/consumer"
bash "$repository_root/tools/retry-composer.sh" create-project \
    --no-interaction \
    --prefer-dist \
    'laravel/laravel:^13.0' \
    "$consumer_root"

cd "$consumer_root"

previous_repository="$(jq -nc \
    --arg url "$rehearsal_workspace/prepared-artifact" \
    --arg version "$previous_version" \
    '{"type":"path","url":$url,"options":{"symlink":false,"versions":{"nvl/laravel-suite":$version}}}')"
composer config repositories.nvl-final-1x "$previous_repository"
bash "$repository_root/tools/retry-composer.sh" require \
    --no-interaction \
    --with-all-dependencies \
    "nvl/laravel-suite:$previous_version"
test ! -L vendor/nvl/laravel-suite

cp -R "$fixture_root/app/." app/
cp -R "$fixture_root/config/." config/
cp -R "$fixture_root/resources/." resources/
cp "$fixture_root/bootstrap/providers.php" bootstrap/providers.php
mkdir -p auth-consumer-types
cp -R "$fixture_root/typescript/." auth-consumer-types/
cp .env.example .env
touch database/database.sqlite
rm -f config/nvl-suite.php
composer dump-autoload --no-interaction
php artisan key:generate --force

auth_consumer_artisan() {
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=https://auth-consumer.test \
    AUTH_CONSUMER_PACKAGE_MIGRATIONS=true \
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

verify_explicit_module_configuration() {
    php -r '
    $configuration = require $argv[1];
    $enabled = array_keys(array_filter($configuration["modules"] ?? []));
    $expected = ["support", "data", "activity", "auth", "mail-notifications", "settings"];
    if (count($configuration["modules"] ?? []) !== 20 || $enabled !== $expected) {
        throw new RuntimeException("The rendered module map is not complete and explicit.");
    }
    ' "$consumer_root/config/nvl-suite.php"
}

exercise_consumer_boundary() {
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
}

auth_consumer_artisan nvl:suite:configure --profile=auth-only \
    --add=activity --add=mail-notifications --add=settings \
    --full --write --force --format=json
verify_explicit_module_configuration
exercise_consumer_boundary
auth_consumer_artisan auth-consumer:smoke --format=json
npm install --ignore-scripts --no-save 'typescript@^5.9.3'
./node_modules/.bin/tsc --noEmit -p auth-consumer-types/tsconfig.json

composer config --unset repositories.nvl-final-1x
candidate_repository="$(jq -nc \
    --arg url "$rehearsal_workspace/candidate-artifact" \
    --arg version "$candidate_version" \
    '{"type":"path","url":$url,"options":{"symlink":false,"versions":{"nvl/laravel-suite":$version}}}')"
composer config repositories.nvl-candidate "$candidate_repository"
bash "$repository_root/tools/retry-composer.sh" require \
    --no-interaction \
    --with-all-dependencies \
    "nvl/laravel-suite:$candidate_version"
test ! -L vendor/nvl/laravel-suite
composer dump-autoload --no-interaction

verify_explicit_module_configuration
exercise_consumer_boundary
auth_consumer_artisan queue:work --stop-when-empty --max-jobs=10 --tries=1
auth_consumer_artisan auth-consumer:smoke --verify-queued-mail --format=json
./node_modules/.bin/tsc --noEmit -p auth-consumer-types/tsconfig.json
composer audit --locked --no-interaction
