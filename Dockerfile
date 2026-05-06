FROM php:8.2-apache

# System deps + PHP extensions (MySQL) + OCR tools
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libmariadb-dev \
      tesseract-ocr \
      tesseract-ocr-eng \
      procps \
 && docker-php-ext-install pdo_mysql mysqli \
 && docker-php-ext-enable pdo_mysql mysqli \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/ipca-uploads.ini

COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY vendor/ /var/www/vendor/
RUN chown -R www-data:www-data /var/www/html /var/www/src /var/www/vendor

EXPOSE 80
