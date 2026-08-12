#!/usr/bin/env bash

set -u -o pipefail

if [[ "$#" -eq 0 ]]; then
  echo 'Usage: retry-composer.sh <composer command> [arguments...]' >&2
  exit 64
fi

composer_binary="${COMPOSER_BINARY:-composer}"
retry_schedule="${COMPOSER_RETRY_DELAYS_SECONDS:-15 30 60 120 180}"
read -r -a retry_delays <<< "$retry_schedule"
total_attempts="$(( ${#retry_delays[@]} + 1 ))"

for delay in "${retry_delays[@]}"; do
  if [[ ! "$delay" =~ ^[0-9]+$ ]]; then
    echo "Invalid Composer retry delay [$delay]." >&2
    exit 64
  fi
done

attempt=1

while true; do
  if "$composer_binary" "$@"; then
    exit 0
  else
    exit_code="$?"
  fi

  if [[ "$attempt" -ge "$total_attempts" ]]; then
    echo "Composer failed after $total_attempts attempts." >&2
    exit "$exit_code"
  fi

  delay="${retry_delays[$(( attempt - 1 ))]}"
  echo "Composer attempt $attempt/$total_attempts failed; retrying in ${delay}s." >&2
  sleep "$delay"
  attempt="$(( attempt + 1 ))"
done
