# syntax=docker/dockerfile:1

ARG NODE_BASE
ARG COMPOSER_BASE
ARG PHP_BASE

FROM ${NODE_BASE} AS node_toolchain
FROM ${COMPOSER_BASE} AS composer_toolchain
FROM ${PHP_BASE}

ARG PHP_SERIES
ARG SOURCE_URL=https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud

LABEL org.opencontainers.image.source="${SOURCE_URL}" \
      org.opencontainers.image.title="Maintenance Tracker PHP CI" \
      org.opencontainers.image.description="Qualified PHP/Composer toolchain for Maintenance Tracker Forgejo CI" \
      org.opencontainers.image.version="${PHP_SERIES}-v1"

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN set -eux; \
    apt-get update; \
    apt-get install --yes --no-install-recommends \
        git \
        libonig-dev \
        libxml2-dev \
        unzip; \
    docker-php-ext-install -j"$(nproc)" dom mbstring xml xmlwriter; \
    rm -rf /var/lib/apt/lists/*

# Forgejo JavaScript actions (including checkout) require a Node runtime in a
# job container. Routine PHP jobs do not need npm, so copy only the pinned Node
# binary rather than the full Node image filesystem.
COPY --from=node_toolchain /usr/local/bin/node /usr/local/bin/node
COPY --from=composer_toolchain /usr/bin/composer /usr/local/bin/composer

RUN set -eux; \
    php --version; \
    node --version; \
    composer --version; \
    php -r 'foreach (["dom", "libxml", "mbstring", "xml", "xmlwriter"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: {$ext}\\n"); exit(1); } }'
