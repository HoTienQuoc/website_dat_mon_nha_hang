FROM php:8.5-cli-bookworm
ARG WWWUSER=1000
ARG WWWGROUP=1000
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql intl zip pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN groupadd --gid ${WWWGROUP} sail && useradd --uid ${WWWUSER} --gid ${WWWGROUP} --create-home sail
WORKDIR /var/www/html
USER sail
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
