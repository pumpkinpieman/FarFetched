#!/usr/bin/env bash
set -euo pipefail

# FarFetched entrypoint.
# Prepares writable state dirs, starts cron in the background, then hands off
# to the container CMD (apache2-foreground) as PID 1's child in foreground.

PRIVATE_DIR="/var/www/html/private"
DOWNLOAD_DIR="${FETCHER_DOWNLOAD_DIR:-/downloads}"

# Ensure the persistent dirs exist and are owned by the web/cron user.
mkdir -p "$PRIVATE_DIR" "$DOWNLOAD_DIR"
chown -R www-data:www-data "$PRIVATE_DIR" || true
# Downloads may be a host bind-mount with foreign ownership; try, don't fail.
chown -R www-data:www-data "$DOWNLOAD_DIR" 2>/dev/null || true
chmod 0775 "$DOWNLOAD_DIR" 2>/dev/null || true

# cron needs the env vars too (cron strips the container environment),
# so persist the ones the worker reads into a file the crontab sources.
{
  echo "FETCHER_DOWNLOAD_DIR=${FETCHER_DOWNLOAD_DIR:-/downloads}"
  echo "FETCHER_DOWNLOAD_DELAY=${FETCHER_DOWNLOAD_DELAY:-120}"
} > /etc/fetcher.env
chmod 0644 /etc/fetcher.env

# Start cron daemon (worker is invoked from /etc/cron.d/fetcher). This must
# never abort the entrypoint: if cron won't start, we still want the web UI up.
service cron start || /usr/sbin/cron || echo "[entrypoint] WARNING: cron did not start; downloads won't run, but the web UI will."

echo "[entrypoint] cron started; downloads -> ${DOWNLOAD_DIR}; delay=${FETCHER_DOWNLOAD_DELAY:-120}s"
echo "[entrypoint] launching: $*"

exec "$@"
