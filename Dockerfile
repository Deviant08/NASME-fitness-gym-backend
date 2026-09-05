# ════════════════════════════════════════
#  NASME GYM — PHP Backend Dockerfile
#  Fixed: removed duplicate MPM loading
# ════════════════════════════════════════

FROM php:8.2-apache

# Install MySQL PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable mod_rewrite for .htaccess support
RUN a2enmod rewrite

# Copy all backend files into Apache's web root
COPY . /var/www/html/

# Set correct file permissions
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/

# Allow .htaccess overrides in the web root
RUN sed -i 's|AllowOverride None|AllowOverride All|g' \
    /etc/apache2/apache2.conf

# Railway dynamically assigns a PORT — wire Apache to use it
RUN sed -i 's/Listen 80/Listen ${PORT:-80}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT:-80}>/' \
    /etc/apache2/sites-enabled/000-default.conf

EXPOSE 80

CMD ["apache2-foreground"]
