# syntax=docker/dockerfile:1

# Defaults are pinned so the Dockerfile is valid when parsed or built directly.
# scripts/build-ci-images.sh still supplies the reviewed base explicitly for
# each image (including the PHP 8.2 override).
ARG NODE_BASE=node:24.20.0-bookworm@sha256:be23f54a88d34e8824c741b19b91064094f92c1c97b194144bfc8b50d67258e2
ARG COMPOSER_BASE=composer:2.10.3@sha256:4d045ea9f71d5d111a95e608400da61d187e487adf9eaf2dfe068998a8d4f584
ARG PHP_BASE=php:8.5.5-cli-bookworm@sha256:36664e49e4bdc669d9e2cba2e708c288b7fafade442dd1cd3c30d6cb97370b82

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

# The pinned official PHP images provide the required core extensions.
# Do not rebuild them here; the qualification command below is the contract
# check for each exact image identity.
RUN set -eux; \
    apt-get update; \
    apt-get install --yes --no-install-recommends \
        git \
        unzip; \
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
