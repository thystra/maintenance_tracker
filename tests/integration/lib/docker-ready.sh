# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

# Shared by nextcloud-smoke.sh, upgrade-smoke.sh and pull-images.sh: image
# defaults, retried image pulls, a Nextcloud readiness wait with progress and
# a diagnostics dump, and per-run resource names. Source it; it defines
# functions and defaults only.
#
#   NC_SMOKE_RUN_ID          short suffix that makes this run's container and
#                            network names unique (default: 8 hex characters
#                            hashed from the CI run id, job, attempt and a random
#                            part; recorded in full in the smoke-ci label)
#   NC_SMOKE_DB_ALIAS        DNS name of the database on the per-run network
#                            (default db)
#
#   NC_SMOKE_READY_TIMEOUT   seconds to wait for the first install (default 600)
#   NC_SMOKE_START_ATTEMPTS  container starts before giving up (default 2)
#   NC_SMOKE_PULL_ATTEMPTS   docker pull attempts per image (default 4)
#   NC_SMOKE_PULL_BACKOFF    first retry delay in seconds, doubled each time (default 10)

: "${NC_SMOKE_POSTGRES_IMAGE:=postgres:17-alpine}"
: "${NC_SMOKE_MARIADB_IMAGE:=mariadb:11.4}"
: "${NC_SMOKE_BROWSER_IMAGE:=mcr.microsoft.com/playwright:v1.64.1-noble@sha256:7852dc5a7d72f5fe458a597d690f6c42fbc8e9e26ddfa95f8e6ef552558d9bbf}"
: "${NC_SMOKE_READY_TIMEOUT:=600}"
: "${NC_SMOKE_START_ATTEMPTS:=2}"
: "${NC_SMOKE_PULL_ATTEMPTS:=4}"
: "${NC_SMOKE_PULL_BACKOFF:=10}"

# Parallel CI jobs share one Docker daemon, so fixed names collide (and an
# old "remove leftovers" step could kill another job's containers). Every
# resource a run creates carries its run id in the name and in this label, and
# cleanup only ever touches resources with this run's label.
smoke_label_key=org.argentwolf.maintenance-tracker.smoke-run
# The run id stays short: names built from it double as DNS names, and a DNS
# label is at most 63 characters (a 65-character database name made every CI
# database job fail with "Name or service not known").
smoke_ci_context="${GITHUB_RUN_ID:-local}-${GITHUB_JOB:-job}-${GITHUB_RUN_ATTEMPT:-1}"
if [ -z "${NC_SMOKE_RUN_ID:-}" ]; then
	NC_SMOKE_RUN_ID=$(printf '%s-%s-%s' "$smoke_ci_context" "$$" "$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')" | sha256sum | cut -c1-8)
fi
NC_SMOKE_RUN_ID=$(printf '%s' "$NC_SMOKE_RUN_ID" | tr 'A-Z' 'a-z' | tr -c 'a-z0-9-' '-')
smoke_label="${smoke_label_key}=${NC_SMOKE_RUN_ID}"
: "${NC_SMOKE_DB_ALIAS:=db}"

# assert_dns_name NAME...: fail fast unless each name is a valid DNS label
# (1-63 letters, digits and hyphens, not starting or ending with a hyphen).
assert_dns_name() {
	local name
	for name in "$@"; do
		if [ "${#name}" -gt 63 ]; then
			echo "Docker name '${name}' is ${#name} characters; names used as hostnames must be at most 63 (shorten NC_SMOKE_RUN_ID or the NC_SMOKE_*_CONTAINER override)." >&2
			return 1
		fi
		if ! [[ "$name" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$ ]]; then
			echo "Docker name '${name}' is not a valid hostname (letters, digits and inner hyphens only)." >&2
			return 1
		fi
	done
}

# ensure_run_network NAME: create the network with this run's label, or reuse
# a leftover only if it carries this run's label; never touch anyone else's.
ensure_run_network() {
	local name=$1 owner
	if owner=$(docker network inspect --format "{{index .Labels \"${smoke_label_key}\"}}" "$name" 2>/dev/null); then
		if [ "$owner" = "$NC_SMOKE_RUN_ID" ]; then
			ready_log "reusing network ${name} left over by this run"
			return 0
		fi
		ready_log "network ${name} already exists and belongs to another run (${owner:-unlabelled}); not touching it"
		return 1
	fi
	docker network create --label "$smoke_label" --label "${smoke_label_key}-ci=${smoke_ci_context}" "$name" >/dev/null
}

# remove_run_resources: remove every container (with its anonymous volumes)
# and network that carries this run's label, and nothing else.
remove_run_resources() {
	local ids
	ids=$(docker ps -aq --filter "label=${smoke_label}" 2>/dev/null || true)
	if [ -n "$ids" ]; then
		# shellcheck disable=SC2086
		docker rm --force --volumes $ids >/dev/null 2>&1 || true
	fi
	ids=$(docker network ls -q --filter "label=${smoke_label}" 2>/dev/null || true)
	if [ -n "$ids" ]; then
		# shellcheck disable=SC2086
		docker network rm $ids >/dev/null 2>&1 || true
	fi
}

ready_log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }

# pull_image IMAGE: pull unless already present, retrying with exponential
# backoff, so a slow or flaky registry never eats into the readiness timeout.
pull_image() {
	local image=$1 attempt=1 delay=$NC_SMOKE_PULL_BACKOFF started=$SECONDS
	if docker image inspect "$image" >/dev/null 2>&1; then
		ready_log "image ${image} already present"
		return 0
	fi
	while :; do
		ready_log "pulling ${image} (attempt ${attempt}/${NC_SMOKE_PULL_ATTEMPTS})"
		if docker pull --quiet "$image" >/dev/null; then
			ready_log "pulled ${image} in $((SECONDS - started))s"
			return 0
		fi
		if [ "$attempt" -ge "$NC_SMOKE_PULL_ATTEMPTS" ]; then
			ready_log "giving up on ${image} after ${attempt} attempts ($((SECONDS - started))s)"
			return 1
		fi
		ready_log "pull of ${image} failed; retrying in ${delay}s"
		sleep "$delay"
		attempt=$((attempt + 1))
		delay=$((delay * 2))
	done
}

# dump_nextcloud_diagnostics CONTAINER [DB_CONTAINER]: what a failed start
# or a failed suite looked like, for the CI log. Sections that cannot be
# collected say why instead of printing nothing.
dump_nextcloud_diagnostics() {
	local container=$1 db_container=${2:-} state
	{
		echo "===== docker ps -a (run ${NC_SMOKE_RUN_ID}) ====="
		docker ps -a --filter "label=${smoke_label}" --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.RunningFor}}' 2>&1 || true
		echo '===== docker ps -a (all, for contention) ====='
		docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.RunningFor}}' 2>&1 || true
		state=$(docker inspect --format 'status={{.State.Status}} running={{.State.Running}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} started={{.State.StartedAt}} error={{.State.Error}}' "$container" 2>/dev/null || true)
		echo "===== ${container} state ====="
		if [ -z "$state" ]; then
			echo "(container ${container} does not exist: it never started, or it exited and was auto-removed, so there are no logs to show)"
		else
			echo "$state"
		fi
		echo "===== ${container} status.php ====="
		if [[ "$state" == *'running=true'* ]]; then
			docker exec "$container" curl --silent --show-error --max-time 10 'http://127.0.0.1/status.php' 2>&1 || true
			echo
			echo "===== ${container} occ status ====="
			docker exec --user www-data "$container" php occ status 2>&1 | head -n 20 || true
		else
			echo '(skipped: container is not running)'
		fi
		echo "===== ${container} container log (last 200 lines) ====="
		if [ -n "$state" ]; then
			docker logs --tail 200 "$container" 2>&1 || true
		else
			echo '(none: container does not exist)'
		fi
		echo "===== ${container} nextcloud.log (last 120 lines) ====="
		if [[ "$state" == *'running=true'* ]]; then
			docker exec --user www-data "$container" sh -c 'if test -f /var/www/html/data/nextcloud.log; then tail -n 120 /var/www/html/data/nextcloud.log | cut -c1-800; else echo "(no nextcloud.log yet: Nextcloud has not written one)"; fi' 2>&1 || true
		else
			echo '(skipped: container is not running)'
		fi
		if [ -n "$db_container" ]; then
			echo "===== ${db_container} container log (last 80 lines) ====="
			if docker inspect "$db_container" >/dev/null 2>&1; then
				docker logs --tail 80 "$db_container" 2>&1 || true
			else
				echo "(container ${db_container} does not exist: it never started or was auto-removed)"
			fi
		fi
		echo '===== end of diagnostics ====='
	} >&2
}

# wait_for_nextcloud CONTAINER [DB_CONTAINER]: wait until both `occ status`
# and status.php report an installed instance, printing progress every 30s.
# Returns 1 (after dumping diagnostics) on timeout or if the container stops.
wait_for_nextcloud() {
	local container=$1 db_container=${2:-} started=$SECONDS next_note=30 elapsed occ_status http_status state
	ready_log "waiting up to ${NC_SMOKE_READY_TIMEOUT}s for Nextcloud in ${container} to install and start"
	while :; do
		elapsed=$((SECONDS - started))
		occ_status=$(docker exec --user www-data "$container" php occ status --output=json 2>/dev/null || true)
		if [[ "$occ_status" == *'"installed":true'* ]] || [[ "$occ_status" == *'"installed": true'* ]]; then
			http_status=$(docker exec "$container" curl --silent --max-time 10 'http://127.0.0.1/status.php' 2>/dev/null || true)
			if [[ "$http_status" == *'"installed":true'* ]]; then
				ready_log "Nextcloud ready after ${elapsed}s"
				return 0
			fi
		fi
		state=$(docker inspect --format '{{.State.Status}}' "$container" 2>/dev/null || echo missing)
		if [ "$state" != running ]; then
			ready_log "container ${container} is ${state} after ${elapsed}s"
			dump_nextcloud_diagnostics "$container" "$db_container"
			return 1
		fi
		if [ "$elapsed" -ge "$NC_SMOKE_READY_TIMEOUT" ]; then
			ready_log "Nextcloud did not become ready within ${NC_SMOKE_READY_TIMEOUT} seconds"
			dump_nextcloud_diagnostics "$container" "$db_container"
			return 1
		fi
		if [ "$elapsed" -ge "$next_note" ]; then
			ready_log "still waiting (${elapsed}s): $(docker logs --tail 1 "$container" 2>&1 | cut -c1-160)"
			next_note=$((next_note + 30))
		fi
		sleep 2
	done
}
