#!/bin/sh
# Спільний entrypoint: ставить залежності при першому старті й піднімає вбудований веб-сервер.
set -e

cd /app

if [ ! -d vendor ]; then
    echo "[entrypoint] vendor/ відсутній — composer install…"
    composer install --no-interaction --no-progress
fi

PORT="${SERVICE_PORT:-80}"
echo "[entrypoint] ${SERVICE_NAME:-service} → php -S 0.0.0.0:${PORT} -t public"
exec php -S "0.0.0.0:${PORT}" -t public public/index.php
