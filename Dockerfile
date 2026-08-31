FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev unzip git \
 && docker-php-ext-install pdo pdo_pgsql pcntl \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-interaction --no-progress --no-scripts

COPY . /app
RUN composer dump-autoload --optimize --no-interaction

# php -S is single threaded unless we ask for workers; the race tests need
# genuinely concurrent requests.
ENV PHP_CLI_SERVER_WORKERS=16

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
