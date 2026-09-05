# ════════════════════════════════════════
#  NASME GYM — PHP Backend Dockerfile
#  Tells Railway how to run the PHP API
# ════════════════════════════════════════

# Use official PHP with Apache web server
FROM php:8.2-apache

# Enable Apache mod_rewrite (needed for clean URLs)
RUN a2enmod rewrite

# Install the MySQL PHP extension
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copy all backend files into the server's web root
COPY . /var/www/html/

# Give Apache permission to read the files
RUN chown -R www-data:www-data /var/www/html/

# Tell Apache to allow .htaccess overrides
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# Railway assigns a PORT — tell Apache to use it
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf
RUN sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-enabled/000-default.conf

# Expose the port
EXPOSE ${PORT}

# Start Apache
CMD ["apache2-foreground"]
