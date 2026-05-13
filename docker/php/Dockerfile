FROM php:8.2-fpm

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Инсталираме нужните пакети
RUN apt-get update && apt-get install -y  \   
    git \
    unzip \
    zip \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip

WORKDIR /var/www/html