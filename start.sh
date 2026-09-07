#!/bin/sh
echo "=== Files in web root ==="
ls -la /var/www/html/ || true
echo "=== API folder ==="
ls -la /var/www/html/api/ || echo "api/ folder not found!"

PORT="${PORT:-8080}"
echo "Using PORT: $PORT"
sed -i "s/listen[[:space:]]*[^;]*;/listen 0.0.0.0:$PORT;/" /etc/nginx/nginx.conf
echo "=== nginx listen line ==="
grep listen /etc/nginx/nginx.conf || true

echo "Starting PHP-FPM..."
php-fpm -D
sleep 2
echo "Starting Nginx on 0.0.0.0:$PORT..."
exec nginx -g "daemon off;"
