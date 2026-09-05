#!/bin/sh
# ════════════════════════════════════════
#  NASME GYM — Container Startup Script
#  Starts php-fpm first, then nginx
# ════════════════════════════════════════

echo "Starting PHP-FPM..."
php-fpm -D

echo "Waiting for PHP-FPM to be ready..."
sleep 1

echo "Starting Nginx..."
nginx -g "daemon off;"
