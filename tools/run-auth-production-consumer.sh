#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo 'Usage: tools/run-auth-production-consumer.sh <consumer-directory> <profile>' >&2
    exit 64
fi

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
consumer_root="$(cd "$1" && pwd -P)"
fixture_root="$repository_root/tools/fixtures/auth-production-consumer"
profile="$2"

case "$profile" in
    browser-baseline)
        expected_features='["authentication","password","devices","sessions"]'
        expected_schedule_count=8
        ;;
    selective-feature)
        expected_features='["authentication","password","magic_links","security_codes","contacts","totp","devices","sessions","security_notifications"]'
        expected_schedule_count=8
        ;;
    all-enabled)
        expected_features='["authentication","password","magic_links","security_codes","contacts","invitations","totp","passkeys","recovery_codes","account_recovery","social_identities","devices","cross_device","sessions","clients","api_tokens","rbac","security_notifications","principal_management","security_event_management"]'
        expected_schedule_count=9
        ;;
    ingress-disabled)
        expected_features='["authentication","password","magic_links","security_codes","contacts","invitations","totp","passkeys","recovery_codes","account_recovery","social_identities","devices","cross_device","sessions","clients","api_tokens","rbac","security_notifications","principal_management","security_event_management"]'
        expected_schedule_count=8
        ;;
    *)
        echo "Unsupported Auth production-consumer profile [$profile]." >&2
        exit 64
        ;;
esac

cp -R "$fixture_root/app/." "$consumer_root/app/"
cp -R "$fixture_root/database/." "$consumer_root/database/"
cp -R "$fixture_root/routes/." "$consumer_root/routes/"
cp "$fixture_root/bootstrap/providers.php" "$consumer_root/bootstrap/providers.php"
cp "$fixture_root/config/auth-consumer.php" "$consumer_root/config/auth-consumer.php"

cd "$consumer_root"

export APP_ENV=production
export APP_URL=https://auth-consumer.test
export APP_DEBUG=false
export AUTH_CONSUMER_PROFILE="$profile"
export CACHE_STORE=database
export CACHE_LIMITER=database
export QUEUE_CONNECTION=database

php_artisan() {
    php -d error_reporting=8191 artisan "$@"
}

report_root="${RUNNER_TEMP:-/tmp}/nvl-auth-consumer-$profile-$$"
mkdir -p "$report_root"

composer dump-autoload --no-interaction
php_artisan config:clear
php_artisan route:clear
php_artisan vendor:publish --tag=sanctum-migrations --force
php_artisan vendor:publish --tag=permission-migrations --force
php_artisan migrate --force
php_artisan auth-consumer:maintenance --format=json
php_artisan nvl:auth:doctor --strict --format=json

before_inventory="$report_root/features-before-cache.json"
after_inventory="$report_root/features-after-cache.json"
reapplied_inventory="$report_root/features-after-reapply.json"

php_artisan nvl:auth:features --format=json > "$before_inventory"

assert_inventory() {
    local inventory="$1"
    local registered_routes
    local actual_routes

    jq -e \
        --argjson expected "$expected_features" \
        'length == 20
            and ([.[] | select(.enabled) | .feature] | sort) == ($expected | sort)' \
        "$inventory" > /dev/null

    if [ "$profile" = 'ingress-disabled' ]; then
        jq -e 'all(.[]; .effective_status == "ingress_disabled")' \
            "$inventory" > /dev/null
    else
        jq -e 'all(.[]; if .enabled
            then .effective_status == "ready"
            else .effective_status == "disabled"
            end)' "$inventory" > /dev/null
    fi

    registered_routes="$(jq '[.[].registered_route_count] | add' "$inventory")"
    actual_routes="$(php_artisan route:list --json | jq \
        '[.[] | select((.name // "") | startswith("nvl.auth."))] | length')"

    if [ "$registered_routes" -ne "$actual_routes" ]; then
        echo "Feature inventory reports [$registered_routes] package routes, route:list reports [$actual_routes]." >&2
        return 1
    fi

    case "$profile" in
        browser-baseline|ingress-disabled)
            [ "$registered_routes" -eq 0 ]
            ;;
        selective-feature)
            [ "$registered_routes" -gt 0 ] && [ "$registered_routes" -lt 89 ]
            ;;
        all-enabled)
            [ "$registered_routes" -eq 89 ]
            ;;
    esac
}

inventory_matrix() {
    jq -S '[.[] | {
        feature,
        enabled,
        mode,
        effective_status,
        effective_operations,
        registered_route_count,
        effective_route_count
    }]' "$1"
}

assert_inventory "$before_inventory"
inventory_matrix "$before_inventory" > "$report_root/matrix-before-cache.json"

php_artisan config:cache
php_artisan route:cache
php_artisan nvl:auth:doctor --strict --format=json
php_artisan nvl:auth:features --format=json > "$after_inventory"

assert_inventory "$after_inventory"
inventory_matrix "$after_inventory" > "$report_root/matrix-after-cache.json"
diff -u \
    "$report_root/matrix-before-cache.json" \
    "$report_root/matrix-after-cache.json"

php_artisan schedule:list > "$report_root/schedule.txt"
schedule_count="$(grep -c 'nvl-auth:maintenance:' "$report_root/schedule.txt")"

if [ "$schedule_count" -ne "$expected_schedule_count" ]; then
    echo "Expected [$expected_schedule_count] Auth maintenance schedules, found [$schedule_count]." >&2
    exit 1
fi

php_artisan schedule:run --no-interaction
php_artisan queue:work database \
    --queue=default \
    --stop-when-empty \
    --max-time=120 \
    --tries=1 \
    --timeout=90 \
    --no-interaction
php_artisan nvl:auth:doctor --strict --format=json

if [ "$profile" = 'all-enabled' ]; then
    php_artisan auth-consumer:smoke --format=json
fi

php_artisan migrate:rollback --force --step=999
php_artisan migrate --force
php_artisan auth-consumer:maintenance --format=json
php_artisan nvl:auth:doctor --strict --format=json
php_artisan nvl:auth:features --format=json > "$reapplied_inventory"

assert_inventory "$reapplied_inventory"
inventory_matrix "$reapplied_inventory" > "$report_root/matrix-after-reapply.json"
diff -u \
    "$report_root/matrix-after-cache.json" \
    "$report_root/matrix-after-reapply.json"

if [ "$profile" = 'all-enabled' ]; then
    php_artisan auth-consumer:smoke --format=json
fi
