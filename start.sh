#!/bin/sh
set -eu
PORT="${PORT:-8080}"
cd /var/www/html
echo "=== web root ==="
ls -la
echo "=== api ==="
ls -la api || echo "api folder missing"
echo "PHP version: $(php -v | head -n 1)"
echo "Starting PHP server on 0.0.0.0:${PORT}"
exec php -S "0.0.0.0:${PORT}" -t /var/www/html /var/www/html/router.php
