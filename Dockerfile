# syntax=docker/dockerfile:1.7

FROM dunglas/frankenphp:1-php8.5-bookworm AS frankenphp_upstream

FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    acl \
    file \
    gettext \
    git \
    watchman \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    install-php-extensions \
        @composer \
        pdo_sqlite \
        intl \
        opcache \
        zip

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY docker/frankenphp/worker.Caddyfile /etc/caddy/worker.Caddyfile
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1

FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
    install-php-extensions xdebug

RUN apt-get update && apt-get install -y --no-install-recommends \
    inotify-tools \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/app.dev.ini /usr/local/etc/php/conf.d/app.dev.ini
COPY docker/frankenphp/watch-and-restart.sh /usr/local/bin/watch-and-restart.sh
RUN chmod +x /usr/local/bin/watch-and-restart.sh

COPY docker/frankenphp/docker-entrypoint-dev.sh /usr/local/bin/docker-entrypoint-dev.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-dev.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-dev.sh"]

FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY composer.* symfony.* ./
RUN set -eux; \
    composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY . ./

RUN set -eux; \
    mkdir -p var/cache var/log data; \
    touch data/research; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    chmod +x bin/console

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
