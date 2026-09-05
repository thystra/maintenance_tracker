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
collab_user="collab_test"
collab_password="collab-test-only"
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

assert_not_contains() {
	value=$1
	unexpected=$2
	label=$3
	if [[ "$value" == *"$unexpected"* ]]; then
		echo "${label}: did not expect ${unexpected}" >&2
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

assert_contains "$capabilities" '"custom-categories"' 'capabilities'
assert_contains "$capabilities" '"component-instances"' 'capabilities'
assert_contains "$capabilities" '"typed-asset-relationships"' 'capabilities'
assert_contains "$capabilities" '"effective-dated-assignments"' 'capabilities'
assert_contains "$capabilities" '"capability-authorization"' 'capabilities'
assert_contains "$capabilities" '"workspace-membership"' 'capabilities'
assert_contains "$capabilities" '"append-only-audit"' 'capabilities'

categories=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/categories?format=json')
assert_contains "$categories" '"key":"vehicle"' 'category list'

custom_category=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"category":{"key":"marine","name":"Marine","defaultAssetClass":"equipment"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/categories?format=json')
assert_contains "$custom_category" '"statuscode":201' 'custom category create'
assert_contains "$custom_category" '"key":"marine"' 'custom category create'

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

assert_contains "$created" '"assetClass":"vehicle"' 'asset create class'

component=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"component":{"name":"Primary fuel filter","type":"fuel_filter","partNumber":"OEM-PRIMARY"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/components?format=json')
assert_contains "$component" '"statuscode":201' 'component create'
assert_contains "$component" '"type":"fuel_filter"' 'component create'

component_uuid=$(printf '%s' "$component" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["ocs"]["data"]["uuid"] ?? "";')
test -n "$component_uuid"

specification=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data "{\"specification\":{\"componentUuid\":\"${component_uuid}\",\"key\":\"filter.part_number\",\"label\":\"OEM part number\",\"value\":\"OEM-PRIMARY\",\"source\":{\"type\":\"manual\",\"reference\":\"integration test\"}}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/specifications?format=json')
assert_contains "$specification" '"statuscode":201' 'specification create'
assert_contains "$specification" '"key":"filter.part_number"' 'specification create'

component_list=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/components?format=json')
assert_contains "$component_list" '"Primary fuel filter"' 'component list'

specification_list=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/specifications?format=json')
assert_contains "$specification_list" '"OEM part number"' 'specification list'

relationship_types=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationship-types?format=json')
assert_contains "$relationship_types" '"key":"tows"' 'relationship type list'
assert_contains "$relationship_types" '"inverseKey":"towed_by"' 'relationship type list'

trailer=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"asset":{"uuid":"c024682e-6516-4b99-8c6a-3e781b6fa4ed","category":"other","assetClass":"trailer","name":"Integration Cargo Trailer"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$trailer" '"statuscode":201' 'trailer create'
assert_contains "$trailer" '"assetClass":"trailer"' 'trailer create'

boat=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"asset":{"uuid":"d135793f-7627-4caa-9d7b-4f892c70b5fe","category":"marine","name":"Integration Boat"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$boat" '"statuscode":201' 'boat create'
assert_contains "$boat" '"assetClass":"equipment"' 'boat create'

tow_relationship=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"relationship":{"uuid":"e2468a40-8738-4dbb-8e8c-509a3d81c60f","sourceAssetUuid":"b913571d-5405-4a88-bb59-2d670a5f93dc","targetAssetUuid":"c024682e-6516-4b99-8c6a-3e781b6fa4ed","type":"tows","context":"trip","isDefault":true}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_contains "$tow_relationship" '"statuscode":201' 'tow relationship create'
assert_contains "$tow_relationship" '"type":"tows"' 'tow relationship create'
assert_contains "$tow_relationship" '"isDefault":true' 'tow relationship create'

tow_relationship_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"relationship":{"uuid":"e2468a40-8738-4dbb-8e8c-509a3d81c60f","sourceAssetUuid":"b913571d-5405-4a88-bb59-2d670a5f93dc","targetAssetUuid":"c024682e-6516-4b99-8c6a-3e781b6fa4ed","type":"tows","context":"trip","isDefault":true}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_contains "$tow_relationship_retry" '"statuscode":201' 'tow relationship idempotent retry'
assert_contains "$tow_relationship_retry" '"uuid":"e2468a40-8738-4dbb-8e8c-509a3d81c60f"' 'tow relationship idempotent retry'

carry_relationship=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"relationship":{"uuid":"f3579b51-9849-4ecc-9f9d-61ab4e92d710","sourceAssetUuid":"c024682e-6516-4b99-8c6a-3e781b6fa4ed","targetAssetUuid":"d135793f-7627-4caa-9d7b-4f892c70b5fe","type":"carries","context":"trip"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_contains "$carry_relationship" '"statuscode":201' 'carry relationship create'
assert_contains "$carry_relationship" '"type":"carries"' 'carry relationship create'

incompatible_relationship=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"relationship":{"sourceAssetUuid":"b913571d-5405-4a88-bb59-2d670a5f93dc","targetAssetUuid":"d135793f-7627-4caa-9d7b-4f892c70b5fe","type":"tows"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_contains "$incompatible_relationship" '"statuscode":400' 'incompatible relationship rejection'

updated_relationship=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request PATCH \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"relationship":{"context":"fuel","notes":"Default towing configuration"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships/e2468a40-8738-4dbb-8e8c-509a3d81c60f?format=json')
assert_contains "$updated_relationship" '"statuscode":200' 'relationship update'
assert_contains "$updated_relationship" '"revision":2' 'relationship update'
assert_contains "$updated_relationship" '"context":"fuel"' 'relationship update'

assignment=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"assignment":{"uuid":"a468ac62-a95a-4fdd-8aae-72bc5fa3e821","sourceAssetUuid":"b913571d-5405-4a88-bb59-2d670a5f93dc","targetAssetUuid":"c024682e-6516-4b99-8c6a-3e781b6fa4ed","type":"tows","context":"trip","isPrimary":true,"effectiveFrom":"2026-09-01"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments?format=json')
assert_contains "$assignment" '"statuscode":201' 'assignment create'
assert_contains "$assignment" '"isPrimary":true' 'assignment create'

assignment_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"assignment":{"uuid":"a468ac62-a95a-4fdd-8aae-72bc5fa3e821","sourceAssetUuid":"b913571d-5405-4a88-bb59-2d670a5f93dc","targetAssetUuid":"c024682e-6516-4b99-8c6a-3e781b6fa4ed","type":"tows","context":"trip","isPrimary":true,"effectiveFrom":"2026-09-01"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments?format=json')
assert_contains "$assignment_retry" '"statuscode":201' 'assignment idempotent retry'
assert_contains "$assignment_retry" '"uuid":"a468ac62-a95a-4fdd-8aae-72bc5fa3e821"' 'assignment idempotent retry'

assignment_overlap=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"assignment":{"sourceAssetUuid":"b913571d-5405-4a88-bb59-2d670a5f93dc","targetAssetUuid":"c024682e-6516-4b99-8c6a-3e781b6fa4ed","type":"tows","context":"trip","isPrimary":true,"effectiveFrom":"2026-09-15"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments?format=json')
assert_contains "$assignment_overlap" '"statuscode":400' 'overlapping primary assignment rejection'

updated_assignment=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request PATCH \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"assignment":{"effectiveUntil":"2026-12-31","notes":"Fall towing assignment"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments/a468ac62-a95a-4fdd-8aae-72bc5fa3e821?format=json')
assert_contains "$updated_assignment" '"statuscode":200' 'assignment update'
assert_contains "$updated_assignment" '"revision":2' 'assignment update'
assert_contains "$updated_assignment" '"effectiveUntil":"2026-12-31"' 'assignment update'

relationship_list=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_contains "$relationship_list" '"e2468a40-8738-4dbb-8e8c-509a3d81c60f"' 'relationship list'
assert_contains "$relationship_list" '"f3579b51-9849-4ecc-9f9d-61ab4e92d710"' 'relationship list'

assignment_list=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments?format=json')
assert_contains "$assignment_list" '"a468ac62-a95a-4fdd-8aae-72bc5fa3e821"' 'assignment list'

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

relationship_history=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships/e2468a40-8738-4dbb-8e8c-509a3d81c60f?format=json')
assert_contains "$relationship_history" '"statuscode":200' 'relationship history after asset archive'
assert_contains "$relationship_history" '"archived":true' 'relationship history after asset archive'

assignment_history=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments/a468ac62-a95a-4fdd-8aae-72bc5fa3e821?format=json')
assert_contains "$assignment_history" '"statuscode":200' 'assignment history after asset archive'
assert_contains "$assignment_history" '"archived":true' 'assignment history after asset archive'

archived_relationship=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request DELETE \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"expectedRevision":2}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships/e2468a40-8738-4dbb-8e8c-509a3d81c60f?format=json')
assert_contains "$archived_relationship" '"statuscode":200' 'relationship archive'
assert_contains "$archived_relationship" '"revision":3' 'relationship archive'

archived_assignment=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request DELETE \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"expectedRevision":2}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments/a468ac62-a95a-4fdd-8aae-72bc5fa3e821?format=json')
assert_contains "$archived_assignment" '"statuscode":200' 'assignment archive'
assert_contains "$archived_assignment" '"revision":3' 'assignment archive'

post_archive_relationships=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_not_contains "$post_archive_relationships" '"e2468a40-8738-4dbb-8e8c-509a3d81c60f"' 'relationship active list after archive'
assert_contains "$post_archive_relationships" '"f3579b51-9849-4ecc-9f9d-61ab4e92d710"' 'relationship active list after archive'

post_archive_assignments=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments?format=json')
assert_not_contains "$post_archive_assignments" '"a468ac62-a95a-4fdd-8aae-72bc5fa3e821"' 'assignment active list after archive'

# Qualify real multi-user capability boundaries and shared-workspace lifecycle.
docker exec --env OC_PASS="$collab_password" --user www-data "$container" \
	php occ user:add --password-from-env "$collab_user" >/dev/null

admin_workspaces=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/workspaces?format=json')
assert_contains "$admin_workspaces" '"statuscode":200' 'owner workspace list'
admin_workspace_uuid=$(docker exec --env RESPONSE="$admin_workspaces" "$container" \
	php -r '$d=json_decode(getenv("RESPONSE"),true); echo $d["ocs"]["data"]["items"][0]["uuid"] ?? "";')
if [[ ! "$admin_workspace_uuid" =~ ^[0-9a-f-]{36}$ ]]; then
	echo "Could not resolve the owner personal workspace UUID: $admin_workspace_uuid" >&2
	exit 1
fi

member_added=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data "{\"member\":{\"userUid\":\"${collab_user}\",\"role\":\"contributor\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/workspaces/${admin_workspace_uuid}/members?format=json")
assert_contains "$member_added" '"statuscode":201' 'contributor membership create'
assert_contains "$member_added" '"role":"contributor"' 'contributor membership create'

collab_workspaces=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/workspaces?format=json')
assert_contains "$collab_workspaces" "\"uuid\":\"${admin_workspace_uuid}\"" 'contributor shared workspace visibility'
assert_contains "$collab_workspaces" '"role":"contributor"' 'contributor shared workspace role'

collab_assets=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$collab_assets" '"statuscode":200' 'contributor inventory read'

collab_write_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"asset":{"name":"Contributor must not create"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$collab_write_denied" '"statuscode":403' 'contributor inventory write rejection'

collab_audit_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/audit?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$collab_audit_denied" '"statuscode":403' 'contributor audit rejection'

member_promoted=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request PATCH \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"member":{"role":"manager"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/workspaces/${admin_workspace_uuid}/members/${collab_user}?format=json")
assert_contains "$member_promoted" '"statuscode":200' 'manager promotion'
assert_contains "$member_promoted" '"role":"manager"' 'manager promotion'

shared_asset_uuid='8d6d399f-8a39-4d84-9bd9-57a84e6a7aec'
manager_created=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data "{\"asset\":{\"uuid\":\"${shared_asset_uuid}\",\"category\":\"other\",\"name\":\"Manager-created shared asset\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$manager_created" '"statuscode":201' 'manager inventory create'
assert_contains "$manager_created" "\"uuid\":\"${shared_asset_uuid}\"" 'manager inventory create'

manager_members=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/workspaces/${admin_workspace_uuid}/members?format=json")
assert_contains "$manager_members" '"statuscode":200' 'manager membership read'
assert_contains "$manager_members" "\"userUid\":\"${collab_user}\"" 'manager membership read'

manager_audit=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/audit?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$manager_audit" '"statuscode":200' 'manager audit read'
assert_contains "$manager_audit" '"eventType":"asset.created"' 'manager audit domain event'
assert_contains "$manager_audit" "\"actorUid\":\"${collab_user}\"" 'manager audit actor attribution'
assert_contains "$manager_audit" "\"subjectId\":\"${shared_asset_uuid}\"" 'manager audit subject attribution'

manager_membership_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--request PATCH \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"member":{"role":"viewer"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/workspaces/${admin_workspace_uuid}/members/${collab_user}?format=json")
assert_contains "$manager_membership_denied" '"statuscode":403' 'manager membership administration rejection'

docker exec --user www-data "$container" php occ user:delete "$collab_user" >/dev/null

members_after_delete=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/workspaces/${admin_workspace_uuid}/members?format=json")
assert_not_contains "$members_after_delete" "\"userUid\":\"${collab_user}\"" 'deleted manager membership cleanup'

assets_after_member_delete=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$assets_after_member_delete" "\"uuid\":\"${shared_asset_uuid}\"" 'shared work retention after member deletion'

audit_after_member_delete=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/audit?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$audit_after_member_delete" "\"actorUid\":\"${collab_user}\"" 'historical audit actor retention after member deletion'
assert_contains "$audit_after_member_delete" "\"subjectId\":\"${shared_asset_uuid}\"" 'historical audit subject retention after member deletion'

docker exec --env OC_PASS="$cleanup_password" --user www-data "$container" \
	php occ user:add --password-from-env "$cleanup_user" >/dev/null

cleanup_category=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"category":{"uuid":"1569bd73-ba6b-40ee-9bbf-83cd60b4f932","key":"cleanup_fleet","name":"Cleanup Fleet","defaultAssetClass":"vehicle"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/categories?format=json')
assert_contains "$cleanup_category" '"statuscode":201' 'cleanup category create'

cleanup_source=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"asset":{"uuid":"267ace84-cb7c-41ff-8cc0-94de71c50a43","category":"cleanup_fleet","name":"Cleanup Tow Vehicle"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$cleanup_source" '"statuscode":201' 'cleanup source asset create'

cleanup_target=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"asset":{"uuid":"378bdf95-dc8d-4200-9dd1-a5ef82d61b54","category":"other","assetClass":"trailer","name":"Cleanup Trailer"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$cleanup_target" '"statuscode":201' 'cleanup target asset create'

cleanup_component=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"component":{"uuid":"489ce0a6-ed9e-4311-8ee2-b6f093e72c65","name":"Cleanup component","type":"test_component"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/components?format=json')
assert_contains "$cleanup_component" '"statuscode":201' 'cleanup component create'

cleanup_spec=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"specification":{"uuid":"59adf1b7-feaf-4422-8ff3-c701a4f83d76","componentUuid":"489ce0a6-ed9e-4311-8ee2-b6f093e72c65","key":"cleanup.value","label":"Cleanup value","value":"fixture"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/specifications?format=json')
assert_contains "$cleanup_spec" '"statuscode":201' 'cleanup specification create'

cleanup_relationship=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"relationship":{"uuid":"6abee2c8-0fb0-4333-8004-d812b5094e87","sourceAssetUuid":"267ace84-cb7c-41ff-8cc0-94de71c50a43","targetAssetUuid":"378bdf95-dc8d-4200-9dd1-a5ef82d61b54","type":"tows","context":"cleanup"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_contains "$cleanup_relationship" '"statuscode":201' 'cleanup relationship create'

cleanup_assignment=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data '{"assignment":{"uuid":"7bcff3d9-10c1-4444-9115-e923c61a5f98","sourceAssetUuid":"267ace84-cb7c-41ff-8cc0-94de71c50a43","targetAssetUuid":"378bdf95-dc8d-4200-9dd1-a5ef82d61b54","type":"tows","context":"cleanup","isPrimary":true,"effectiveFrom":"2026-09-03"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments?format=json')
assert_contains "$cleanup_assignment" '"statuscode":201' 'cleanup assignment create'

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

# Reuse every UUID-bearing fixture. Unique-key failures here prove that account
# deletion left physical domain rows behind instead of merely hiding them.
cleanup_category_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"category":{"uuid":"1569bd73-ba6b-40ee-9bbf-83cd60b4f932","key":"cleanup_fleet","name":"Cleanup Fleet","defaultAssetClass":"vehicle"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/categories?format=json')
assert_contains "$cleanup_category_reused" '"statuscode":201' 'cleanup category UUID reuse'

cleanup_source_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"asset":{"uuid":"267ace84-cb7c-41ff-8cc0-94de71c50a43","category":"cleanup_fleet","name":"Cleanup Tow Vehicle"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$cleanup_source_reused" '"statuscode":201' 'cleanup source asset UUID reuse'

cleanup_target_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"asset":{"uuid":"378bdf95-dc8d-4200-9dd1-a5ef82d61b54","category":"other","assetClass":"trailer","name":"Cleanup Trailer"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?format=json')
assert_contains "$cleanup_target_reused" '"statuscode":201' 'cleanup target asset UUID reuse'

cleanup_component_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"component":{"uuid":"489ce0a6-ed9e-4311-8ee2-b6f093e72c65","name":"Cleanup component","type":"test_component"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/components?format=json')
assert_contains "$cleanup_component_reused" '"statuscode":201' 'cleanup component UUID reuse'

cleanup_spec_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"specification":{"uuid":"59adf1b7-feaf-4422-8ff3-c701a4f83d76","componentUuid":"489ce0a6-ed9e-4311-8ee2-b6f093e72c65","key":"cleanup.value","label":"Cleanup value","value":"fixture"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/specifications?format=json')
assert_contains "$cleanup_spec_reused" '"statuscode":201' 'cleanup specification UUID reuse'

cleanup_relationship_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"relationship":{"uuid":"6abee2c8-0fb0-4333-8004-d812b5094e87","sourceAssetUuid":"267ace84-cb7c-41ff-8cc0-94de71c50a43","targetAssetUuid":"378bdf95-dc8d-4200-9dd1-a5ef82d61b54","type":"tows","context":"cleanup"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/relationships?format=json')
assert_contains "$cleanup_relationship_reused" '"statuscode":201' 'cleanup relationship UUID reuse'

cleanup_assignment_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"assignment":{"uuid":"7bcff3d9-10c1-4444-9115-e923c61a5f98","sourceAssetUuid":"267ace84-cb7c-41ff-8cc0-94de71c50a43","targetAssetUuid":"378bdf95-dc8d-4200-9dd1-a5ef82d61b54","type":"tows","context":"cleanup","isPrimary":true,"effectiveFrom":"2026-09-03"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assignments?format=json')
assert_contains "$cleanup_assignment_reused" '"statuscode":201' 'cleanup assignment UUID reuse'

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
