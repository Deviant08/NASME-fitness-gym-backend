# ════════════════════════════════════════
#  NASME GYM — PHP Backend Dockerfile
#  Stack: PHP 8.2-FPM + Nginx (Alpine)
#  Avoids Apache MPM conflicts entirely
# ════════════════════════════════════════

FROM php:8.2-fpm-alpine

# ── Install nginx ──────────────────────
RUN apk add --no-cache nginx

# ── Install PHP extensions ─────────────
RUN docker-php-ext-install pdo pdo_mysql mysqli

# ── Create required directories ────────
RUN mkdir -p /run/nginx \
    && mkdir -p /var/www/html

# ── Copy project files ─────────────────
COPY . /var/www/html/

# ── Copy nginx config ──────────────────
COPY nginx.conf /etc/nginx/nginx.conf

# ── File permissions ───────────────────
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/

# ── Copy and set startup script ────────
COPY start.sh /start.sh
RUN chmod +x /start.sh

# Railway uses 8080 by default for web services
EXPOSE 8080

CMD ["/start.sh"]
