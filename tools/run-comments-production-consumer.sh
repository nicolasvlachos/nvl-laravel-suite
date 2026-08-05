#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo 'Usage: tools/run-comments-production-consumer.sh <consumer-directory>' >&2
    exit 64
fi

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
consumer_root="$(cd "$1" && pwd -P)"
fixture_root="$repository_root/tools/fixtures/comments-production-consumer"

cp -R "$fixture_root/app/." "$consumer_root/app/"
cp "$fixture_root/config/comments.php" "$consumer_root/config/comments.php"
cp "$fixture_root/bootstrap/providers.php" "$consumer_root/bootstrap/providers.php"
cp \
    "$fixture_root/database/migrations/2026_08_02_000003_create_comments_consumer_articles_table.php" \
    "$consumer_root/database/migrations/2026_08_02_000003_create_comments_consumer_articles_table.php"
mkdir -p "$consumer_root/comments-consumer-types"
cp -R "$fixture_root/typescript/." "$consumer_root/comments-consumer-types/"

cd "$consumer_root"
composer dump-autoload --no-interaction

comments_artisan() {
    APP_ENV=production \
    APP_URL=https://comments-consumer.test \
    APP_DEBUG=false \
    CACHE_STORE=database \
    CACHE_LIMITER=database \
    FILESYSTEM_DISK=local \
    MEDIA_ALLOW_NOOP_SCANNER=true \
    MEDIA_QUEUE_ENABLED=false \
    QUEUE_CONNECTION=sync \
        php artisan "$@"
}

comments_artisan config:clear
comments_artisan route:clear
comments_artisan config:cache
comments_artisan route:cache
comments_artisan migrate --force
comments_artisan nvl:comments:doctor --strict --format=json
comments_artisan comments-consumer:smoke --format=json
comments_artisan comments-consumer:smoke --format=json
comments_artisan nvl:data:types:generate
comments_artisan nvl:data:types:check

npm install --ignore-scripts --no-save 'typescript@^5.9.3'
./node_modules/.bin/tsc --noEmit -p comments-consumer-types/tsconfig.json

comments_artisan migrate:rollback --force --step=999
comments_artisan migrate --force
comments_artisan nvl:comments:doctor --strict --format=json
comments_artisan comments-consumer:smoke --format=json
