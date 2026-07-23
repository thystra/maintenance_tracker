#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

set -Eeuo pipefail

container="${NC_SMOKE_CONTAINER:-maintenance-tracker-nc34-smoke}"
image="${NC_SMOKE_IMAGE:-nextcloud:34-apache}"
admin_user="integration_admin"
admin_password="integration-test-only"
cleanup_user="cleanup_test"
cleanup_password="cleanup-test-only"

cleanup() {
	status=$?
	if [ "$status" -ne 0 ]; then
		docker logs --tail 120 "$container" 2>/dev/null || true
	fi
	docker stop "$container" >/dev/null 2>&1 || true
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

docker run --rm --detach \
	--name "$container" \
	--env SQLITE_DATABASE=nextcloud \
	--env NEXTCLOUD_ADMIN_USER="$admin_user" \
	--env NEXTCLOUD_ADMIN_PASSWORD="$admin_password" \
	--volume "$PWD:/var/www/html/custom_apps/maintenance_tracker:ro" \
	"$image" >/dev/null

ready=0
for _attempt in $(seq 1 60); do
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
	echo 'Nextcloud did not become ready within 120 seconds.' >&2
	exit 1
fi

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

echo 'Nextcloud 34 smoke test passed.'
