#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 4 ]]; then
    echo 'Usage: tools/run-comments-artifact-consumer.sh <laravel-major> <package-version> <archive-directory> <work-directory>' >&2
    exit 64
fi

laravel_major="$1"
package_version="$2"
archive_directory="$3"
work_directory="$4"

case "$laravel_major" in
    12 | 13)
        ;;
    *)
        echo "Unsupported Laravel major [$laravel_major]. Expected 12 or 13." >&2
        exit 64
        ;;
esac

if [[ -z "$package_version" ]]; then
    echo 'The package version must not be empty.' >&2
    exit 64
fi

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

if [[ ! -d "$archive_directory" ]]; then
    echo "Archive directory [$archive_directory] does not exist." >&2
    exit 66
fi

archive_directory="$(cd "$archive_directory" && pwd -P)"

if [[ -e "$work_directory" ]]; then
    echo "Work directory [$work_directory] already exists." >&2
    exit 73
fi

mkdir -p "$work_directory/artifacts"
work_root="$(cd "$work_directory" && pwd -P)"
artifact_root="$work_root/artifacts"
consumer_root="$work_root/consumer"

shopt -s nullglob
archives=("$archive_directory"/nvl-{comments,data,filterable,media,support,translatable}-*.zip)
shopt -u nullglob

if [[ ${#archives[@]} -ne 6 ]]; then
    echo "Expected exactly six Comments closure archives in [$archive_directory], found [${#archives[@]}]." >&2
    exit 65
fi

for package in comments data filterable media support translatable; do
    matches=()
    for archive in "${archives[@]}"; do
        if [[ "$(basename "$archive")" == "nvl-$package-"*.zip ]]; then
            matches+=("$archive")
        fi
    done

    if [[ ${#matches[@]} -ne 1 ]]; then
        echo "Expected exactly one archive for [nvl/$package], found [${#matches[@]}]." >&2
        exit 65
    fi
done

cp "${archives[@]}" "$artifact_root/"
test ! -e "$artifact_root/packages.json"

composer create-project \
    --no-interaction \
    --no-dev \
    --prefer-dist \
    "laravel/laravel:^$laravel_major.0" \
    "$consumer_root"

cd "$consumer_root"
composer config repositories.nvl artifact "$artifact_root"
composer require \
    --no-interaction \
    --update-no-dev \
    --with-all-dependencies \
    --prefer-dist \
    "nvl/comments:$package_version"

php -r '
$expected = [
    "nvl/comments",
    "nvl/data",
    "nvl/filterable",
    "nvl/media",
    "nvl/support",
    "nvl/translatable",
];
$expectedVersion = $argv[1];
$artifactRoot = $argv[2];
$workspace = $argv[3];
$lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
$packages = [];
foreach ($lock["packages"] ?? [] as $package) {
    if (in_array($package["name"] ?? null, $expected, true)) {
        $packages[$package["name"]] = $package;
    }
}
foreach ($expected as $name) {
    $package = $packages[$name] ?? null;
    $url = is_array($package) ? ($package["dist"]["url"] ?? null) : null;
    if (! is_array($package)
        || ($package["version"] ?? null) !== $expectedVersion
        || ($package["dist"]["type"] ?? null) !== "zip"
        || ! is_string($url)
        || ! str_starts_with($url, $artifactRoot."/")
        || str_contains($url, $workspace)) {
        fwrite(STDERR, "Invalid relocated Comments artifact provenance for [$name].\n");
        exit(1);
    }

    $archivePath = realpath($url);
    if (! is_string($archivePath) || ! str_starts_with($archivePath, $artifactRoot."/")) {
        fwrite(STDERR, "Unresolvable relocated Comments artifact for [$name].\n");
        exit(1);
    }
}
$manifest = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
if (($manifest["require"]["nvl/comments"] ?? null) !== $expectedVersion) {
    fwrite(STDERR, "The consumer does not directly require the exact Comments version.\n");
    exit(1);
}
foreach (array_slice($expected, 1) as $transitive) {
    if (isset($manifest["require"][$transitive])) {
        fwrite(STDERR, "Transitive package [$transitive] became a direct consumer requirement.\n");
        exit(1);
    }
}
' "$package_version" "$artifact_root" "$repository_root"

cp .env.example .env
touch database/database.sqlite
php artisan key:generate
"$repository_root/tools/run-comments-production-consumer.sh" "$consumer_root"
composer audit --locked --no-interaction
