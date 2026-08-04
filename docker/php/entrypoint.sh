#!/bin/sh
set -e

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
fi

exec docker-php-entrypoint php-fpm
