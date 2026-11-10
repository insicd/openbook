#!/bin/sh
set -eu

# Read only explicitly supported secrets; never evaluate file contents as shell.
for name in APP_KEY DB_PASSWORD; do
    file_name="${name}_FILE"
    file_path=$(printenv "$file_name" || true)
    if [ -n "$file_path" ]; then
        if [ -n "$(printenv "$name" || true)" ]; then
            echo "Set either $name or $file_name, not both." >&2
            exit 1
        fi
        if [ ! -r "$file_path" ]; then
            echo "Cannot read $file_name." >&2
            exit 1
        fi
        export "$name=$(cat "$file_path")"
        unset "$file_name"
    fi
done

case "${1:-}" in
    apache2-foreground|openbook-cron|php)
        # Simple diagnostics and key generation can run without instance settings.
        if [ "$1" != php ] || [ "${2:-}" = artisan ]; then
            : "${APP_KEY:?Supply a persistent APP_KEY or APP_KEY_FILE}"
            : "${APP_URL:?Supply the public HTTPS APP_URL}"
            : "${OPENBOOK_DOMAIN:?Supply the stable federation domain}"
            : "${DB_HOST:?Supply DB_HOST}"
            : "${DB_DATABASE:?Supply DB_DATABASE}"
            : "${DB_USERNAME:?Supply DB_USERNAME}"
        fi
        ;;
esac

# Initialize even an empty/bind-mounted storage volume, without changing uploads
# recursively on each start. Existing bind mounts must already have UID 33 access.
if [ "$(id -u)" = 0 ]; then
    for directory in storage storage/app storage/app/public storage/app/private \
        storage/framework storage/framework/cache storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
        install -d -o www-data -g www-data -m 755 "$directory"
    done
    case "${1:-}" in
        php|openbook-cron) exec gosu www-data docker-php-entrypoint "$@" ;;
    esac
fi

# Apache starts only after migrations and view-cache cleanup have succeeded.
if [ "${1:-}" = apache2-foreground ]; then
    startup_pid=
    stop_startup() {
        trap '' TERM INT WINCH
        if [ -n "$startup_pid" ]; then
            kill -TERM "$startup_pid" 2>/dev/null || true
            wait "$startup_pid" 2>/dev/null || true
        fi
        exit 143
    }
    trap stop_startup TERM INT WINCH
    if [ "$(id -u)" = 0 ]; then
        gosu www-data php /usr/local/lib/openbook/prepare.php &
    else
        php /usr/local/lib/openbook/prepare.php &
    fi
    startup_pid=$!
    status=0
    wait "$startup_pid" || status=$?
    trap - TERM INT WINCH
    if [ "$status" -ne 0 ]; then
        exit "$status"
    fi
fi

exec docker-php-entrypoint "$@"
