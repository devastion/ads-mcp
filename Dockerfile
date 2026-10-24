FROM node:24-bookworm-slim AS node

FROM php:8.5-cli-bookworm

ARG UID=1000
ARG GID=1000

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql pcntl intl zip bcmath

RUN groupadd --gid "${GID}" app \
    && useradd --uid "${UID}" --gid app --create-home app \
    && mkdir -p /app/vendor /app/node_modules \
    && chown -R app:app /app

WORKDIR /app
USER app

COPY --chown=app:app docker/entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
