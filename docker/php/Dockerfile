FROM php:8.4-cli-alpine

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql pdo_pgsql pcov intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo "memory_limit=1G" > /usr/local/etc/php/conf.d/prism.ini

WORKDIR /app
