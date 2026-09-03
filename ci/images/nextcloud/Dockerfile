# syntax=docker/dockerfile:1

ARG NODE_BASE
FROM ${NODE_BASE}

ARG SOURCE_URL=https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud

LABEL org.opencontainers.image.source="${SOURCE_URL}" \
      org.opencontainers.image.title="Maintenance Tracker Nextcloud CI" \
      org.opencontainers.image.description="Qualified Node/Docker client toolchain for disposable Nextcloud integration tests" \
      org.opencontainers.image.version="v1"

RUN set -eux; \
    apt-get update; \
    apt-get install --yes --no-install-recommends \
        ca-certificates \
        docker.io \
        git; \
    rm -rf /var/lib/apt/lists/*; \
    node --version; \
    docker --version; \
    git --version
