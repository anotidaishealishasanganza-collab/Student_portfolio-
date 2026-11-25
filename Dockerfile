FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    default-mysql-client \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV PORT=10000

RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY isrgrootx1.pem /etc/ssl/certs/isrgrootx1.pem

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 10000

CMD ["/bin/sh", "-c", "echo '--- AUSSH STARTUP DIAGNOSTICS ---'; pwd; ls -la /var/www/html; apache2ctl -t || exit 1; echo '--- STARTING APACHE ---'; exec apache2-foreground"]
