#!/bin/sh
set -eu

child=
stop() {
    trap '' TERM INT
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null || true
        wait "$child" 2>/dev/null || true
    fi
    exit 0
}
trap stop TERM INT

while :; do
    started=$(date +%s)
    if [ -f storage/installed.lock ]; then
        php artisan openbook:cron --no-interaction &
        child=$!
        if ! wait "$child"; then
            echo 'Openbook periodic tasks failed; retrying on the next cycle.' >&2
        fi
        child=
    fi
    # Do not overlap invocations; long executions delay the next cycle.
    delay=$((60 - ($(date +%s) - started)))
    if [ "$delay" -lt 1 ]; then delay=1; fi
    sleep "$delay" &
    child=$!
    wait "$child"
    child=
done
