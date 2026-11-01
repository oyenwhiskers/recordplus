#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p \
  storage/app/private \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

# Mounted volume for documents is storage/app/private — fix ownership after mount.
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

if [ ! -L public/storage ]; then
  php artisan storage:link --force >/dev/null 2>&1 || true
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
