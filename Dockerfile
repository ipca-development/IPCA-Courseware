FROM php:8.2-apache

# System deps + PHP extensions (MySQL)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libmariadb-dev \
 && docker-php-ext-install pdo_mysql mysqli \
 && docker-php-ext-enable pdo_mysql mysqli \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY public/ /var/www/html/
COPY src/ /var/www/src/
RUN chown -R www-data:www-data /var/www/html /var/www/src

EXPOSE 80
