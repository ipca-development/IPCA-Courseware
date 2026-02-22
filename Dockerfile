FROM php:8.2-apache

# Enable Apache rewrite (optional)
RUN a2enmod rewrite

# Copy app into Apache docroot
COPY public/ /var/www/html/
COPY src/ /var/www/src/

# Ensure PHP can read env vars and sessions
RUN chown -R www-data:www-data /var/www/html /var/www/src

# Apache listens on 80 by default (App Platform will route traffic)
EXPOSE 80
