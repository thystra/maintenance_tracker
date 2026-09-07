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
		echo '===== Nextcloud application log tail =====' >&2
		docker exec --user www-data "$container" sh -c \
			'test ! -f /var/www/html/data/nextcloud.log || tail -n 120 /var/www/html/data/nextcloud.log' >&2 2>/dev/null || true
		echo '===== Nextcloud container log tail =====' >&2
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

	for path in appinfo lib templates img js css profiles; do
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
assert_contains "$capabilities" '"meters-readings"' 'capabilities'
assert_contains "$capabilities" '"work-definitions-schedules"' 'capabilities'
assert_contains "$capabilities" '"activity-ledger"' 'capabilities'
assert_contains "$capabilities" '"maintenance-due-state"' 'capabilities'
assert_contains "$capabilities" '"profile-installation"' 'capabilities'

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


odometer_meter_uuid='9c7f24c0-0d3a-4c6f-9c11-0b6f3e1e5e10'
odometer_meter=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--request POST \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	--header 'Content-Type: application/json' \
	--data "{\"meter\":{\"uuid\":\"${odometer_meter_uuid}\",\"key\":\"odometer\",\"name\":\"Odometer\",\"dimension\":\"distance\",\"displayUnit\":\"mi\",\"monotonic\":true}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/meters?format=json')
assert_contains "$odometer_meter" '"statuscode":201' 'odometer meter create'
assert_contains "$odometer_meter" '"canonicalUnit":"mm"' 'odometer meter canonical unit'
assert_contains "$odometer_meter" '"monotonic":true' 'odometer meter monotonic flag'

odometer_meter_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"meter\":{\"uuid\":\"${odometer_meter_uuid}\",\"key\":\"odometer\",\"name\":\"Odometer\",\"dimension\":\"distance\",\"displayUnit\":\"mi\",\"monotonic\":true}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/meters?format=json')
assert_contains "$odometer_meter_retry" '"statuscode":201' 'odometer meter idempotent retry'
assert_contains "$odometer_meter_retry" "\"uuid\":\"${odometer_meter_uuid}\"" 'odometer meter idempotent retry'

duplicate_odometer=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"meter":{"uuid":"dc8da275-5628-44fe-a254-1ba496b3698f","key":"odometer","name":"Duplicate odometer","dimension":"distance","displayUnit":"mi","monotonic":true}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/meters?format=json')
assert_contains "$duplicate_odometer" '"statuscode":400' 'duplicate meter key rejection'

runtime_meter_uuid='11b6aabd-43c6-43bd-9e00-4daad9f83bfe'
runtime_meter=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"meter\":{\"uuid\":\"${runtime_meter_uuid}\",\"componentUuid\":\"${component_uuid}\",\"key\":\"service_hours\",\"name\":\"Service hours\",\"dimension\":\"runtime\",\"displayUnit\":\"hour\",\"monotonic\":true}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/meters?format=json')
assert_contains "$runtime_meter" '"statuscode":201' 'component runtime meter create'
assert_contains "$runtime_meter" "\"componentUuid\":\"${component_uuid}\"" 'component runtime meter target'
assert_contains "$runtime_meter" '"canonicalUnit":"s"' 'component runtime meter canonical unit'

runtime_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"reading":{"uuid":"dbbe02fb-26ba-426d-9d21-515e7b161374","observedAt":"2026-09-01T13:00:00Z","value":"1.25","unit":"h"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${runtime_meter_uuid}/readings?format=json")
assert_contains "$runtime_reading" '"statuscode":201' 'runtime reading create'
assert_contains "$runtime_reading" '"canonicalValue":4500' 'runtime reading canonical value'
assert_contains "$runtime_reading" '"originalUnit":"hour"' 'runtime reading normalized unit alias'

reading_one_uuid='a1e81ef4-e63a-4ed7-9053-fcefe78275ab'
reading_one=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${reading_one_uuid}\",\"observedAt\":\"2026-09-01T12:00:00Z\",\"value\":\"100000.0\",\"unit\":\"mi\",\"source\":{\"type\":\"manual\",\"reference\":\"integration fixture\"}}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$reading_one" '"statuscode":201' 'odometer reading create'
assert_contains "$reading_one" '"originalValue":"100000"' 'odometer reading normalized original value'
assert_contains "$reading_one" '"originalUnit":"mi"' 'odometer reading original unit'
assert_contains "$reading_one" '"canonicalValue":160934400000' 'odometer reading canonical value'

reading_one_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${reading_one_uuid}\",\"observedAt\":\"2026-09-01T12:00:00Z\",\"value\":\"100000.0\",\"unit\":\"mi\",\"source\":{\"type\":\"manual\",\"reference\":\"integration fixture\"}}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$reading_one_retry" '"statuscode":201' 'odometer reading idempotent retry'
assert_contains "$reading_one_retry" "\"uuid\":\"${reading_one_uuid}\"" 'odometer reading idempotent retry'

reading_later_uuid='b2f92f05-f74b-4fe8-a164-0df0f89386bc'
reading_later=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${reading_later_uuid}\",\"observedAt\":\"2026-09-03T12:00:00Z\",\"value\":\"100250.5\",\"unit\":\"mi\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$reading_later" '"statuscode":201' 'later odometer reading create'

reading_decrease=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"reading":{"observedAt":"2026-09-04T12:00:00Z","value":"99999","unit":"mi"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$reading_decrease" '"statuscode":400' 'monotonic decrease rejection'

historical_reading_uuid='c30a4016-085c-4af9-b275-1e01fa0497cd'
historical_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${historical_reading_uuid}\",\"observedAt\":\"2026-09-02T12:00:00Z\",\"value\":\"100100\",\"unit\":\"mi\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$historical_reading" '"statuscode":201' 'historical monotonic reading create'

historical_too_high=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"reading":{"observedAt":"2026-09-02T18:00:00Z","value":"100300","unit":"mi"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$historical_too_high" '"statuscode":400' 'historical successor monotonic rejection'

corrected_reading_uuid='d41b5127-196d-4b0a-8366-2f120b15a8de'
corrected_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${corrected_reading_uuid}\",\"value\":\"100125\",\"unit\":\"mi\",\"notes\":\"Corrected transcription\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/readings/${historical_reading_uuid}/corrections?format=json")
assert_contains "$corrected_reading" '"statuscode":201' 'reading correction create'
assert_contains "$corrected_reading" "\"supersedesUuid\":\"${historical_reading_uuid}\"" 'reading correction supersedes link'

reading_history=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$reading_history" "\"uuid\":\"${historical_reading_uuid}\"" 'reading history retains superseded row'
assert_contains "$reading_history" "\"supersededByUuid\":\"${corrected_reading_uuid}\"" 'reading history marks superseded row'
assert_contains "$reading_history" "\"uuid\":\"${corrected_reading_uuid}\"" 'reading history includes correction'

meter_update=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"meter":{"displayUnit":"km"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}?format=json")
assert_contains "$meter_update" '"statuscode":200' 'meter update'
assert_contains "$meter_update" '"revision":2' 'meter update revision'
assert_contains "$meter_update" '"displayUnit":"km"' 'meter update display unit'

meter_stale=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"meter":{"name":"Stale meter write"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}?format=json")
assert_contains "$meter_stale" '"statuscode":412' 'stale meter update'

nonmonotonic_meter_uuid='881174c9-d2b4-49e9-941d-c14eed8a7623'
nonmonotonic_meter=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"meter\":{\"uuid\":\"${nonmonotonic_meter_uuid}\",\"key\":\"cycle_counter\",\"name\":\"Cycle counter\",\"dimension\":\"usage_count\",\"displayUnit\":\"use\",\"monotonic\":false}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/meters?format=json")
assert_contains "$nonmonotonic_meter" '"statuscode":201' 'nonmonotonic meter create'

nonmonotonic_reading_one_uuid='4b84e9c0-3a09-4eba-b94d-a00ee7b42226'
nonmonotonic_reading_one=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${nonmonotonic_reading_one_uuid}\",\"observedAt\":\"2026-09-01T08:00:00Z\",\"value\":\"2\",\"unit\":\"use\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${nonmonotonic_meter_uuid}/readings?format=json")
assert_contains "$nonmonotonic_reading_one" '"statuscode":201' 'nonmonotonic first reading create'

nonmonotonic_reading_two_uuid='3cf0605f-1823-4e19-a178-bc67c20c74cb'
nonmonotonic_reading_two=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${nonmonotonic_reading_two_uuid}\",\"observedAt\":\"2026-09-02T08:00:00Z\",\"value\":\"1\",\"unit\":\"use\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${nonmonotonic_meter_uuid}/readings?format=json")
assert_contains "$nonmonotonic_reading_two" '"statuscode":201' 'nonmonotonic decreasing reading create'

monotonic_enable_rejected=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"meter":{"monotonic":true}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${nonmonotonic_meter_uuid}?format=json")
assert_contains "$monotonic_enable_rejected" '"statuscode":400' 'monotonic enable rejects inconsistent history'

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


work_group_uuid='4a18b2c3-6d4e-4f50-8a21-3b4c5d6e7f80'
work_group=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"group\":{\"uuid\":\"${work_group_uuid}\",\"key\":\"engine\",\"name\":\"Engine\",\"sortOrder\":100}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/work-groups?format=json')
assert_contains "$work_group" '"statuscode":201' 'work group create'
assert_contains "$work_group" '"key":"engine"' 'work group create'

missing_schedule=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"definition":{"key":"missing_schedule","title":"Missing schedule","kind":"maintenance"}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/work-definitions?format=json')
assert_contains "$missing_schedule" '"statuscode":400' 'missing schedule rejection'
assert_contains "$missing_schedule" 'schedule is required' 'missing schedule rejection'

oil_change_uuid='5b29c3d4-7e5f-4a61-9b32-4c5d6e7f8091'
oil_change=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"uuid\":\"${oil_change_uuid}\",\"groupUuid\":\"${work_group_uuid}\",\"key\":\"oil_change\",\"title\":\"Engine oil change\",\"kind\":\"maintenance\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"meter\",\"meterUuid\":\"${odometer_meter_uuid}\",\"interval\":{\"value\":7500,\"unit\":\"mi\"}},{\"type\":\"calendar\",\"interval\":{\"value\":12,\"unit\":\"month\"}}]}}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/work-definitions?format=json')
assert_contains "$oil_change" '"statuscode":201' 'scheduled work definition create'
assert_contains "$oil_change" '"combination":"any"' 'scheduled work definition create'
assert_contains "$oil_change" "\"meterUuid\":\"${odometer_meter_uuid}\"" 'scheduled work definition meter'
assert_contains "$oil_change" '"value":"7500","unit":"mi"' 'scheduled work definition original meter interval'

oil_change_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"uuid\":\"${oil_change_uuid}\",\"groupUuid\":\"${work_group_uuid}\",\"key\":\"oil_change\",\"title\":\"Engine oil change\",\"kind\":\"maintenance\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"meter\",\"meterUuid\":\"${odometer_meter_uuid}\",\"interval\":{\"value\":7500,\"unit\":\"mi\"}},{\"type\":\"calendar\",\"interval\":{\"value\":12,\"unit\":\"month\"}}]}}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/work-definitions?format=json')
assert_contains "$oil_change_retry" '"statuscode":201' 'scheduled work definition idempotent retry'


activity_uuid='a1111111-1111-4111-8111-111111111111'
activity_item_uuid='b1111111-1111-4111-8111-111111111111'
activity_component_item_uuid='b1111111-2222-4111-8111-111111111111'
activity_meter_uuid='c1111111-1111-4111-8111-111111111111'
activity_reading_uuid='d1111111-1111-4111-8111-111111111111'
activity_create=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${activity_uuid}\",\"performedAt\":\"2026-09-04T12:00:00Z\",\"summary\":\"Oil service\",\"items\":[{\"uuid\":\"${activity_item_uuid}\",\"definitionUuid\":\"${oil_change_uuid}\"},{\"uuid\":\"${activity_component_item_uuid}\",\"componentUuid\":\"${component_uuid}\",\"title\":\"Inspect primary fuel filter\",\"kind\":\"inspection\"}],\"meters\":[{\"uuid\":\"${activity_meter_uuid}\",\"meterUuid\":\"${odometer_meter_uuid}\",\"reading\":{\"uuid\":\"${activity_reading_uuid}\",\"value\":\"100300\",\"unit\":\"mi\",\"notes\":\"Service odometer\"}}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/activities?format=json')
assert_contains "$activity_create" '"statuscode":201' 'activity create'
assert_contains "$activity_create" "\"definitionUuid\":\"${oil_change_uuid}\"" 'activity definition snapshot'
assert_contains "$activity_create" "\"componentUuid\":\"${component_uuid}\"" 'activity component identity snapshot'
assert_contains "$activity_create" '"componentName":"Primary fuel filter"' 'activity component name snapshot'
assert_contains "$activity_create" "\"readingUuid\":\"${activity_reading_uuid}\"" 'activity meter reading snapshot'

renamed_oil_change=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"definition":{"title":"Oil service definition"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/work-definitions/${oil_change_uuid}?format=json")
assert_contains "$renamed_oil_change" '"statuscode":200' 'work definition rename after activity'

activity_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${activity_uuid}\",\"performedAt\":\"2026-09-04T12:00:00Z\",\"summary\":\"Oil service\",\"items\":[{\"uuid\":\"${activity_item_uuid}\",\"definitionUuid\":\"${oil_change_uuid}\"},{\"uuid\":\"${activity_component_item_uuid}\",\"componentUuid\":\"${component_uuid}\",\"title\":\"Inspect primary fuel filter\",\"kind\":\"inspection\"}],\"meters\":[{\"uuid\":\"${activity_meter_uuid}\",\"meterUuid\":\"${odometer_meter_uuid}\",\"reading\":{\"uuid\":\"${activity_reading_uuid}\",\"value\":\"100300\",\"unit\":\"mi\",\"notes\":\"Service odometer\"}}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/activities?format=json')
assert_contains "$activity_retry" '"statuscode":201' 'activity retry after definition rename'

activity_component_omission_conflict=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${activity_uuid}\",\"performedAt\":\"2026-09-04T12:00:00Z\",\"summary\":\"Oil service\",\"items\":[{\"uuid\":\"${activity_item_uuid}\",\"definitionUuid\":\"${oil_change_uuid}\"},{\"uuid\":\"${activity_component_item_uuid}\",\"title\":\"Inspect primary fuel filter\",\"kind\":\"inspection\"}],\"meters\":[{\"uuid\":\"${activity_meter_uuid}\",\"meterUuid\":\"${odometer_meter_uuid}\",\"reading\":{\"uuid\":\"${activity_reading_uuid}\",\"value\":\"100300\",\"unit\":\"mi\",\"notes\":\"Service odometer\"}}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/activities?format=json')
assert_contains "$activity_component_omission_conflict" '"statuscode":412' 'activity retry component omission conflict'

activity_conflict=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${activity_uuid}\",\"performedAt\":\"2026-09-04T12:00:00Z\",\"summary\":\"Oil service\",\"items\":[{\"uuid\":\"${activity_item_uuid}\",\"definitionUuid\":\"${oil_change_uuid}\"},{\"uuid\":\"${activity_component_item_uuid}\",\"componentUuid\":\"${component_uuid}\",\"title\":\"Inspect primary fuel filter\",\"kind\":\"inspection\"}],\"meters\":[{\"uuid\":\"${activity_meter_uuid}\",\"meterUuid\":\"${odometer_meter_uuid}\",\"reading\":{\"uuid\":\"${activity_reading_uuid}\",\"value\":\"100300\",\"unit\":\"mi\",\"notes\":\"Changed retry\"}}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/activities?format=json')
assert_contains "$activity_conflict" '"statuscode":412' 'activity nested reading retry conflict'


archived_activity_meter_uuid='91111111-1111-4111-8111-111111111111'
archived_activity_meter=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"meter\":{\"uuid\":\"${archived_activity_meter_uuid}\",\"key\":\"retired_service_counter\",\"name\":\"Retired service counter\",\"dimension\":\"usage_count\",\"displayUnit\":\"use\",\"monotonic\":true}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/meters?format=json')
assert_contains "$archived_activity_meter" '"statuscode":201' 'offline activity meter fixture create'

archived_activity_meter_delete=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request DELETE \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${archived_activity_meter_uuid}?format=json")
assert_contains "$archived_activity_meter_delete" '"statuscode":200' 'offline activity meter fixture archive'

offline_activity_uuid='a3333333-3333-4333-8333-333333333333'
offline_activity_item_uuid='b3333333-3333-4333-8333-333333333333'
offline_activity_snapshot_uuid='c3333333-3333-4333-8333-333333333333'
offline_activity_reading_uuid='d3333333-3333-4333-8333-333333333333'
offline_activity=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${offline_activity_uuid}\",\"performedAt\":\"2026-09-04T13:00:00Z\",\"summary\":\"Delayed field sync\",\"items\":[{\"uuid\":\"${offline_activity_item_uuid}\",\"title\":\"Exercise retired counter\",\"kind\":\"inspection\"}],\"meters\":[{\"uuid\":\"${offline_activity_snapshot_uuid}\",\"meterUuid\":\"${archived_activity_meter_uuid}\",\"reading\":{\"uuid\":\"${offline_activity_reading_uuid}\",\"value\":\"1\",\"unit\":\"use\"}}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/activities?format=json')
assert_contains "$offline_activity" '"statuscode":201' 'offline activity create against archived meter'
assert_contains "$offline_activity" '"meterName":"Retired service counter"' 'offline activity archived-meter name snapshot'

business_definition_uuid='6c3ad4e5-8f60-4b72-ac43-5d6e7f8091a2'
business_definition=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"uuid\":\"${business_definition_uuid}\",\"key\":\"business_inspection\",\"title\":\"Business-day inspection\",\"kind\":\"inspection\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"business_days\",\"interval\":{\"value\":10,\"unit\":\"business_day\"},\"weekdays\":[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\"]}]}}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/work-definitions?format=json')
assert_contains "$business_definition" '"statuscode":201' 'business-day work definition create'
assert_contains "$business_definition" '"weekdays":["mon","tue","wed","thu","fri"]' 'business-day work definition weekdays'


maintenance_upcoming=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-status?asOf=2026-09-10T12%3A00%3A00Z&format=json')
assert_contains "$maintenance_upcoming" '"statuscode":200' 'maintenance status upcoming projection'
oil_status=$(printf '%s' "$maintenance_upcoming" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definition"]["uuid"]??"")==="'"${oil_change_uuid}"'"){echo $i["state"]??"";}}')
business_status=$(printf '%s' "$maintenance_upcoming" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definition"]["uuid"]??"")==="'"${business_definition_uuid}"'"){echo $i["state"]??"";}}')
[ "$oil_status" = 'upcoming' ] || { echo "maintenance status expected oil upcoming, got: $oil_status" >&2; exit 1; }
[ "$business_status" = 'baseline_required' ] || { echo "maintenance status expected business baseline_required, got: $business_status" >&2; exit 1; }
assert_contains "$maintenance_upcoming" '"lastActivityUuid":"'"${activity_uuid}"'"' 'maintenance status completion baseline'

reminder_policy_default=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/reminder-policy?format=json')
assert_contains "$reminder_policy_default" '"statuscode":200' 'default reminder policy read'
assert_contains "$reminder_policy_default" '"calendarLeadDays":14' 'default calendar forecast lead'
assert_contains "$reminder_policy_default" '"meterLeadPercent":10' 'default meter forecast lead'
assert_contains "$reminder_policy_default" '"revision":0' 'default reminder policy revision'
assert_contains "$reminder_policy_default" '"source":"default"' 'default reminder policy source'

reminder_policy_saved=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":0,"policy":{"calendarLeadDays":14,"meterLeadPercent":10}}' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/reminder-policy?format=json')
assert_contains "$reminder_policy_saved" '"statuscode":200' 'reminder policy save'
assert_contains "$reminder_policy_saved" '"revision":1' 'reminder policy stored revision'
assert_contains "$reminder_policy_saved" '"source":"workspace"' 'reminder policy workspace source'

forecast_soon_reading_uuid='d4444444-4444-4444-8444-444444444444'
forecast_soon_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"reading":{"uuid":"'"${forecast_soon_reading_uuid}"'","observedAt":"2026-09-30T12:00:00Z","value":"107100","unit":"mi","source":{"type":"manual","reference":"forecast smoke"}}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$forecast_soon_reading" '"statuscode":201' 'forecast due-soon meter reading create'

maintenance_forecast_soon=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-forecast?asOf=2026-09-30T12%3A00%3A00Z&format=json')
assert_contains "$maintenance_forecast_soon" '"statuscode":200' 'maintenance forecast due-soon projection'
oil_forecast_state=$(printf '%s' "$maintenance_forecast_soon" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definition"]["uuid"]??"")==="'"${oil_change_uuid}"'"){echo $i["forecast"]["state"]??"";}}')
business_forecast_state=$(printf '%s' "$maintenance_forecast_soon" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definition"]["uuid"]??"")==="'"${business_definition_uuid}"'"){echo $i["forecast"]["state"]??"";}}')
[ "$oil_forecast_state" = 'due_soon' ] || { echo "maintenance forecast expected oil due_soon, got: $oil_forecast_state" >&2; echo "$maintenance_forecast_soon" >&2; exit 1; }
[ "$business_forecast_state" = 'setup_required' ] || { echo "maintenance forecast expected business setup_required, got: $business_forecast_state" >&2; exit 1; }
assert_contains "$maintenance_forecast_soon" '"materialize":true' 'maintenance forecast materialization policy'

maintenance_occurrences_first=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-occurrences/reconcile?asOf=2026-09-30T12%3A00%3A00Z&format=json')
assert_contains "$maintenance_occurrences_first" '"statuscode":200' 'maintenance occurrence reconcile'
occurrence_uuid=$(printf '%s' "$maintenance_occurrences_first" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definitionUuid"]??"")==="'"${oil_change_uuid}"'" && ($i["open"]??false)){echo $i["uuid"]??"";}}')
[ -n "$occurrence_uuid" ] || { echo "maintenance occurrence was not materialized for due-soon oil work" >&2; echo "$maintenance_occurrences_first" >&2; exit 1; }

maintenance_occurrences_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-occurrences/reconcile?asOf=2026-09-30T12%3A00%3A00Z&format=json')
occurrence_retry_uuid=$(printf '%s' "$maintenance_occurrences_retry" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definitionUuid"]??"")==="'"${oil_change_uuid}"'" && ($i["open"]??false)){echo $i["uuid"]??"";}}')
[ "$occurrence_retry_uuid" = "$occurrence_uuid" ] || { echo "maintenance occurrence reconcile was not idempotent" >&2; exit 1; }
open_oil_occurrence_count=$(printf '%s' "$maintenance_occurrences_retry" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); $n=0; foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definitionUuid"]??"")==="'"${oil_change_uuid}"'" && ($i["open"]??false)){$n++;}} echo $n;')
[ "$open_oil_occurrence_count" = '1' ] || { echo "expected exactly one open occurrence per definition, got: $open_oil_occurrence_count" >&2; exit 1; }

maintenance_invalid_asof=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-status?asOf=not-a-time&format=json')
assert_contains "$maintenance_invalid_asof" '"statuscode":400' 'maintenance status invalid asOf'

maintenance_due_reading_uuid='e4444444-4444-4444-8444-444444444444'
maintenance_due_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"reading":{"uuid":"'"${maintenance_due_reading_uuid}"'","observedAt":"2026-10-01T12:00:00Z","value":"107800","unit":"mi","source":{"type":"manual","reference":"due-state smoke"}}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$maintenance_due_reading" '"statuscode":201' 'maintenance due meter reading create'
maintenance_due=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-status?asOf=2026-10-01T12%3A00%3A00Z&format=json')
oil_due_status=$(printf '%s' "$maintenance_due" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definition"]["uuid"]??"")==="'"${oil_change_uuid}"'"){echo $i["state"]??"";}}')
[ "$oil_due_status" = 'due' ] || { echo "maintenance status expected oil due, got: $oil_due_status" >&2; echo "$maintenance_due" >&2; exit 1; }
assert_contains "$maintenance_due" '"remainingCanonicalValue":0' 'maintenance meter exact due threshold'

maintenance_overdue_reading_uuid='f4444444-4444-4444-8444-444444444444'
maintenance_overdue_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"reading":{"uuid":"'"${maintenance_overdue_reading_uuid}"'","observedAt":"2026-10-02T12:00:00Z","value":"107801","unit":"mi","source":{"type":"manual","reference":"due-state smoke"}}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?format=json")
assert_contains "$maintenance_overdue_reading" '"statuscode":201' 'maintenance overdue meter reading create'
maintenance_overdue=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-status?asOf=2026-10-02T12%3A00%3A00Z&format=json')
oil_overdue_status=$(printf '%s' "$maintenance_overdue" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definition"]["uuid"]??"")==="'"${oil_change_uuid}"'"){echo $i["state"]??"";}}')
[ "$oil_overdue_status" = 'overdue' ] || { echo "maintenance status expected oil overdue, got: $oil_overdue_status" >&2; echo "$maintenance_overdue" >&2; exit 1; }

maintenance_occurrences_overdue=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-occurrences?asOf=2026-10-02T12%3A00%3A00Z&format=json')
overdue_occurrence_uuid=$(printf '%s' "$maintenance_occurrences_overdue" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definitionUuid"]??"")==="'"${oil_change_uuid}"'" && ($i["open"]??false)){echo $i["uuid"]??"";}}')
overdue_occurrence_forecast=$(printf '%s' "$maintenance_occurrences_overdue" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definitionUuid"]??"")==="'"${oil_change_uuid}"'" && ($i["open"]??false)){echo $i["current"]["forecast"]["state"]??"";}}')
[ "$overdue_occurrence_uuid" = "$occurrence_uuid" ] || { echo "occurrence identity changed as derived forecast advanced" >&2; exit 1; }
[ "$overdue_occurrence_forecast" = 'overdue' ] || { echo "open occurrence did not expose live overdue forecast, got: $overdue_occurrence_forecast" >&2; exit 1; }

completion_activity_uuid='d2222222-2222-4222-8222-222222222222'
completion_activity_item_uuid='e2222222-2222-4222-8222-222222222222'
completion_activity_meter_uuid='f2222222-2222-4222-8222-222222222222'
completion_activity=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${completion_activity_uuid}\",\"performedAt\":\"2026-10-02T12:00:00Z\",\"summary\":\"Completed forecasted oil service\",\"items\":[{\"uuid\":\"${completion_activity_item_uuid}\",\"definitionUuid\":\"${oil_change_uuid}\"}],\"meters\":[{\"uuid\":\"${completion_activity_meter_uuid}\",\"meterUuid\":\"${odometer_meter_uuid}\",\"readingUuid\":\"${maintenance_overdue_reading_uuid}\"}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/activities?format=json')
assert_contains "$completion_activity" '"statuscode":201' 'forecast occurrence completion activity'

maintenance_occurrences_completed=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-occurrences/reconcile?asOf=2026-10-02T12%3A00%3A00Z&format=json')
open_after_completion=$(printf '%s' "$maintenance_occurrences_completed" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); $n=0; foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definitionUuid"]??"")==="'"${oil_change_uuid}"'" && ($i["open"]??false)){$n++;}} echo $n;')
[ "$open_after_completion" = '0' ] || { echo "completed maintenance occurrence remained open" >&2; exit 1; }

maintenance_occurrence_history=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/maintenance-occurrences?includeClosed=true&asOf=2026-10-02T12%3A00%3A00Z&format=json')
closed_occurrence_reason=$(printf '%s' "$maintenance_occurrence_history" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["uuid"]??"")==="'"${occurrence_uuid}"'"){echo $i["closedReason"]??"";}}')
[ "$closed_occurrence_reason" = 'completed' ] || { echo "closed occurrence expected completed reason, got: $closed_occurrence_reason" >&2; exit 1; }

invalid_meter_schedule=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"key\":\"invalid_meter_schedule\",\"title\":\"Invalid meter schedule\",\"kind\":\"maintenance\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"meter\",\"meterUuid\":\"${odometer_meter_uuid}\",\"interval\":{\"value\":1,\"unit\":\"hour\"}}]}}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/work-definitions?format=json')
assert_contains "$invalid_meter_schedule" '"statuscode":400' 'meter schedule dimension rejection'

scheduled_definitions=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/b913571d-5405-4a88-bb59-2d670a5f93dc/work-definitions?scheduled=true&format=json')
assert_contains "$scheduled_definitions" "\"uuid\":\"${oil_change_uuid}\"" 'scheduled definition filter'
assert_contains "$scheduled_definitions" "\"uuid\":\"${business_definition_uuid}\"" 'scheduled definition filter'

trailer_repair_uuid='7d4be5f6-9061-4c83-bd54-6e7f8091a2b3'
trailer_repair=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"uuid\":\"${trailer_repair_uuid}\",\"key\":\"repair\",\"title\":\"Unscheduled trailer repair\",\"kind\":\"repair\",\"schedule\":\"none\"}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/work-definitions?format=json')
assert_contains "$trailer_repair" '"statuscode":201' 'unscheduled work definition create'
assert_contains "$trailer_repair" '"schedule":"none"' 'unscheduled work definition explicit schedule'

unscheduled_definitions=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/work-definitions?scheduled=false&format=json')
assert_contains "$unscheduled_definitions" "\"uuid\":\"${trailer_repair_uuid}\"" 'unscheduled definition filter'


trailer_status=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/maintenance-status?asOf=2026-10-02T12%3A00%3A00Z&format=json')
trailer_repair_status=$(printf '%s' "$trailer_status" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["definition"]["uuid"]??"")==="'"${trailer_repair_uuid}"'"){echo $i["state"]??"";}}')
[ "$trailer_repair_status" = 'unscheduled' ] || { echo "maintenance status expected trailer repair unscheduled, got: $trailer_repair_status" >&2; exit 1; }

work_group_archive_rejected=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request DELETE \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/work-groups/${work_group_uuid}?format=json")
assert_contains "$work_group_archive_rejected" '"statuscode":400' 'referenced work group archive rejection'

scheduled_meter_archive_rejected=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request DELETE \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":2}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}?format=json")
assert_contains "$scheduled_meter_archive_rejected" '"statuscode":400' 'scheduled meter archive rejection'

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


contributor_meters=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_meters" '"statuscode":200' 'contributor meter read'


contributor_work_definitions=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/work-definitions?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_work_definitions" '"statuscode":200' 'contributor work-definition read'
assert_contains "$contributor_work_definitions" "\"uuid\":\"${trailer_repair_uuid}\"" 'contributor work-definition read'


contributor_maintenance_status=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/maintenance-status?workspace=${admin_workspace_uuid}&asOf=2026-10-02T12%3A00%3A00Z&format=json")
assert_contains "$contributor_maintenance_status" '"statuscode":200' 'contributor maintenance-status read'

contributor_forecast=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/maintenance-forecast?workspace=${admin_workspace_uuid}&asOf=2026-10-02T12%3A00%3A00Z&format=json")
assert_contains "$contributor_forecast" '"statuscode":200' 'contributor maintenance-forecast read'

contributor_occurrences=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/maintenance-occurrences?workspace=${admin_workspace_uuid}&asOf=2026-10-02T12%3A00%3A00Z&format=json")
assert_contains "$contributor_occurrences" '"statuscode":200' 'contributor maintenance-occurrence read'

contributor_reconcile_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/maintenance-occurrences/reconcile?workspace=${admin_workspace_uuid}&asOf=2026-10-02T12%3A00%3A00Z&format=json")
assert_contains "$contributor_reconcile_denied" '"statuscode":403' 'contributor maintenance-occurrence reconcile rejection'

contributor_reminder_policy=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/reminder-policy?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_reminder_policy" '"statuscode":200' 'contributor reminder-policy read'

contributor_reminder_policy_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"policy":{"calendarLeadDays":30}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/reminder-policy?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_reminder_policy_denied" '"statuscode":403' 'contributor reminder-policy management rejection'


contributor_activity_uuid='e1111111-1111-4111-8111-111111111111'
contributor_activity_item_uuid='f1111111-1111-4111-8111-111111111111'
contributor_activity=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${contributor_activity_uuid}\",\"performedAt\":\"2026-09-05T13:00:00Z\",\"items\":[{\"uuid\":\"${contributor_activity_item_uuid}\",\"definitionUuid\":\"${trailer_repair_uuid}\"}]}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/activities?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_activity" '"statuscode":201' 'contributor activity create'
contributor_activity_update_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"activity":{"summary":"Forbidden correction"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/activities/${contributor_activity_uuid}?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_activity_update_denied" '"statuscode":403' 'contributor activity correction rejection'

contributor_work_definition_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"definition":{"key":"forbidden_work","title":"Forbidden work","kind":"repair","schedule":"none"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/work-definitions?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_work_definition_denied" '"statuscode":403' 'contributor work-definition management rejection'

contributor_meter_manage_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"meter":{"key":"forbidden","name":"Forbidden meter","dimension":"usage_count","displayUnit":"use"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/meters?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_meter_manage_denied" '"statuscode":403' 'contributor meter management rejection'

contributor_reading_uuid='e52c6238-2a7e-4c1b-9477-30231c26b9ef'
contributor_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${contributor_reading_uuid}\",\"observedAt\":\"2026-09-05T12:00:00Z\",\"value\":\"100300\",\"unit\":\"mi\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_reading" '"statuscode":201' 'contributor reading create'

contributor_correction_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"reading":{"value":"100301","unit":"mi"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/readings/${contributor_reading_uuid}/corrections?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_correction_denied" '"statuscode":403' 'contributor reading correction rejection'

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

# v0.1.9 validated local profile installation into canonical domain records.
profile_catalog=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/profiles?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_catalog" '"statuscode":200' 'bundled profile catalog'
assert_contains "$profile_catalog" '"id":"org.argentwolf.maintenance.generic-car"' 'bundled generic-car profile'
assert_contains "$profile_catalog" '"version":"0.3.0"' 'bundled generic-car profile version'
assert_contains "$profile_catalog" '"trustState":"first_party"' 'bundled profile trust state'

profile_target_uuid='913f3d34-9d2b-47af-ae37-7fa22e340191'
profile_target=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"asset\":{\"uuid\":\"${profile_target_uuid}\",\"category\":\"vehicle\",\"assetClass\":\"vehicle\",\"name\":\"Profile installation target\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_target" '"statuscode":201' 'profile target asset create'

profile_payload=$(docker exec "$container" php -r '$p=json_decode(file_get_contents("/var/www/html/custom_apps/maintenance_tracker/profiles/generic-car.json"),true);$p["id"]="org.argentwolf.maintenance.smoke-local";$p["provenance"]["sourceRevision"]="integration-local";echo json_encode(["profile"=>$p],JSON_UNESCAPED_SLASHES);')
profile_validate=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$profile_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/profiles/validate?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_validate" '"statuscode":200' 'local profile validate'
assert_contains "$profile_validate" '"valid":true' 'local profile validation result'
assert_contains "$profile_validate" '"origin":"local"' 'local profile origin classification'
assert_contains "$profile_validate" '"components":7' 'local profile materialized component count'
profile_hash=$(printf '%s' "$profile_validate" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["ocs"]["data"]["profile"]["contentHash"]??"";')
if [[ ! "$profile_hash" =~ ^[0-9a-f]{64}$ ]]; then
	echo "local profile content hash is not SHA-256: ${profile_hash}" >&2
	exit 1
fi

profile_preview=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$profile_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/profiles/preview?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_preview" '"statuscode":200' 'local profile preview'
assert_contains "$profile_preview" '"applicable":true' 'local profile applicability'
assert_contains "$profile_preview" '"installable":true' 'local profile installable preview'
assert_contains "$profile_preview" '"meters":1' 'profile preview meter count'
assert_contains "$profile_preview" '"workGroups":4' 'profile preview work-group count'
assert_contains "$profile_preview" '"workDefinitions":6' 'profile preview work-definition count'

profile_installation_uuid='124a9d17-3f55-4550-92de-65392ad39d87'
profile_install_payload=$(printf '%s' "$profile_payload" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);$d["installationUuid"]="124a9d17-3f55-4550-92de-65392ad39d87";echo json_encode($d,JSON_UNESCAPED_SLASHES);')
profile_install=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$profile_install_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/profiles/install?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_install" '"statuscode":201' 'local profile install'
assert_contains "$profile_install" "\"installationUuid\":\"${profile_installation_uuid}\"" 'profile installation UUID'
assert_contains "$profile_install" '"id":"org.argentwolf.maintenance.smoke-local"' 'installed local profile identity'
assert_contains "$profile_install" "\"contentHash\":\"${profile_hash}\"" 'installed profile content hash'

profile_install_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$profile_install_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/profiles/install?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_install_retry" '"statuscode":201' 'profile installation idempotent retry'
assert_contains "$profile_install_retry" "\"installationUuid\":\"${profile_installation_uuid}\"" 'profile installation retry identity'

profile_current=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/profile-installation?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_current" '"statuscode":200' 'profile installation current read'
assert_contains "$profile_current" '"sourceType":"component"' 'profile component source binding'
assert_contains "$profile_current" '"sourceType":"meter"' 'profile meter source binding'
assert_contains "$profile_current" '"sourceType":"work_group"' 'profile work-group source binding'
assert_contains "$profile_current" '"sourceType":"work_definition"' 'profile work-definition source binding'

profile_components=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/components?workspace=${admin_workspace_uuid}&format=json")
profile_component_count=$(printf '%s' "$profile_components" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);echo count($d["ocs"]["data"]["items"]??[]);')
[ "$profile_component_count" = '7' ] || { echo "profile component count expected 7, got ${profile_component_count}" >&2; exit 1; }

profile_meters=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/meters?workspace=${admin_workspace_uuid}&format=json")
profile_odometer_uuid=$(printf '%s' "$profile_meters" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["key"]??"")==="odometer"){echo $i["uuid"];}}')
[[ "$profile_odometer_uuid" =~ ^[0-9a-f-]{36}$ ]] || { echo 'profile odometer UUID missing' >&2; exit 1; }

profile_groups=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/work-groups?workspace=${admin_workspace_uuid}&format=json")
profile_group_count=$(printf '%s' "$profile_groups" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);echo count($d["ocs"]["data"]["items"]??[]);')
[ "$profile_group_count" = '4' ] || { echo "profile work-group count expected 4, got ${profile_group_count}" >&2; exit 1; }

profile_definitions=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/work-definitions?workspace=${admin_workspace_uuid}&format=json")
profile_definition_count=$(printf '%s' "$profile_definitions" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);echo count($d["ocs"]["data"]["items"]??[]);')
[ "$profile_definition_count" = '6' ] || { echo "profile work-definition count expected 6, got ${profile_definition_count}" >&2; exit 1; }
rotate_meter_uuid=$(printf '%s' "$profile_definitions" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["key"]??"")==="rotate_tires"){foreach(($i["schedule"]["rules"]??[]) as $r){if(($r["type"]??"")==="meter"){echo $r["meterUuid"]??"";}}}}')
[ "$rotate_meter_uuid" = "$profile_odometer_uuid" ] || { echo 'profile meterKey did not resolve to materialized meter UUID' >&2; exit 1; }

profile_asset=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$profile_asset" '"key":"org.argentwolf.maintenance.smoke-local"' 'asset profile key after materialization'
assert_contains "$profile_asset" '"version":"0.3.0"' 'asset profile version after materialization'

contributor_profile_read=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/profile-installation?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_profile_read" '"statuscode":200' 'contributor profile installation read'
assert_contains "$contributor_profile_read" "\"installationUuid\":\"${profile_installation_uuid}\"" 'contributor profile provenance read'

contributor_profile_install_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$profile_install_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${profile_target_uuid}/profiles/install?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_profile_install_denied" '"statuscode":403' 'contributor profile install rejection'

profile_audit=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/audit?workspace=${admin_workspace_uuid}&limit=100&format=json")
assert_contains "$profile_audit" '"eventType":"profile.installed"' 'profile installation audit event'
assert_contains "$profile_audit" "\"subjectId\":\"${profile_installation_uuid}\"" 'profile installation audit subject'
assert_contains "$profile_audit" "\"contentHash\":\"${profile_hash}\"" 'profile installation audit content hash'

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


manager_activity_update=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request PATCH \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data '{"expectedRevision":1,"activity":{"summary":"Manager reviewed repair"}}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/activities/${contributor_activity_uuid}?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$manager_activity_update" '"statuscode":200' 'manager activity correction'
assert_contains "$manager_activity_update" '"revision":2' 'manager activity correction revision'


manager_correction_uuid='f63d7349-3b8f-4d2c-9588-41342d37caf0'
manager_correction=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${manager_correction_uuid}\",\"value\":\"100305\",\"unit\":\"mi\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/readings/${contributor_reading_uuid}/corrections?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$manager_correction" '"statuscode":201' 'manager reading correction'
assert_contains "$manager_correction" "\"supersedesUuid\":\"${contributor_reading_uuid}\"" 'manager reading correction link'


manager_work_definition_uuid='8e5cf607-a172-4d94-8e65-7f8091a2b3c4'
manager_work_definition=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"uuid\":\"${manager_work_definition_uuid}\",\"key\":\"manager_repair\",\"title\":\"Manager-created repair\",\"kind\":\"repair\",\"schedule\":\"none\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/work-definitions?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$manager_work_definition" '"statuscode":201' 'manager work-definition create'
assert_contains "$manager_work_definition" '"schedule":"none"' 'manager work-definition explicit unscheduled schedule'

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
assert_contains "$manager_audit" '"eventType":"reading.corrected"' 'manager audit reading correction event'
assert_contains "$manager_audit" '"eventType":"work_definition.created"' 'manager audit work-definition event'
assert_contains "$manager_audit" "\"subjectId\":\"${manager_work_definition_uuid}\"" 'manager audit work-definition subject'
assert_contains "$manager_audit" '"eventType":"activity.updated"' 'manager audit activity update event'
assert_contains "$manager_audit" "\"supersedesReadingUuid\":\"${contributor_reading_uuid}\"" 'manager audit reading correction detail'
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

readings_after_member_delete=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' \
	--header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${odometer_meter_uuid}/readings?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$readings_after_member_delete" "\"uuid\":\"${contributor_reading_uuid}\"" 'shared contributor reading retention after member deletion'
assert_contains "$readings_after_member_delete" "\"uuid\":\"${manager_correction_uuid}\"" 'shared manager correction retention after member deletion'


work_definitions_after_member_delete=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/work-definitions?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$work_definitions_after_member_delete" "\"uuid\":\"${manager_work_definition_uuid}\"" 'shared manager work-definition retention after member deletion'

activities_after_member_delete=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/c024682e-6516-4b99-8c6a-3e781b6fa4ed/activities?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$activities_after_member_delete" "\"uuid\":\"${contributor_activity_uuid}\"" 'shared contributor activity retention after member deletion'

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


cleanup_work_group_uuid='2c60a67c-6eb2-4f12-8c34-74675a6afd23'
cleanup_work_group=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"group\":{\"uuid\":\"${cleanup_work_group_uuid}\",\"key\":\"maintenance\",\"name\":\"Maintenance\",\"sortOrder\":100}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/work-groups?format=json')
assert_contains "$cleanup_work_group" '"statuscode":201' 'cleanup work group create'


cleanup_meter_uuid='0a4e845a-4c90-4e3d-a699-52453e48db01'
cleanup_meter=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"meter\":{\"uuid\":\"${cleanup_meter_uuid}\",\"key\":\"uses\",\"name\":\"Uses\",\"dimension\":\"usage_count\",\"displayUnit\":\"use\",\"monotonic\":true}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/meters?format=json')
assert_contains "$cleanup_meter" '"statuscode":201' 'cleanup meter create'

cleanup_reading_uuid='1b5f956b-5da1-4f4e-b7aa-63564f59ec12'
cleanup_reading=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${cleanup_reading_uuid}\",\"observedAt\":\"2026-09-04T12:00:00Z\",\"value\":\"1\",\"unit\":\"use\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${cleanup_meter_uuid}/readings?format=json")
assert_contains "$cleanup_reading" '"statuscode":201' 'cleanup reading create'


cleanup_work_definition_uuid='3d71b78d-7fc3-4023-9d45-85786b7b0e34'
cleanup_work_definition=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"uuid\":\"${cleanup_work_definition_uuid}\",\"groupUuid\":\"${cleanup_work_group_uuid}\",\"key\":\"service_after_uses\",\"title\":\"Service after uses\",\"kind\":\"maintenance\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"meter\",\"meterUuid\":\"${cleanup_meter_uuid}\",\"interval\":{\"value\":10,\"unit\":\"use\"}}]}}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/work-definitions?format=json')
assert_contains "$cleanup_work_definition" '"statuscode":201' 'cleanup work definition create'

cleanup_activity_uuid='a2222222-2222-4222-8222-222222222222'
cleanup_activity_item_uuid='b2222222-2222-4222-8222-222222222222'
cleanup_activity_meter_uuid='c2222222-2222-4222-8222-222222222222'
cleanup_activity=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${cleanup_activity_uuid}\",\"performedAt\":\"2026-09-04T12:00:00Z\",\"items\":[{\"uuid\":\"${cleanup_activity_item_uuid}\",\"definitionUuid\":\"${cleanup_work_definition_uuid}\"}],\"meters\":[{\"uuid\":\"${cleanup_activity_meter_uuid}\",\"meterUuid\":\"${cleanup_meter_uuid}\",\"readingUuid\":\"${cleanup_reading_uuid}\"}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/activities?format=json')
assert_contains "$cleanup_activity" '"statuscode":201' 'cleanup activity create'

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


cleanup_work_group_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"group\":{\"uuid\":\"${cleanup_work_group_uuid}\",\"key\":\"maintenance\",\"name\":\"Maintenance\",\"sortOrder\":100}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/work-groups?format=json')
assert_contains "$cleanup_work_group_reused" '"statuscode":201' 'cleanup work group UUID reuse'


cleanup_meter_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"meter\":{\"uuid\":\"${cleanup_meter_uuid}\",\"key\":\"uses\",\"name\":\"Uses\",\"dimension\":\"usage_count\",\"displayUnit\":\"use\",\"monotonic\":true}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/meters?format=json')
assert_contains "$cleanup_meter_reused" '"statuscode":201' 'cleanup meter UUID reuse'

cleanup_reading_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"reading\":{\"uuid\":\"${cleanup_reading_uuid}\",\"observedAt\":\"2026-09-04T12:00:00Z\",\"value\":\"1\",\"unit\":\"use\"}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/meters/${cleanup_meter_uuid}/readings?format=json")
assert_contains "$cleanup_reading_reused" '"statuscode":201' 'cleanup reading UUID reuse'


cleanup_work_definition_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"definition\":{\"uuid\":\"${cleanup_work_definition_uuid}\",\"groupUuid\":\"${cleanup_work_group_uuid}\",\"key\":\"service_after_uses\",\"title\":\"Service after uses\",\"kind\":\"maintenance\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"meter\",\"meterUuid\":\"${cleanup_meter_uuid}\",\"interval\":{\"value\":10,\"unit\":\"use\"}}]}}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/work-definitions?format=json')
assert_contains "$cleanup_work_definition_reused" '"statuscode":201' 'cleanup work definition UUID reuse'

cleanup_activity_reused=$(docker exec "$container" curl --silent --show-error \
	--user "${cleanup_user}:${cleanup_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"activity\":{\"uuid\":\"${cleanup_activity_uuid}\",\"performedAt\":\"2026-09-04T12:00:00Z\",\"items\":[{\"uuid\":\"${cleanup_activity_item_uuid}\",\"definitionUuid\":\"${cleanup_work_definition_uuid}\"}],\"meters\":[{\"uuid\":\"${cleanup_activity_meter_uuid}\",\"meterUuid\":\"${cleanup_meter_uuid}\",\"readingUuid\":\"${cleanup_reading_uuid}\"}]}}" \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/267ace84-cb7c-41ff-8cc0-94de71c50a43/activities?format=json')
assert_contains "$cleanup_activity_reused" '"statuscode":201' 'cleanup activity child UUID reuse'

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
