#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

# Pre-pull the images a smoke or upgrade run needs, with retries and backoff,
# so pulling never counts against the Nextcloud readiness timeout. Uses the
# same environment as the smoke scripts (NC_SMOKE_IMAGE, NC_SMOKE_DATABASE,
# NC_SMOKE_BROWSER and the image overrides).

set -Eeuo pipefail
# shellcheck source=tests/integration/lib/docker-ready.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/docker-ready.sh"

images=("${NC_SMOKE_IMAGE:-nextcloud:34.0.5-apache}")
case "${NC_SMOKE_DATABASE:-sqlite}" in
	pgsql) images+=("$NC_SMOKE_POSTGRES_IMAGE") ;;
	mysql) images+=("$NC_SMOKE_MARIADB_IMAGE") ;;
esac
if [ "${NC_SMOKE_BROWSER:-0}" = '1' ]; then
	images+=("$NC_SMOKE_BROWSER_IMAGE")
fi
started=$SECONDS
for image in "${images[@]}"; do
	pull_image "$image"
done
ready_log "all images present after $((SECONDS - started))s"
