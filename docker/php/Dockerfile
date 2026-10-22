# Dev/test runtime for the Laravel API (PHP 8.5 + pdo_pgsql). Production: any PHP >= 8.4.1 host with the same extensions.
FROM php:8.5-cli
COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions pdo_pgsql pdo_sqlite gd intl zip bcmath pcntl exif
WORKDIR /app
