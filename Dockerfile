FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libxml2-dev libonig-dev unzip git \
    && docker-php-ext-install pdo_sqlite dom mbstring \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/api
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts
COPY src ./src
COPY public ./public
COPY bin ./bin
COPY LICENSE ./LICENSE
RUN useradd -u 10001 -m api && mkdir -p /srv/api/var && chown api:api /srv/api/var
USER api
ENV IBUPROFEN_DATA_DIR=/srv/api/var
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
