#!/bin/sh
# Спільний entrypoint: синхронізує залежності, прогріває кеш і запускає процес сервісу.
#
# Залежності ставимо ЗАВЖДИ, а не лише коли vendor/ відсутній: інакше після появи
# нового пакета в composer.json воркер крешить у циклі зі «Class ... not found».
# Кеш прогріваємо тут, щоб перший запит не тягнувся 40–90 с (це вже ламало e2e).
set -e

cd /app

if [ -f composer.json ]; then
    echo "[entrypoint] composer install…"
    composer install --no-interaction --no-progress --quiet
fi

if [ -f bin/console ]; then
    echo "[entrypoint] cache:warmup…"
    php bin/console cache:warmup --no-interaction >/dev/null 2>&1 || echo "[entrypoint] warmup пропущено"
fi

# Якщо контейнеру задали команду (воркери, тести) — виконуємо її замість веб-сервера.
if [ "$#" -gt 0 ]; then
    echo "[entrypoint] ${SERVICE_NAME:-service} → $*"
    exec "$@"
fi

PORT="${SERVICE_PORT:-80}"
echo "[entrypoint] ${SERVICE_NAME:-service} → php -S 0.0.0.0:${PORT} -t public"
exec php -S "0.0.0.0:${PORT}" -t public public/index.php
