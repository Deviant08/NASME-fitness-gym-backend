# ════════════════════════════════════════
#  NASME GYM — PHP Backend Dockerfile
#  Stack: PHP 8.2-FPM + Nginx (Alpine)
# ════════════════════════════════════════

FROM php:8.2-fpm-alpine

# Install nginx
RUN apk add --no-cache nginx

# Install PHP MySQL extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Create directories nginx needs
RUN mkdir -p /run/nginx /var/log/nginx

# Copy ALL project files into web root
COPY . /var/www/html/

# Copy nginx config
COPY nginx.conf /etc/nginx/nginx.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/

# Copy startup script
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080

CMD ["/start.sh"]
