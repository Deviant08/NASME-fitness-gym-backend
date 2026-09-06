#!/bin/sh
# ════════════════════════════════
#  NASME GYM — Container Startup Script
# ════════════════════════════════

set -e

echo "=== Files in web root ==="
ls -la /var/www/html/
echo "=== API folder ==="
ls -la /var/www/html/api/ 2>/dev/null || echo "api/ folder not found!"

# Railway injects PORT. Nginx cannot read env vars on its own.
PORT="${PORT:-8080}"
echo "Using PORT: $PORT"
sed -i "s/listen[[:space:]]*[^;]*;/listen $PORT;/" /etc/nginx/nginx.conf
echo "=== nginx listen line ==="
grep listen /etc/nginx/nginx.conf || true

echo "Starting PHP-FPM..."
php-fpm -D

echo "Waiting for PHP-FPM..."
sleep 2

echo "Starting Nginx on port $PORT..."
nginx -g "daemon off;"
