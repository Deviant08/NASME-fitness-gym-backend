FROM php:8.2-cli-alpine

RUN docker-php-ext-install pdo pdo_mysql mysqli

COPY . /var/www/html/
WORKDIR /var/www/html

RUN chmod +x /var/www/html/start.sh

EXPOSE 8080

CMD ["/var/www/html/start.sh"]
