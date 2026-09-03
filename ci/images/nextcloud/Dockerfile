# syntax=docker/dockerfile:1

# Keep a valid pinned default for direct Dockerfile parsing/builds. The build
# script supplies this same reviewed identity explicitly.
ARG NODE_BASE=node:24.20.0-bookworm@sha256:be23f54a88d34e8824c741b19b91064094f92c1c97b194144bfc8b50d67258e2
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
