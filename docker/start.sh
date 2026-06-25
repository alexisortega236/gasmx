#!/usr/bin/env sh
set -eu

: "${PORT:=10000}"

mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" \
  /etc/apache2/sites-available/000-default.conf

echo "Running migrations..."
php artisan migrate --force

echo "Caching Laravel configuration..."
php artisan config:cache

exec apache2-foreground
