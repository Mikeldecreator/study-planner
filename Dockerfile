FROM php:8.2-apache

# Install system dependencies & PHP extensions required by application and web-push
RUN apt-get update && apt-get install -y --no-install-recommends \
    libgmp-dev \
    unzip \
    && docker-php-ext-install pdo_mysql bcmath gmp \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install Composer dependencies
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

# Copy application source
COPY . .

# Ensure production config uses environment-backed template
COPY config/config.example.php config/config.php

# Configure Apache virtual host with /api and /assets aliasing & directory protection
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Setup entrypoint script
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80
