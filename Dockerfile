FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY . .
COPY config/config.example.php config/config.php

RUN chown -R www-data:www-data /var/www/html/public/uploads

EXPOSE 80