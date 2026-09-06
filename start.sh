#!/bin/sh
# ════════════════════════════════════════
#  NASME GYM — Container Startup Script
# ════════════════════════════════════════

echo "=== Files in web root ==="
ls -la /var/www/html/
echo "=== API folder ==="
ls -la /var/www/html/api/ 2>/dev/null || echo "api/ folder not found!"

# Use Railway PORT if set, otherwise default to 8080
NGINX_PORT=${PORT:-8080}
echo "Using PORT: $NGINX_PORT"

# Replace the placeholder in nginx.conf with the actual port number
sed -i "s/NGINX_PORT/$NGINX_PORT/" /etc/nginx/nginx.conf

echo "Starting PHP-FPM..."
php-fpm -D

echo "Waiting for PHP-FPM..."
sleep 2

echo "Starting Nginx on port $NGINX_PORT..."
nginx -g "daemon off;"
