#!/bin/sh
# ════════════════════════════════════════
#  NASME GYM — Container Startup Script
# ════════════════════════════════════════

echo "=== Files in web root ==="
ls -la /var/www/html/
echo "=== API folder ==="
ls -la /var/www/html/api/ 2>/dev/null || echo "api/ folder not found!"

echo "Starting PHP-FPM..."
php-fpm -D

echo "Waiting for PHP-FPM..."
sleep 2

# Confirm PHP-FPM is actually listening
echo "=== PHP-FPM status ==="
netstat -tlnp 2>/dev/null | grep 9000 || echo "PHP-FPM port check skipped"

echo "Starting Nginx on port 8080..."
nginx -g "daemon off;"
