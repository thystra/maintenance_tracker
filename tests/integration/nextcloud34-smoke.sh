#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

set -Eeuo pipefail

database="${NC_SMOKE_DATABASE:-sqlite}"
case "$database" in
	sqlite|pgsql)
		;;
	*)
		echo "Unsupported NC_SMOKE_DATABASE: $database" >&2
		exit 2
		;;
esac

container="${NC_SMOKE_CONTAINER:-maintenance-tracker-nc34-${database}-smoke}"
image="${NC_SMOKE_IMAGE:-nextcloud:34.0.3-apache}"
network="${NC_SMOKE_NETWORK:-${container}-network}"
db_container="${NC_SMOKE_DB_CONTAINER:-${container}-db}"
postgres_image="${NC_SMOKE_POSTGRES_IMAGE:-postgres:17-alpine}"
admin_user="integration_admin"
admin_password="integration-test-only"
cleanup_user="cleanup_test"
cleanup_password="cleanup-test-only"
postgres_database="nextcloud"
postgres_user="nextcloud"
postgres_password="integration-postgres-only"
staging_root="$(mktemp -d)"
created_network=0
started_database=0

cleanup() {
	status=$?
	if [ "$status" -ne 0 ]; then
		docker logs --tail 120 "$container" 2>/dev/null || true
		if [ "$started_database" -eq 1 ]; then
			docker logs --tail 120 "$db_container" 2>/dev/null || true
		fi
	fi
	docker rm --force "$container" >/dev/null 2>&1 || true
	if [ "$started_database" -eq 1 ]; then
		docker rm --force "$db_container" >/dev/null 2>&1 || true
	fi
	if [ "$created_network" -eq 1 ]; then
		docker network rm "$network" >/dev/null 2>&1 || true
	fi
	rm -rf "$staging_root"
}
trap cleanup EXIT

assert_contains() {
	value=$1
	expected=$2
	label=$3
	if [[ "$value" != *"$expected"* ]]; then
		echo "${label}: expected ${expected}" >&2
		echo "$value" >&2
		exit 1
	fi
}

stage_app() {
	local target="$staging_root/maintenance_tracker"
	mkdir -p "$target"

	for path in appinfo lib templates img js css; do
		if [ -e "$path" ]; then
			cp -a "$path" "$target/"
		fi
	done
	cp -a LICENSE "$target/"

	test -f "$target/appinfo/info.xml"
	test -f "$target/lib/AppInfo/Application.php"
	test -f "$target/js/maintenance_tracker-main.mjs"
	test -f "$target/css/maintenance_tracker-main.css"
}

stage_app

docker rm --force "$container" >/dev/null 2>&1 || true
docker rm --force "$db_container" >/dev/null 2>&1 || true
docker network rm "$network" >/dev/null 2>&1 || true

run_args=(
	--rm
	--detach
	--name "$container"
	--env "NEXTCLOUD_ADMIN_USER=$admin_user"
	--env "NEXTCLOUD_ADMIN_PASSWORD=$admin_password"
)

if [ "$database" = 'sqlite' ]; then
	run_args+=(--env SQLITE_DATABASE=nextcloud)
else
	docker network create "$network" >/dev/null
	created_network=1

	docker run --rm --detach \
		--name "$db_container" \
		--network "$network" \
		--env "POSTGRES_DB=$postgres_database" \
		--env "POSTGRES_USER=$postgres_user" \
		--env "POSTGRES_PASSWORD=$postgres_password" \
		"$postgres_image" >/dev/null
	started_database=1

	db_ready=0
	for _attempt in $(seq 1 60); do
		if docker exec "$db_container" \
			pg_isready --quiet --username "$postgres_user" --dbname "$postgres_database"; then
			db_ready=1
			break
		fi
		sleep 1
	done
	if [ "$db_ready" -ne 1 ]; then
		echo 'PostgreSQL did not become ready within 60 seconds.' >&2
		exit 1
	fi

	run_args+=(
		--network "$network"
		--env "POSTGRES_DB=$postgres_database"
		--env "POSTGRES_USER=$postgres_user"
		--env "POSTGRES_PASSWORD=$postgres_password"
		--env "POSTGRES_HOST=$db_container"
	)
fi

docker run "${run_args[@]}" "$image" >/dev/null

ready=0
for _attempt in $(seq 1 90); do
	status=$(docker exec --user www-data "$container" \
		php occ status --output=json 2>/dev/null || true)
	if [[ "$status" == *'"installed":true'* ]] \
		|| [[ "$status" == *'"installed": true'* ]]; then
		http_status=$(docker exec "$container" curl --silent --show-error \
			'http://127.0.0.1/status.php' 2>/dev/null || true)
		if [[ "$http_status" == *'"installed":true'* ]]; then
			ready=1
			break
		fi
	fi
	sleep 2
done
if [ "$ready" -ne 1 ]; then
	echo 'Nextcloud did not become ready within 180 seconds.' >&2
	exit 1
fi

# The Forgejo workstation runners use a separate Docker daemon. `docker cp`
# transfers the staged application through the Docker API instead of assuming
# the daemon can bind-mount the job container's $PWD.
docker cp "$staging_root/maintenance_tracker" \
	"$container:/var/www/html/custom_apps/maintenance_tracker"
docker exec "$container" \
	chown -R www-data:www-data /var/www/html/custom_apps/maintenance_tracker

docker exec --user www-data "$container" \
	php occ app:enable maintenance_tracker >/dev/null

docker exec "$container" apachectl graceful >/dev/null 2>&1

capabilities=''
for _attempt in $(seq 1 30); do
	capabilities=$(docker exec "$container" curl --silent --show-error \
		--user "${admin_user}:${admin_password}" \
		--header 'OCS-APIRequest: true' \
		--header 'Accept: application/json' \
		'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/capabilities?format=json')
	if [[ "$capabilities" == *'"statuscode":200'* ]]; then
		break
	fi
	sleep 1
done
assert_contains "$capabilities" '"statuscode":200' 'capabilities'
assert_contains "$capabilities" '"cursor-pagination"' 'capabilities'

created=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"asset":{"uuid":"b913571d-5405-4a88-bb59-2d670a5f93dc","category":"vehicle","name":"Integration Test Truck","manufacturer":"Ford","model":"F-350","modelYear":2020,"purchasePriceMinor":6250000,"currency":"USD"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$created" '"statuscode":201' 'asset create'
assert_contains "$created" '"revision":1' 'asset create'

updated=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request PATCH \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"asset":{"notes":"Primary tow vehicle"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc?format=json')
assert_contains "$updated" '"statuscode":200' 'asset update'
assert_contains "$updated" '"revision":2' 'asset update'

stale=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request PATCH \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"asset":{"notes":"Stale write"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc?format=json')
assert_contains "$stale" '"statuscode":412' 'stale asset update'

archived=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request DELETE \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"expectedRevision":2}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc?format=json')
assert_contains "$archived" '"statuscode":200' 'asset archive'
assert_contains "$archived" '"revision":3' 'asset archive'

missing=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc?format=json')
assert_contains "$missing" '"statuscode":404' 'archived asset lookup'

docker exec --env OC_PASS="$cleanup_password" --user www-data "$container" \
	php occ user:add --password-from-env "$cleanup_user" >/dev/null

cleanup_created=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"asset":{"category":"tool","name":"Must not survive UID reuse"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$cleanup_created" '"statuscode":201' 'cleanup fixture create'

docker exec --user www-data "$container" \
	php occ user:delete "$cleanup_user" >/dev/null
docker exec --env OC_PASS="$cleanup_password" --user www-data "$container" \
	php occ user:add --password-from-env "$cleanup_user" >/dev/null

reused_uid=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$reused_uid" '"statuscode":200' 'reused UID asset list'
assert_contains "$reused_uid" '"items":[]' 'reused UID asset list'

page_status=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--output /dev/null \
	--write-out '%{http_code}' \
	'http://127.0.0.1/apps/maintenance_tracker/')
if [ "$page_status" != '200' ]; then
	echo "web page: expected 200, got ${page_status}" >&2
	exit 1
fi

app_errors=$(docker exec "$container" php -r '
	$count = 0;
	foreach (@file("/var/www/html/data/nextcloud.log") ?: [] as $line) {
		$entry = json_decode($line, true);
		if (
			is_array($entry)
			&& ($entry["level"] ?? 0) >= 3
			&& str_contains((string)($entry["url"] ?? ""), "maintenance_tracker")
		) {
			++$count;
		}
	}
	echo $count;
')
if [ "$app_errors" != '0' ]; then
	echo "Nextcloud logged ${app_errors} app error(s)." >&2
	exit 1
fi

echo "Nextcloud 34 smoke test passed (${database})."
