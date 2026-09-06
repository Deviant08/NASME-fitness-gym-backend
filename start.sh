#!/bin/sh
# ════════════════════════════════════════
#  NASME GYM — Container Startup Script
# ════════════════════════════════════════

echo "=== Files in web root ==="
ls -la /var/www/html/
echo "=== API folder ==="
ls -la /var/www/html/api/ 2>/dev/null || echo "api/ folder not found!"

# Substitute Railway PORT into nginx config
PORT=${PORT:-9000}
echo "Using PORT: $PORT"
sed -i "s|\${PORT:-9000}|$PORT|g" /etc/nginx/nginx.conf

echo "Starting PHP-FPM..."
php-fpm -D

echo "Waiting for PHP-FPM..."
sleep 2

echo "Starting Nginx on port $PORT..."
nginx -g "daemon off;"
