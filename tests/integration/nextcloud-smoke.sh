#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

set -Eeuo pipefail

database="${NC_SMOKE_DATABASE:-sqlite}"
case "$database" in
	sqlite|pgsql|mysql)
		;;
	*)
		echo "Unsupported NC_SMOKE_DATABASE: $database" >&2
		exit 2
		;;
esac

image="${NC_SMOKE_IMAGE:-nextcloud:34.0.5-apache}"
nc_label="${image##*:}"
nc_label="${nc_label%%-*}"
container="${NC_SMOKE_CONTAINER:-maintenance-tracker-nc${nc_label%%.*}-${database}-smoke}"
network="${NC_SMOKE_NETWORK:-${container}-network}"
db_container="${NC_SMOKE_DB_CONTAINER:-${container}-db}"
postgres_image="${NC_SMOKE_POSTGRES_IMAGE:-postgres:17-alpine}"
mariadb_image="${NC_SMOKE_MARIADB_IMAGE:-mariadb:11.4}"
# network: a user-defined bridge with container DNS (default).
# bridge: the default Docker bridge, addressing the database by container IP,
# for hosts whose firewall drops traffic on user-defined bridges.
network_mode="${NC_SMOKE_NETWORK_MODE:-network}"
case "$network_mode" in
	network|bridge)
		;;
	*)
		echo "Unsupported NC_SMOKE_NETWORK_MODE: $network_mode" >&2
		exit 2
		;;
esac
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
	docker rm --force "${container}-browser" >/dev/null 2>&1 || true
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

	for path in appinfo lib templates img js css profiles l10n; do
		if [ -e "$path" ]; then
			cp -a "$path" "$target/"
		fi
	done
	mkdir -p "$target/fitment"
	cp -a fitment/core-v1.json "$target/fitment/core-v1.json"
	cp -a LICENSE "$target/"

	test -f "$target/appinfo/info.xml"
	test -f "$target/lib/AppInfo/Application.php"
	test -f "$target/js/maintenance_tracker-main.mjs"
	test -f "$target/css/maintenance_tracker-main.css"
	test -f "$target/fitment/core-v1.json"
	test ! -e "$target/fitment/examples"
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
	db_run_args=(--rm --detach --name "$db_container")
	if [ "$network_mode" = 'network' ]; then
		docker network create "$network" >/dev/null
		created_network=1
		db_run_args+=(--network "$network")
		run_args+=(--network "$network")
	fi

	if [ "$database" = 'pgsql' ]; then
		docker run "${db_run_args[@]}" \
			--env "POSTGRES_DB=$postgres_database" \
			--env "POSTGRES_USER=$postgres_user" \
			--env "POSTGRES_PASSWORD=$postgres_password" \
			"$postgres_image" >/dev/null
	else
		# Nextcloud requires READ-COMMITTED isolation and row-based binlogs.
		docker run "${db_run_args[@]}" \
			--env "MARIADB_DATABASE=$postgres_database" \
			--env "MARIADB_USER=$postgres_user" \
			--env "MARIADB_PASSWORD=$postgres_password" \
			--env "MARIADB_ROOT_PASSWORD=$postgres_password-root" \
			"$mariadb_image" \
			--transaction-isolation=READ-COMMITTED --binlog-format=ROW \
			--character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci >/dev/null
	fi
	started_database=1

	db_ready=0
	for _attempt in $(seq 1 90); do
		if [ "$database" = 'pgsql' ]; then
			if docker exec "$db_container" \
				pg_isready --quiet --username "$postgres_user" --dbname "$postgres_database"; then
				db_ready=1
				break
			fi
		elif docker exec "$db_container" \
			mariadb --user="$postgres_user" --password="$postgres_password" --execute='SELECT 1' "$postgres_database" >/dev/null 2>&1; then
			db_ready=1
			break
		fi
		sleep 1
	done
	if [ "$db_ready" -ne 1 ]; then
		echo "${database} did not become ready within 90 seconds." >&2
		exit 1
	fi

	if [ "$network_mode" = 'network' ]; then
		db_host="$db_container"
	else
		db_host=$(docker inspect --format '{{.NetworkSettings.IPAddress}}' "$db_container")
	fi
	if [ "$database" = 'pgsql' ]; then
		run_args+=(
			--env "POSTGRES_DB=$postgres_database"
			--env "POSTGRES_USER=$postgres_user"
			--env "POSTGRES_PASSWORD=$postgres_password"
			--env "POSTGRES_HOST=$db_host"
		)
	else
		run_args+=(
			--env "MYSQL_DATABASE=$postgres_database"
			--env "MYSQL_USER=$postgres_user"
			--env "MYSQL_PASSWORD=$postgres_password"
			--env "MYSQL_HOST=$db_host"
		)
	fi
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

# NC_SMOKE_QUALIFY=1 force-enables the app on a Nextcloud release that
# appinfo/info.xml does not declare yet, so a new target can be exercised
# before the compatibility declaration is widened. Never use it otherwise.
enable_args=(app:enable maintenance_tracker)
if [ "${NC_SMOKE_QUALIFY:-0}" = '1' ]; then
	enable_args+=(--force)
fi
docker exec --user www-data "$container" \
	php occ "${enable_args[@]}" >/dev/null

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
assert_contains "$capabilities" '"fitment-packs"' 'capabilities'
assert_contains "$capabilities" '"fitment-csv-zip"' 'capabilities'

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

# v0.1.10 fitment-pack JSON + CSV/ZIP vertical slice. Example data is intentionally not staged into the app;
# feed it through the running Nextcloud container over stdin instead.
fitment_asset_uuid='5a7f7611-d4b8-4d9f-8e10-218b6889d2a1'
fitment_asset_name='Private Local Workhorse Nickname'
fitment_asset=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"asset\":{\"uuid\":\"${fitment_asset_uuid}\",\"category\":\"vehicle\",\"assetClass\":\"vehicle\",\"name\":\"${fitment_asset_name}\",\"manufacturer\":\"Example Motors\",\"model\":\"Workhorse 2500\",\"modelYear\":2020}}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_asset" '"statuscode":201' 'fitment target local asset create'

fitment_pack_json=$(cat fitment/examples/example-service-parts.json)
fitment_validate_payload=$(printf '%s' "$fitment_pack_json" | docker exec --interactive "$container" php -r '$p=json_decode(stream_get_contents(STDIN),true);echo json_encode(["pack"=>$p],JSON_UNESCAPED_SLASHES);')
fitment_validate=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$fitment_validate_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/validate?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_validate" '"statuscode":200' 'fitment pack validate'
assert_contains "$fitment_validate" '"valid":true' 'fitment pack validation result'
fitment_hash='1b29fad0964d9f58d25a91154340779234d2b529d7d3d7ea8b05362768d88150'
assert_contains "$fitment_validate" "\"contentHash\":\"${fitment_hash}\"" 'fitment cross-language canonical hash'

fitment_preview=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$fitment_validate_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/preview?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_preview" '"statuscode":200' 'fitment pack preview'
assert_contains "$fitment_preview" '"importable":true' 'fitment pack preview importable'
assert_contains "$fitment_preview" "\"uuid\":\"${fitment_asset_uuid}\"" 'fitment preview local asset suggestion'
assert_contains "$fitment_preview" '"state":"candidate"' 'fitment missing required qualifier candidate state'

fitment_import_uuid='6b8e8722-e5c9-4ea0-9f21-329c799ae3b2'
fitment_import_payload=$(printf '%s' "$fitment_pack_json" | docker exec --interactive --env IMPORT_UUID="$fitment_import_uuid" "$container" php -r '$p=json_decode(stream_get_contents(STDIN),true);echo json_encode(["importUuid"=>getenv("IMPORT_UUID"),"pack"=>$p],JSON_UNESCAPED_SLASHES);')
fitment_import=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$fitment_import_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/import?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_import" '"statuscode":201' 'fitment pack import'
assert_contains "$fitment_import" "\"importUuid\":\"${fitment_import_uuid}\"" 'fitment pack import UUID'
assert_contains "$fitment_import" "\"contentHash\":\"${fitment_hash}\"" 'fitment imported canonical hash'

fitment_import_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$fitment_import_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/import?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_import_retry" '"statuscode":201' 'fitment pack idempotent import retry'
assert_contains "$fitment_import_retry" "\"importUuid\":\"${fitment_import_uuid}\"" 'fitment import retry identity'

fitment_second_import_uuid='7c9f9833-f6da-4fb1-a032-43ad8aabf4c3'
fitment_second_import_payload=$(printf '%s' "$fitment_pack_json" | docker exec --interactive --env IMPORT_UUID="$fitment_second_import_uuid" "$container" php -r '$p=json_decode(stream_get_contents(STDIN),true);echo json_encode(["importUuid"=>getenv("IMPORT_UUID"),"pack"=>$p],JSON_UNESCAPED_SLASHES);')
fitment_second_import=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$fitment_second_import_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/import?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_second_import" '"statuscode":412' 'fitment revision second import UUID rejection'

fitment_source_export=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/${fitment_import_uuid}/export?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_source_export" '"statuscode":200' 'fitment source export'
assert_contains "$fitment_source_export" '"key":"example-oil-100-store"' 'fitment source export retains offers'
assert_contains "$fitment_source_export" "\"contentHash\":\"${fitment_hash}\"" 'fitment source export hash'
docker exec "$container" php -r 'if (!extension_loaded("zip") || !class_exists("ZipArchive")) { fwrite(STDERR, "PHP zip extension unavailable\n"); exit(1); }'
fitment_source_bundle='/tmp/maintenance-tracker-source.fitment.zip'
fitment_source_bundle_status=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/zip' \
	--output "$fitment_source_bundle" --write-out '%{http_code}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/${fitment_import_uuid}/bundle?workspace=${admin_workspace_uuid}")
[ "$fitment_source_bundle_status" = '200' ] || { echo "fitment source bundle export expected HTTP 200, got ${fitment_source_bundle_status}" >&2; exit 1; }
fitment_source_bundle_entries=$(docker exec --env BUNDLE="$fitment_source_bundle" "$container" php -r '
$z=new ZipArchive(); if($z->open(getenv("BUNDLE"), ZipArchive::RDONLY)!==true){exit(2);} $n=[]; for($i=0;$i<$z->numFiles;$i++){$n[]=$z->getNameIndex($i);} $z->close(); sort($n,SORT_STRING); echo implode(",",$n);')
assert_contains "$fitment_source_bundle_entries" 'equipment_identifiers.csv' 'fitment source bundle identifier table'
assert_contains "$fitment_source_bundle_entries" 'manifest.json' 'fitment source bundle manifest'
assert_contains "$fitment_source_bundle_entries" 'fitment_evidence.csv' 'fitment source bundle evidence table'

fitment_bundle_validate=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "bundle=@${fitment_source_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/validate?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_bundle_validate" '"statuscode":200' 'fitment ZIP bundle validate'
assert_contains "$fitment_bundle_validate" "\"contentHash\":\"${fitment_hash}\"" 'fitment ZIP bundle canonical hash'

fitment_bundle_preview=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "bundle=@${fitment_source_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/preview?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_bundle_preview" '"statuscode":200' 'fitment ZIP bundle preview'
assert_contains "$fitment_bundle_preview" "\"contentHash\":\"${fitment_hash}\"" 'fitment ZIP preview canonical hash'

fitment_bundle_second_import=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "importUuid=${fitment_second_import_uuid}" \
	--form "bundle=@${fitment_source_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/import?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_bundle_second_import" '"statuscode":412' 'fitment cross-representation second import UUID rejection'

fitment_unsafe_bundle='/tmp/maintenance-tracker-unsafe.fitment.zip'
docker exec --env SOURCE="$fitment_source_bundle" --env TARGET="$fitment_unsafe_bundle" "$container" php -r '
if(!copy(getenv("SOURCE"),getenv("TARGET"))){exit(2);} $z=new ZipArchive(); if($z->open(getenv("TARGET"))!==true){exit(3);} if(!$z->addFromString("../unexpected.csv","bad\n")){exit(4);} if(!$z->close()){exit(5);}'
fitment_unsafe_validate=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "bundle=@${fitment_unsafe_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/validate?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_unsafe_validate" '"statuscode":400' 'fitment ZIP traversal entry rejection'


fitment_targets=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/${fitment_import_uuid}/targets?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_targets" '"statuscode":200' 'fitment import target discovery'
fitment_target_uuid=$(printf '%s' "$fitment_targets" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["ocs"]["data"]["items"][0]["target"]["uuid"]??"";')
[[ "$fitment_target_uuid" =~ ^[0-9a-f-]{36}$ ]] || { echo "fitment target UUID missing: ${fitment_target_uuid}" >&2; exit 1; }

fitment_matches=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-targets/${fitment_target_uuid}/matches?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_matches" "\"uuid\":\"${fitment_asset_uuid}\"" 'fitment explicit match local asset'
fitment_match_state=$(printf '%s' "$fitment_matches" | docker exec --interactive --env ASSET_UUID="$fitment_asset_uuid" "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);foreach(($d["ocs"]["data"]["items"]??[]) as $i){if(($i["asset"]["uuid"]??"")===getenv("ASSET_UUID")){echo $i["state"]??"";}}')
[ "$fitment_match_state" = 'candidate' ] || { echo "fitment match expected candidate, got: ${fitment_match_state}" >&2; exit 1; }

fitment_mapping_uuid='8daf0944-a7eb-4ac2-b143-54be9bbcf5d4'
fitment_mapping=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"mappingUuid\":\"${fitment_mapping_uuid}\",\"assetUuid\":\"${fitment_asset_uuid}\"}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-targets/${fitment_target_uuid}/map?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_mapping" '"statuscode":201' 'fitment explicit mapping'
assert_contains "$fitment_mapping" '"matchState":"candidate"' 'fitment explicit mapping candidate state'

fitment_mapping_retry=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"mappingUuid\":\"${fitment_mapping_uuid}\",\"assetUuid\":\"${fitment_asset_uuid}\"}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-targets/${fitment_target_uuid}/map?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_mapping_retry" '"statuscode":201' 'fitment mapping idempotent retry'

fitment_second_mapping_uuid='9eb01a55-b8fc-4bd3-8254-65cfaccd06e5'
fitment_second_mapping=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"mappingUuid\":\"${fitment_second_mapping_uuid}\",\"assetUuid\":\"${fitment_asset_uuid}\"}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-targets/${fitment_target_uuid}/map?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_second_mapping" '"statuscode":412' 'fitment active mapping UUID alias rejection'

fitment_asset_facts=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${fitment_asset_uuid}/fitments?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_asset_facts" '"statuscode":200' 'asset fitment facts read'
fitment_fact_count=$(printf '%s' "$fitment_asset_facts" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);echo count($d["ocs"]["data"]["items"]??[]);')
[ "$fitment_fact_count" = '5' ] || { echo "fitment fact count expected 5, got ${fitment_fact_count}" >&2; exit 1; }
assert_contains "$fitment_asset_facts" '"key":"engine.oil_filter"' 'standardized oil-filter slot fact'
assert_contains "$fitment_asset_facts" '"partNumber":"OIL-100"' 'oil-filter compatible part fact'

fitment_community_payload='{"export":{"pack":{"id":"org.example.community.workhorse2500","version":"1.0.0","name":"Community Workhorse 2500 fitments","description":"Generalized compatibility facts exported by the integration test.","dataLicense":"CC0-1.0","provenance":{"author":"Integration Test","sourceUrl":"https://example.invalid/community-fitments"}},"equipment":{"key":"workhorse2500-community","assetClass":"vehicle","manufacturer":"Example Motors","model":"Workhorse 2500","yearFrom":2020,"yearTo":2020,"aliases":{"manufacturer":[],"model":[]},"identifiers":[],"qualifiers":[{"key":"engine","value":"6.7L Example Diesel","aliases":[],"match":"required"}]}}}'
fitment_community_export=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$fitment_community_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${fitment_asset_uuid}/fitment-export/community?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_community_export" '"statuscode":200' 'fitment community export'
assert_contains "$fitment_community_export" '"offers":[]' 'fitment community export offer selection policy'
assert_contains "$fitment_community_export" '"offersPolicy":"omitted_pending_explicit_selection"' 'fitment community export policy marker'
assert_not_contains "$fitment_community_export" "$fitment_asset_uuid" 'fitment community export local UUID privacy'
assert_not_contains "$fitment_community_export" "$fitment_asset_name" 'fitment community export local nickname privacy'
fitment_community_hash=$(printf '%s' "$fitment_community_export" | docker exec --interactive "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["ocs"]["data"]["contentHash"]??"";')
[[ "$fitment_community_hash" =~ ^[0-9a-f]{64}$ ]] || { echo "fitment community hash missing: ${fitment_community_hash}" >&2; exit 1; }
fitment_community_bundle='/tmp/maintenance-tracker-community.fitment.zip'
fitment_community_bundle_status=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/zip' --header 'Content-Type: application/json' \
	--data "$fitment_community_payload" \
	--output "$fitment_community_bundle" --write-out '%{http_code}' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${fitment_asset_uuid}/fitment-export/community/bundle?workspace=${admin_workspace_uuid}")
[ "$fitment_community_bundle_status" = '200' ] || { echo "fitment community bundle export expected HTTP 200, got ${fitment_community_bundle_status}" >&2; exit 1; }

fitment_community_bundle_validate=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "bundle=@${fitment_community_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/validate?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_community_bundle_validate" '"statuscode":200' 'fitment community ZIP validate'
assert_contains "$fitment_community_bundle_validate" "\"contentHash\":\"${fitment_community_hash}\"" 'fitment community ZIP canonical hash'

fitment_community_import_uuid='afc12b66-c9ad-4ce4-9365-76dfbdde17f6'
fitment_community_bundle_import=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "importUuid=${fitment_community_import_uuid}" \
	--form "bundle=@${fitment_community_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/import?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$fitment_community_bundle_import" '"statuscode":201' 'fitment community ZIP import'
assert_contains "$fitment_community_bundle_import" "\"importUuid\":\"${fitment_community_import_uuid}\"" 'fitment community ZIP import UUID'
assert_contains "$fitment_community_bundle_import" "\"contentHash\":\"${fitment_community_hash}\"" 'fitment community ZIP imported canonical hash'


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


contributor_fitment_read=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/assets/${fitment_asset_uuid}/fitments?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_fitment_read" '"statuscode":200' 'contributor fitment read'
assert_contains "$contributor_fitment_read" '"partNumber":"OIL-100"' 'contributor fitment data read'

contributor_fitment_import_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "$fitment_second_import_payload" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/import?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_fitment_import_denied" '"statuscode":403' 'contributor fitment import rejection'
contributor_fitment_bundle_validate=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "bundle=@${fitment_source_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/validate?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_fitment_bundle_validate" '"statuscode":200' 'contributor fitment bundle validation read'

contributor_fitment_bundle_import_uuid='b0d23c77-dabe-4df5-a476-87efceef28a7'
contributor_fitment_bundle_import_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	--form "importUuid=${contributor_fitment_bundle_import_uuid}" \
	--form "bundle=@${fitment_source_bundle};type=application/zip" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-packs/bundle/import?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_fitment_bundle_import_denied" '"statuscode":403' 'contributor fitment bundle import rejection'


contributor_fitment_map_denied=$(docker exec "$container" curl --silent --show-error \
	--user "${collab_user}:${collab_password}" --request POST \
	--header 'OCS-APIRequest: true' --header 'Accept: application/json' --header 'Content-Type: application/json' \
	--data "{\"mappingUuid\":\"${fitment_second_mapping_uuid}\",\"assetUuid\":\"${fitment_asset_uuid}\"}" \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/fitment-targets/${fitment_target_uuid}/map?workspace=${admin_workspace_uuid}&format=json")
assert_contains "$contributor_fitment_map_denied" '"statuscode":403' 'contributor fitment mapping rejection'


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


fitment_audit=$(docker exec "$container" curl --silent --show-error \
	--user "${admin_user}:${admin_password}" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
	"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/audit?workspace=${admin_workspace_uuid}&limit=100&format=json")
assert_contains "$fitment_audit" '"eventType":"fitment_pack.imported"' 'fitment import audit event'
assert_contains "$fitment_audit" "\"subjectId\":\"${fitment_import_uuid}\"" 'fitment import audit subject'
assert_contains "$fitment_audit" "\"contentHash\":\"${fitment_hash}\"" 'fitment import audit hash'
assert_contains "$fitment_audit" '"eventType":"fitment_mapping.created"' 'fitment mapping audit event'
assert_contains "$fitment_audit" "\"subjectId\":\"${fitment_mapping_uuid}\"" 'fitment mapping audit subject'
assert_contains "$fitment_audit" '"targetKey":"workhorse2500-2020-diesel"' 'fitment mapping bounded target detail'
assert_contains "$fitment_audit" '"matchState":"candidate"' 'fitment mapping bounded state detail'

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

# v0.1.10 completion: canonical parts, vendors, activity parts, profile parts,
# cost ledger, and outside-link policy. A dedicated contributor account
# qualifies the role boundaries against the owner's shared workspace.
v0110_user='v0110_contributor'
v0110_password='v0110-contributor-only'
docker exec --env OC_PASS="$v0110_password" --user www-data "$container" \
	php occ user:add --password-from-env "$v0110_user" >/dev/null

ocs_as() {
	local credentials=$1 method=$2 path=$3 body=${4:-}
	local separator='?'
	if [[ "$path" == *'?'* ]]; then
		separator='&'
	fi
	local args=(--silent --show-error --user "$credentials" --request "$method"
		--header 'OCS-APIRequest: true' --header 'Accept: application/json')
	if [ -n "$body" ]; then
		args+=(--header 'Content-Type: application/json' --data "$body")
	fi
	docker exec "$container" curl "${args[@]}" \
		"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1${path}${separator}format=json"
}
owner_ocs() { ocs_as "${admin_user}:${admin_password}" "$@"; }
contributor_ocs() { ocs_as "${v0110_user}:${v0110_password}" "$@"; }
ws="workspace=${admin_workspace_uuid}"

v0110_member=$(owner_ocs POST "/workspaces/${admin_workspace_uuid}/members" "{\"member\":{\"userUid\":\"${v0110_user}\",\"role\":\"contributor\"}}")
assert_contains "$v0110_member" '"statuscode":201' 'v0.1.10 contributor membership'

v0110_part_uuid='0b4f8a2e-6c1d-4e3f-9a5b-7c8d9e0f1a2b'
v0110_part=$(owner_ocs POST "/parts?${ws}" "{\"part\":{\"uuid\":\"${v0110_part_uuid}\",\"manufacturer\":\"Smoke Filters\",\"partNumber\":\"SF-100\",\"description\":\"Smoke oil filter\"}}")
assert_contains "$v0110_part" '"statuscode":201' 'owner part create'
v0110_part_again=$(owner_ocs POST "/parts?${ws}" '{"part":{"uuid":"1c5a9b3f-7d2e-4f4a-8b6c-8d9e0f1a2b3c","manufacturer":"  smoke filters ","partNumber":"sf-100","description":"Duplicate identity"}}')
assert_contains "$v0110_part_again" '"statuscode":200' 'part identity convergence'
assert_contains "$v0110_part_again" "\"uuid\":\"${v0110_part_uuid}\"" 'part identity convergence uuid'
v0110_part_denied=$(contributor_ocs POST "/parts?${ws}" '{"part":{"manufacturer":"X","partNumber":"Y","description":"Z"}}')
assert_contains "$v0110_part_denied" '"statuscode":403' 'contributor part create denied'
v0110_parts_read=$(contributor_ocs GET "/parts?${ws}")
assert_contains "$v0110_parts_read" "\"uuid\":\"${v0110_part_uuid}\"" 'contributor part read'
v0110_part_patch=$(owner_ocs PATCH "/parts/${v0110_part_uuid}?${ws}" '{"expectedRevision":1,"part":{"partNumber":"SF-200"}}')
assert_contains "$v0110_part_patch" '"statuscode":400' 'part identity immutable'
v0110_part_patch=$(owner_ocs PATCH "/parts/${v0110_part_uuid}?${ws}" '{"expectedRevision":1,"part":{"description":"Smoke oil filter, long life"}}')
assert_contains "$v0110_part_patch" '"revision":2' 'part description correction'
v0110_part_stale=$(owner_ocs PATCH "/parts/${v0110_part_uuid}?${ws}" '{"expectedRevision":1,"part":{"description":"stale"}}')
assert_contains "$v0110_part_stale" '"statuscode":412' 'part stale revision'

v0110_vendor_uuid='2d6b0c4a-8e3f-4a5b-9c7d-9e0f1a2b3c4d'
v0110_vendor=$(owner_ocs POST "/vendors?${ws}" "{\"vendor\":{\"uuid\":\"${v0110_vendor_uuid}\",\"name\":\"Smoke Parts Store\"}}")
assert_contains "$v0110_vendor" '"statuscode":201' 'owner vendor create'
v0110_vendor_denied=$(contributor_ocs POST "/vendors?${ws}" '{"vendor":{"name":"Denied"}}')
assert_contains "$v0110_vendor_denied" '"statuscode":403' 'contributor vendor create denied'

# 1.0 UI: vendor contact details and part catalog extras.
v0130_vendor_patch=$(owner_ocs PATCH "/vendors/${v0110_vendor_uuid}?${ws}" '{"expectedRevision":1,"vendor":{"website":"https://parts.example/","address":"1 Smoke Street","phone":"+1 (555) 010-0100","email":"orders@parts.example","contactName":"Pat Example"}}')
assert_contains "$v0130_vendor_patch" '"contactName":"Pat Example"' 'vendor contact details saved'
assert_contains "$v0130_vendor_patch" '"website":"https:\/\/parts.example\/"' 'vendor website saved'
for v0130_bad in '{"website":"http://parts.example/"}' '{"email":"not-an-email"}' '{"phone":"call me"}'; do
	v0130_bad_resp=$(owner_ocs PATCH "/vendors/${v0110_vendor_uuid}?${ws}" "{\"expectedRevision\":2,\"vendor\":${v0130_bad}}")
	assert_contains "$v0130_bad_resp" '"statuscode":400' "invalid vendor contact rejected: ${v0130_bad}"
done
v0130_vendor_restore=$(owner_ocs PATCH "/vendors/${v0110_vendor_uuid}?${ws}" '{"expectedRevision":2,"vendor":{"name":"Smoke Parts Store"}}')
assert_contains "$v0130_vendor_restore" '"email":"orders@parts.example"' 'absent vendor fields are kept'
v0130_part_extras=$(owner_ocs PATCH "/parts/${v0110_part_uuid}?${ws}" "{\"expectedRevision\":2,\"part\":{\"notes\":\"Torque 25 Nm\",\"vendorUuid\":\"${v0110_vendor_uuid}\",\"unitPrice\":\"12.50\",\"currency\":\"USD\"}}")
assert_contains "$v0130_part_extras" '"vendorName":"Smoke Parts Store"' 'part preferred vendor'
assert_contains "$v0130_part_extras" '"unitPrice":"12.50"' 'part typical price'

v0110_asset_uuid='3e7c1d5b-9f4a-4b6c-8d8e-0f1a2b3c4d5e'
v0110_asset=$(owner_ocs POST "/assets?${ws}" "{\"asset\":{\"uuid\":\"${v0110_asset_uuid}\",\"category\":\"vehicle\",\"name\":\"v0.1.10 smoke vehicle\"}}")
assert_contains "$v0110_asset" '"statuscode":201' 'v0.1.10 smoke asset'
v0130_fit=$(owner_ocs POST "/parts/${v0110_part_uuid}/fits?${ws}" "{\"expectedRevision\":3,\"assetUuid\":\"${v0110_asset_uuid}\"}")
assert_contains "$v0130_fit" '"source":"manual"' 'part fits item recorded'
assert_contains "$v0130_fit" '"revision":4' 'part fit bumps the part revision'
v0130_fit_denied=$(contributor_ocs POST "/parts/${v0110_part_uuid}/fits?${ws}" "{\"expectedRevision\":4,\"assetUuid\":\"${v0110_asset_uuid}\"}")
assert_contains "$v0130_fit_denied" '"statuscode":403' 'contributor cannot change part fits'
v0130_unfit=$(owner_ocs DELETE "/parts/${v0110_part_uuid}/fits/${v0110_asset_uuid}?${ws}&expectedRevision=4")
assert_contains "$v0130_unfit" '"fits":[]' 'part fit removed'

v0130_summary=$(contributor_ocs GET "/maintenance-forecast?${ws}")
assert_contains "$v0130_summary" '"statuscode":200' 'workspace forecast summary'
assert_contains "$v0130_summary" '"scannedAssets":' 'workspace forecast summary scans items'

# 1.0 UI: shared workspace creation/rename and item deletion.
v0130_space=$(owner_ocs POST "/workspaces" '{"name":"Smoke household"}')
assert_contains "$v0130_space" '"statuscode":201' 'shared workspace created'
assert_contains "$v0130_space" '"kind":"shared"' 'created workspace is shared'
v0130_space_uuid=$(printf '%s' "$v0130_space" | python3 -c 'import json,sys;print(json.load(sys.stdin)["ocs"]["data"]["uuid"])')
v0130_rename=$(owner_ocs PATCH "/workspaces/${v0130_space_uuid}" '{"expectedRevision":1,"name":"Smoke home"}')
assert_contains "$v0130_rename" '"name":"Smoke home"' 'workspace renamed'
v0130_rename_denied=$(contributor_ocs PATCH "/workspaces/${v0130_space_uuid}" '{"expectedRevision":2,"name":"Nope"}')
assert_not_contains "$v0130_rename_denied" '"statuscode":200' 'non-member cannot rename a workspace'
v0130_blank=$(owner_ocs POST "/workspaces" '{"name":"   "}')
assert_contains "$v0130_blank" '"statuscode":400' 'blank workspace name rejected'
v0130_del_asset=$(owner_ocs POST "/assets?workspace=${v0130_space_uuid}" '{"asset":{"category":"vehicle","name":"Delete me"}}')
v0130_del_uuid=$(printf '%s' "$v0130_del_asset" | python3 -c 'import json,sys;print(json.load(sys.stdin)["ocs"]["data"]["uuid"])')
v0130_deleted=$(owner_ocs DELETE "/assets/${v0130_del_uuid}?workspace=${v0130_space_uuid}&expectedRevision=1")
assert_contains "$v0130_deleted" '"statuscode":200' 'item deleted'
v0130_after=$(owner_ocs GET "/assets?workspace=${v0130_space_uuid}")
assert_not_contains "$v0130_after" "$v0130_del_uuid" 'deleted item no longer listed'
v0130_audit=$(owner_ocs GET "/audit?workspace=${v0130_space_uuid}&limit=20")
assert_contains "$v0130_audit" 'asset.archived' 'item deletion audited'
assert_contains "$v0130_audit" 'workspace.renamed' 'workspace rename audited'

v0110_activity_uuid='4f8d2e6c-0a5b-4c7d-9e9f-1a2b3c4d5e6f'
v0110_activity_body="{\"activity\":{\"uuid\":\"${v0110_activity_uuid}\",\"performedAt\":\"2026-10-01T12:00:00Z\",\"items\":[{\"uuid\":\"5a9e3f7d-1b6c-4d8e-8f0a-2b3c4d5e6f7a\",\"title\":\"Oil change\",\"kind\":\"maintenance\"}],\"parts\":[{\"uuid\":\"6b0f4a8e-2c7d-4e9f-9a1b-3c4d5e6f7a8b\",\"partUuid\":\"${v0110_part_uuid}\",\"quantity\":\"1\",\"unit\":\"each\",\"vendorUuid\":\"${v0110_vendor_uuid}\"},{\"uuid\":\"7c1a5b9f-3d8e-4fa0-8b2c-4d5e6f7a8b9c\",\"partUuid\":\"${v0110_part_uuid}\",\"quantity\":\"5.5\",\"unit\":\"qt\"}]}}"
v0110_activity=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/activities?${ws}" "$v0110_activity_body")
assert_contains "$v0110_activity" '"statuscode":201' 'contributor activity with parts'
assert_contains "$v0110_activity" '"quantity":"5.5"' 'activity part exact decimal quantity'
assert_contains "$v0110_activity" '"vendorName":"Smoke Parts Store"' 'activity part vendor snapshot'
assert_contains "$v0110_activity" '"description":"Smoke oil filter, long life"' 'activity part description snapshot'
v0110_activity_retry=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/activities?${ws}" "$v0110_activity_body")
assert_contains "$v0110_activity_retry" '"statuscode":201' 'activity with parts idempotent retry'
v0110_activity_changed=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/activities?${ws}" "${v0110_activity_body/5.5/6}")
assert_contains "$v0110_activity_changed" '"statuscode":412' 'activity parts changed retry conflict'
v0110_activity_bad_part=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/activities?${ws}" '{"activity":{"uuid":"8d2b6c0a-4e9f-4ab1-9c3d-5e6f7a8b9c0d","performedAt":"2026-10-01T12:00:00Z","items":[{"uuid":"9e3c7d1b-5fa0-4bc2-8d4e-6f7a8b9c0d1e","title":"x","kind":"maintenance"}],"parts":[{"uuid":"af4d8e2c-6ab1-4cd3-9e5f-7a8b9c0d1e2f","partUuid":"b05e9f3d-7bc2-4de4-8f6a-8b9c0d1e2f3a","quantity":"1","unit":"each"}]}}')
assert_contains "$v0110_activity_bad_part" '"statuscode":400' 'activity unknown part rejected'
v0110_activity_float=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/activities?${ws}" '{"activity":{"uuid":"c16fa04e-8cd3-4ef5-9a7b-9c0d1e2f3a4b","performedAt":"2026-10-01T12:00:00Z","items":[{"uuid":"d27ab15f-9de4-4f06-8b8c-0d1e2f3a4b5c","title":"x","kind":"maintenance"}],"parts":[{"uuid":"e38bc26a-aef5-4a17-9c9d-1e2f3a4b5c6d","partUuid":"0b4f8a2e-6c1d-4e3f-9a5b-7c8d9e0f1a2b","quantity":1.5,"unit":"each"}]}}')
assert_contains "$v0110_activity_float" '"statuscode":400' 'activity float quantity rejected'

v0110_profile_asset_uuid='5b0c2d4e-6f7a-4b8c-9d0e-1f2a3b4c5d6e'
v0110_profile_asset=$(owner_ocs POST "/assets?${ws}" "{\"asset\":{\"uuid\":\"${v0110_profile_asset_uuid}\",\"category\":\"vehicle\",\"name\":\"v0.1.10 profile parts vehicle\"}}")
assert_contains "$v0110_profile_asset" '"statuscode":201' 'v0.1.10 profile-parts asset'
v0110_profile_php='$p=json_decode(file_get_contents("/var/www/html/custom_apps/maintenance_tracker/profiles/generic-car.json"),true);
$p["id"]="org.argentwolf.maintenance.smoke-parts";
$p["parts"]=[
	["key"=>"cabin_filter_a","manufacturer"=>"SMOKE FILTERS","partNumber"=>"SF-100","description"=>"Profile-supplied description","offers"=>[]],
	["key"=>"cabin_filter_b","manufacturer"=>"Profile Parts Co","partNumber"=>"PP-7","description"=>"Profile-only filter","offers"=>[]],
];
foreach($p["components"] as &$c){if($c["key"]==="cabin_filter"){$c["compatiblePartKeys"]=["cabin_filter_a","cabin_filter_b"];}}unset($c);
foreach($p["workDefinitions"] as &$d){if($d["key"]==="replace_cabin_filter"){$d["compatiblePartKeys"]=["cabin_filter_b"];}}unset($d);
$mode=getenv("MODE");
if($mode==="duplicate"){$p["parts"][1]["manufacturer"]="smoke filters";$p["parts"][1]["partNumber"]="sf-100";}
if($mode==="offers"||$mode==="offers-install"){$p["id"]="org.argentwolf.maintenance.smoke-offers";$p["parts"][1]["offers"]=[["label"=>"Store","url"=>"https://example.com/pp-7"]];}
$out=["profile"=>$p];if($mode==="install"){$out["installationUuid"]="6c1d3e5f-7a8b-4c9d-8e0f-2a3b4c5d6e7f";}if($mode==="offers-install"){$out["installationUuid"]="0a5b7c9d-1e2f-4a3b-8c4d-6e7f80910213";}
echo json_encode($out,JSON_UNESCAPED_SLASHES);'
v0110_profile_payload() { docker exec --env MODE="$1" "$container" php -r "$v0110_profile_php"; }

v0110_profile_dup=$(owner_ocs POST "/assets/${v0110_profile_asset_uuid}/profiles/preview?${ws}" "$(v0110_profile_payload duplicate)")
assert_contains "$v0110_profile_dup" '"installable":false' 'profile duplicate part identity fails closed'
assert_contains "$v0110_profile_dup" 'resolve to the same canonical part identity' 'profile duplicate part identity reason'
v0110_profile_preview=$(owner_ocs POST "/assets/${v0110_profile_asset_uuid}/profiles/preview?${ws}" "$(v0110_profile_payload preview)")
assert_contains "$v0110_profile_preview" '"installable":true' 'profile with parts installable'
assert_contains "$v0110_profile_preview" '"parts":2' 'profile preview part count'
v0110_profile_install=$(owner_ocs POST "/assets/${v0110_profile_asset_uuid}/profiles/install?${ws}" "$(v0110_profile_payload install)")
assert_contains "$v0110_profile_install" '"statuscode":201' 'profile with parts install'
assert_contains "$v0110_profile_install" '"sourceType":"part"' 'profile part source binding'
assert_contains "$v0110_profile_install" "\"targetUuid\":\"${v0110_part_uuid}\"" 'profile part converges on existing canonical identity'
v0110_compat=$(contributor_ocs GET "/assets/${v0110_profile_asset_uuid}/compatible-parts?${ws}")
assert_contains "$v0110_compat" '"statuscode":200' 'compatible parts read'
assert_contains "$v0110_compat" '"subjectType":"component"' 'component compatibility fact'
assert_contains "$v0110_compat" '"subjectType":"work_definition"' 'work-definition compatibility fact'
assert_contains "$v0110_compat" '"partNumber":"PP-7"' 'profile-created canonical part'
v0110_parts_after_profile=$(owner_ocs GET "/parts?${ws}")
assert_contains "$v0110_parts_after_profile" '"description":"Smoke oil filter, long life"' 'profile does not overwrite canonical part description'

assert_contains "$capabilities" '"cost-ledger"' 'cost ledger capability'
v0110_cost_settings=$(contributor_ocs GET "/cost-settings?${ws}")
assert_contains "$v0110_cost_settings" '"taxEnabled":false' 'cost settings default no tax'
assert_contains "$v0110_cost_settings" '"multiCurrency":false' 'cost settings default single currency'
v0110_cost_uuid='7d2e4f6a-8b9c-4dae-8f01-3b4c5d6e7f80'
v0110_cost_body="{\"cost\":{\"uuid\":\"${v0110_cost_uuid}\",\"incurredOn\":\"2026-10-01\",\"description\":\"Oil filter\",\"amount\":\"12.50\",\"category\":\"parts\",\"vendorUuid\":\"${v0110_vendor_uuid}\"}}"
v0110_cost=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/costs?${ws}" "$v0110_cost_body")
assert_contains "$v0110_cost" '"statuscode":201' 'contributor adds cost'
assert_contains "$v0110_cost" '"amountMinor":1250' 'cost stored in minor units'
assert_contains "$v0110_cost" '"currency":"USD"' 'cost default currency'
v0110_cost_retry=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/costs?${ws}" "$v0110_cost_body")
assert_contains "$v0110_cost_retry" '"revision":1' 'cost create idempotent retry'
v0110_cost_float=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/costs?${ws}" '{"cost":{"uuid":"7d2e4f6a-8b9c-4dae-8f01-3b4c5d6e7f81","incurredOn":"2026-10-01","description":"Float","amount":12.5}}')
assert_contains "$v0110_cost_float" '"statuscode":400' 'cost float amount rejected'
v0110_cost_eur=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/costs?${ws}" '{"cost":{"uuid":"7d2e4f6a-8b9c-4dae-8f01-3b4c5d6e7f82","incurredOn":"2026-10-01","description":"Euro","amount":"5","currency":"EUR"}}')
assert_contains "$v0110_cost_eur" '"statuscode":400' 'single-currency workspace rejects EUR'
v0110_cost_tax=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/costs?${ws}" '{"cost":{"uuid":"7d2e4f6a-8b9c-4dae-8f01-3b4c5d6e7f83","incurredOn":"2026-10-01","description":"Tax","amount":"1","lineType":"tax"}}')
assert_contains "$v0110_cost_tax" '"statuscode":400' 'tax line rejected while disabled'
v0110_settings_denied=$(contributor_ocs PATCH "/cost-settings?${ws}" '{"expectedRevision":0,"settings":{"taxEnabled":true}}')
assert_contains "$v0110_settings_denied" '"statuscode":403' 'contributor cannot change cost settings'
v0110_cost_archive_denied=$(contributor_ocs DELETE "/costs/${v0110_cost_uuid}?${ws}" '{"expectedRevision":1}')
assert_contains "$v0110_cost_archive_denied" '"statuscode":403' 'contributor cannot archive cost'
v0110_settings=$(owner_ocs PATCH "/cost-settings?${ws}" '{"expectedRevision":0,"settings":{"taxEnabled":true,"multiCurrency":true}}')
assert_contains "$v0110_settings" '"statuscode":200' 'owner enables tax and multi-currency'
assert_contains "$v0110_settings" '"revision":1' 'cost settings revision'
v0110_cost_eur=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/costs?${ws}" '{"cost":{"uuid":"7d2e4f6a-8b9c-4dae-8f01-3b4c5d6e7f82","incurredOn":"2026-10-02","description":"Euro","amount":"5","currency":"EUR"}}')
assert_contains "$v0110_cost_eur" '"statuscode":201' 'multi-currency EUR entry'
v0110_cost_tax=$(contributor_ocs POST "/assets/${v0110_asset_uuid}/costs?${ws}" '{"cost":{"uuid":"7d2e4f6a-8b9c-4dae-8f01-3b4c5d6e7f83","incurredOn":"2026-10-01","description":"Sales tax","amount":"1.00","lineType":"tax"}}')
assert_contains "$v0110_cost_tax" '"category":"tax"' 'tax line accepted when enabled'
v0110_costs=$(contributor_ocs GET "/assets/${v0110_asset_uuid}/costs?${ws}")
assert_contains "$v0110_costs" '"currency":"EUR","exponent":2,"chargesMinor":500' 'EUR total kept separate'
assert_contains "$v0110_costs" '"currency":"USD","exponent":2,"chargesMinor":1250,"taxMinor":100,"totalMinor":1350' 'USD total with tax'
v0110_single_denied=$(owner_ocs PATCH "/cost-settings?${ws}" '{"expectedRevision":1,"settings":{"multiCurrency":false}}')
assert_contains "$v0110_single_denied" '"statuscode":400' 'cannot return to single currency while EUR entries exist'
v0110_cost_fix=$(owner_ocs PATCH "/costs/${v0110_cost_uuid}?${ws}" '{"expectedRevision":1,"cost":{"amount":"13.00"}}')
assert_contains "$v0110_cost_fix" '"amountMinor":1300' 'owner corrects cost'
v0110_cost_archive=$(owner_ocs DELETE "/costs/7d2e4f6a-8b9c-4dae-8f01-3b4c5d6e7f82?${ws}" '{"expectedRevision":1}')
assert_contains "$v0110_cost_archive" '"statuscode":200' 'owner archives cost'
v0110_cost_totals=$(contributor_ocs GET "/costs/totals?${ws}")
assert_contains "$v0110_cost_totals" '"totalMinor":1400' 'workspace totals'
assert_not_contains "$v0110_cost_totals" '"EUR"' 'archived EUR entry excluded from totals'
v0110_cost_audit=$(owner_ocs GET "/audit?${ws}&limit=100")
assert_contains "$v0110_cost_audit" 'cost_settings.updated' 'cost settings audited'
assert_contains "$v0110_cost_audit" 'cost.archived' 'cost archive audited'

assert_contains "$capabilities" '"outside-links"' 'outside links capability'
v0110_link_uuid='8e3f5a7b-9c0d-4e1f-8a2b-4c5d6e7f8091'
v0110_link=$(owner_ocs POST "/parts/${v0110_part_uuid}/links?${ws}" "{\"link\":{\"uuid\":\"${v0110_link_uuid}\",\"label\":\"Smoke store\",\"url\":\"https://example.com/sf-100?ref=mt\"}}")
assert_contains "$v0110_link" '"statuscode":201' 'part link created'
assert_contains "$v0110_link" '"source":"manual"' 'manual part link'
for v0110_bad_url in 'http://example.com/x' 'javascript:alert(1)' 'data:text/html,x' 'https://user:pass@example.com/' 'https://example.com@evil.example/' '//example.com/x'; do
	v0110_bad=$(owner_ocs POST "/parts/${v0110_part_uuid}/links?${ws}" "{\"link\":{\"label\":\"Bad\",\"url\":\"${v0110_bad_url}\"}}")
	assert_contains "$v0110_bad" '"statuscode":400' "unsafe link rejected: ${v0110_bad_url}"
done
v0110_link_denied=$(contributor_ocs POST "/vendors/${v0110_vendor_uuid}/links?${ws}" '{"link":{"label":"Vendor","url":"https://vendor.example/"}}')
assert_contains "$v0110_link_denied" '"statuscode":403' 'contributor cannot add vendor link'
v0110_vendor_link=$(owner_ocs POST "/vendors/${v0110_vendor_uuid}/links?${ws}" '{"link":{"label":"Vendor site","url":"https://vendor.example/"}}')
assert_contains "$v0110_vendor_link" '"statuscode":201' 'vendor link created'
v0110_links=$(contributor_ocs GET "/parts/${v0110_part_uuid}/links?${ws}")
assert_contains "$v0110_links" '"enabled":true' 'links enabled by default'
assert_contains "$v0110_links" 'sf-100?ref=mt' 'contributor reads part link'

v0110_offer_asset_uuid='9f4a6b8c-0d1e-4f2a-9b3c-5d6e7f809102'
v0110_offer_asset=$(owner_ocs POST "/assets?${ws}" "{\"asset\":{\"uuid\":\"${v0110_offer_asset_uuid}\",\"category\":\"vehicle\",\"name\":\"v0.1.10 profile offers vehicle\"}}")
assert_contains "$v0110_offer_asset" '"statuscode":201' 'profile offers asset'
v0110_offer_preview=$(owner_ocs POST "/assets/${v0110_offer_asset_uuid}/profiles/preview?${ws}" "$(v0110_profile_payload offers)")
assert_contains "$v0110_offer_preview" '"installable":true' 'profile offers installable when links enabled'
v0110_offer_install=$(owner_ocs POST "/assets/${v0110_offer_asset_uuid}/profiles/install?${ws}" "$(v0110_profile_payload offers-install)")
assert_contains "$v0110_offer_install" '"statuscode":201' 'profile with offers installs'
assert_contains "$v0110_offer_install" '"sourceType":"offer"' 'profile offer source binding'
v0110_pp7_uuid=$(printf '%s' "$v0110_compat" | docker exec -i "$container" php -r '$d=json_decode(stream_get_contents(STDIN),true);foreach($d["ocs"]["data"]["items"] as $f){if($f["part"]["partNumber"]==="PP-7"){echo $f["part"]["uuid"];break;}}')
v0110_pp7_links=$(contributor_ocs GET "/parts/${v0110_pp7_uuid}/links?${ws}")
assert_contains "$v0110_pp7_links" 'pp-7"' 'profile offer materialized as part link'
assert_contains "$v0110_pp7_links" '"source":"profile"' 'profile link source'

v0110_admin_page=$(docker exec "$container" curl --silent --show-error --user "${admin_user}:${admin_password}" \
	"http://127.0.0.1/index.php/settings/admin/maintenance_tracker")
assert_contains "$v0110_admin_page" 'Allow store and vendor links' 'admin outside-link settings section'
assert_contains "$v0110_admin_page" 'name="requesttoken"' 'admin settings form carries CSRF token'
v0110_admin_post_nocsrf=$(docker exec "$container" curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
	--user "${admin_user}:${admin_password}" --data 'enabled=0' \
	"http://127.0.0.1/index.php/apps/maintenance_tracker/admin/outside-links")
assert_not_contains "$v0110_admin_post_nocsrf" '303' 'admin setting POST without CSRF token is refused'
v0110_links_still=$(owner_ocs GET "/parts/${v0110_part_uuid}/links?${ws}")
assert_contains "$v0110_links_still" '"enabled":true' 'CSRF-less POST did not change the setting'
v0110_contrib_admin=$(docker exec "$container" curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
	--user "${v0110_user}:${v0110_password}" \
	"http://127.0.0.1/index.php/settings/admin/maintenance_tracker")
assert_not_contains "$v0110_contrib_admin" '200' 'non-admin cannot open admin settings'

# Toggle through the real admin form (session cookie + CSRF token) so the web
# process's APCu-backed app config cache sees the change; occ runs with a
# separate CLI cache that the web process does not observe immediately.
v0110_admin_toggle() {
	docker exec --env NC_CRED="${admin_user}:${admin_password}" --env ENABLED="$1" "$container" sh -c '
		jar=$(mktemp); base=http://127.0.0.1/index.php
		token=$(curl -s -u "$NC_CRED" -c "$jar" -b "$jar" "$base/settings/admin/maintenance_tracker" | sed -n "s/.*name=\"requesttoken\" value=\"\([^\"]*\)\".*/\1/p" | head -n 1)
		curl -s -o /dev/null -w "%{http_code}" -u "$NC_CRED" -c "$jar" -b "$jar" --data "enabled=$ENABLED" --data-urlencode "requesttoken=$token" "$base/apps/maintenance_tracker/admin/outside-links"
		rm -f "$jar"'
}
v0110_toggle_off=$(v0110_admin_toggle 0)
assert_contains "$v0110_toggle_off" '303' 'admin form disables outside links'
v0110_links_off=$(contributor_ocs GET "/parts/${v0110_part_uuid}/links?${ws}")
assert_contains "$v0110_links_off" '"enabled":false' 'links disabled flag'
assert_not_contains "$v0110_links_off" 'example.com' 'existing links hidden while disabled'
v0110_link_off=$(owner_ocs POST "/parts/${v0110_part_uuid}/links?${ws}" '{"link":{"label":"Off","url":"https://example.com/off"}}')
assert_contains "$v0110_link_off" '"statuscode":400' 'new link refused while disabled'
assert_contains "$v0110_link_off" 'disabled by the Nextcloud administrator' 'disabled link reason'
v0110_offer_off=$(owner_ocs POST "/assets/${v0110_offer_asset_uuid}/profiles/preview?${ws}" "$(v0110_profile_payload offers)")
assert_contains "$v0110_offer_off" '"installable":false' 'profile offers fail closed while links disabled'
v0110_toggle_on=$(v0110_admin_toggle 1)
assert_contains "$v0110_toggle_on" '303' 'admin form re-enables outside links'
v0110_links_on=$(contributor_ocs GET "/parts/${v0110_part_uuid}/links?${ws}")
assert_contains "$v0110_links_on" 'sf-100?ref=mt' 'links reappear when re-enabled'
v0110_link_audit=$(owner_ocs GET "/audit?${ws}&limit=100")
assert_contains "$v0110_link_audit" 'link.created' 'link creation audited'

# v0.1.11 evidence files: references into Nextcloud Files plus the
# owner-owned workspace evidence folder shared with members by role. Test files
# are synthetic placeholders; no real receipts or notes enter fixtures or logs.
json_field() { python3 -c 'import json,sys
d=json.load(sys.stdin)["ocs"]["data"]
for k in sys.argv[1].split("."): d=d[k] if d is not None else None
print("" if d is None else d)' "$1"; }
dav_as() {
	local credentials=$1 method=$2 path; shift 2
	path=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$1"); shift
	docker exec "$container" curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
		--user "$credentials" --request "$method" "$@" "http://127.0.0.1/remote.php/dav/files/${credentials%%:*}${path}"
}
v0111_png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
docker exec "$container" sh -c "echo '${v0111_png}' | base64 -d > /tmp/v0111.png"
v0111_put=$(dav_as "${admin_user}:${admin_password}" PUT /v0111-private-receipt.png --upload-file /tmp/v0111.png)
assert_contains "$v0111_put" '201' 'owner private file upload'
assert_contains "$(docker exec "$container" curl -s --user "${admin_user}:${admin_password}" -H 'OCS-APIRequest: true' -H 'Accept: application/json' 'http://127.0.0.1/ocs/v2.php/cloud/capabilities?format=json')" '"evidence-files"' 'evidence capability'

v0111_private_uuid='8a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'
v0111_private=$(owner_ocs POST "/evidence?${ws}" "{\"evidence\":{\"uuid\":\"${v0111_private_uuid}\",\"kind\":\"receipt\",\"path\":\"/v0111-private-receipt.png\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${v0110_asset_uuid}\"},{\"targetType\":\"activity\",\"targetUuid\":\"${v0110_activity_uuid}\"},{\"targetType\":\"cost\",\"targetUuid\":\"${v0110_cost_uuid}\"}]}}")
assert_contains "$v0111_private" '"statuscode":201' 'owner links existing file as evidence'
assert_contains "$v0111_private" '"availability":"available"' 'owner sees linked file'
assert_contains "$v0111_private" '"previewUrl":"' 'preview URL for image evidence'
v0111_retry=$(owner_ocs POST "/evidence?${ws}" "{\"evidence\":{\"uuid\":\"${v0111_private_uuid}\",\"kind\":\"receipt\",\"path\":\"/v0111-private-receipt.png\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${v0110_asset_uuid}\"}]}}")
assert_contains "$v0111_retry" '"statuscode":20' 'evidence create is idempotent by UUID'
v0111_dup=$(owner_ocs POST "/evidence?${ws}" "{\"evidence\":{\"uuid\":\"9b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e\",\"kind\":\"receipt\",\"path\":\"/v0111-private-receipt.png\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${v0110_asset_uuid}\"}]}}")
assert_contains "$v0111_dup" '"statuscode":412' 'same file twice is a conflict'
v0111_kind=$(owner_ocs POST "/evidence?${ws}" "{\"evidence\":{\"uuid\":\"ac3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f\",\"kind\":\"video\",\"path\":\"/v0111-private-receipt.png\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${v0110_asset_uuid}\"}]}}")
assert_contains "$v0111_kind" '"statuscode":400' 'kind/MIME mismatch rejected'
v0111_contrib_view=$(contributor_ocs GET "/evidence?${ws}&targetType=cost&targetUuid=${v0110_cost_uuid}")
assert_contains "$v0111_contrib_view" '"availability":"not_shared"' 'member never sees an unshared private file'
assert_not_contains "$v0111_contrib_view" 'v0111-private-receipt' 'unshared file path is not disclosed'
assert_contains "$v0111_contrib_view" '"file":null' 'unshared file metadata withheld'
v0111_steal=$(contributor_ocs POST "/evidence?${ws}" "{\"evidence\":{\"uuid\":\"bd4e5f6a-7b8c-4d9e-8f0a-2b3c4d5e6f7a\",\"kind\":\"receipt\",\"path\":\"/v0111-private-receipt.png\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${v0110_asset_uuid}\"}]}}")
assert_contains "$v0111_steal" '"statuscode":404' 'member cannot attach another user file by path'

v0111_target=$(contributor_ocs POST "/evidence-folder/upload-target?${ws}" "{\"target\":{\"targetType\":\"cost\",\"targetUuid\":\"${v0110_cost_uuid}\",\"fileName\":\"receipt.png\"}}")
assert_contains "$v0111_target" '"statuscode":200' 'upload target for contributor'
assert_contains "$v0111_target" '"writable":true' 'contributor share is writable'
v0111_upload_path=$(printf '%s' "$v0111_target" | json_field uploadPath)
if [ -z "$v0111_upload_path" ]; then echo 'upload target: missing uploadPath' >&2; exit 1; fi
v0111_contrib_put=$(dav_as "${v0110_user}:${v0110_password}" PUT "$v0111_upload_path" --header 'If-None-Match: *' --upload-file /tmp/v0111.png)
assert_contains "$v0111_contrib_put" '201' 'contributor uploads into shared workspace folder'
v0111_shared_uuid='ce5f6a7b-8c9d-4e0f-9a1b-3c4d5e6f7a8b'
v0111_shared=$(contributor_ocs POST "/evidence?${ws}" "{\"evidence\":{\"uuid\":\"${v0111_shared_uuid}\",\"kind\":\"receipt\",\"path\":\"${v0111_upload_path}\",\"links\":[{\"targetType\":\"cost\",\"targetUuid\":\"${v0110_cost_uuid}\"}]}}")
assert_contains "$v0111_shared" '"statuscode":201' 'contributor attaches uploaded evidence'
assert_contains "$v0111_shared" '"storage":"workspace"' 'upload stored in workspace folder'
v0111_owner_sees=$(owner_ocs GET "/evidence/${v0111_shared_uuid}?${ws}")
assert_contains "$v0111_owner_sees" '"availability":"available"' 'owner sees member upload in own folder'
assert_contains "$v0111_owner_sees" 'Maintenance Tracker' 'owner path is under app folder'

v0111_owner_path=$(printf '%s' "$v0111_owner_sees" | json_field file.path)
v0111_moved="$(dirname "$v0111_owner_path")/renamed-receipt.png"
v0111_move=$(dav_as "${admin_user}:${admin_password}" MOVE "$v0111_owner_path" --header "Destination: http://127.0.0.1/remote.php/dav/files/${admin_user}$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$v0111_moved")")
assert_contains "$v0111_move" '201' 'rename in Files'
v0111_after_move=$(contributor_ocs GET "/evidence/${v0111_shared_uuid}?${ws}")
assert_contains "$v0111_after_move" 'renamed-receipt.png' 'evidence follows rename by file id'
assert_contains "$v0111_after_move" '"availability":"available"' 'renamed evidence still available'

v0111_viewer=$(owner_ocs PATCH "/workspaces/${admin_workspace_uuid}/members/${v0110_user}" '{"member":{"role":"viewer"}}')
assert_contains "$v0111_viewer" '"statuscode":200' 'demote member to viewer'
v0111_viewer_folder=$(contributor_ocs GET "/evidence-folder?${ws}")
assert_contains "$v0111_viewer_folder" '"writable":false' 'viewer share is read-only'
v0111_viewer_attach=$(contributor_ocs POST "/evidence?${ws}" "{\"evidence\":{\"uuid\":\"df6a7b8c-9d0e-4f1a-8b2c-4d5e6f7a8b9c\",\"kind\":\"photo\",\"path\":\"/x.png\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${v0110_asset_uuid}\"}]}}")
assert_contains "$v0111_viewer_attach" '"statuscode":403' 'viewer cannot attach evidence'

v0111_delete=$(dav_as "${admin_user}:${admin_password}" DELETE /v0111-private-receipt.png)
assert_contains "$v0111_delete" '204' 'delete linked file in Files'
v0111_missing=$(owner_ocs GET "/evidence/${v0111_private_uuid}?${ws}")
assert_contains "$v0111_missing" '"availability":"missing"' 'deleted file reported missing'

v0111_audit=$(owner_ocs GET "/audit?${ws}&limit=100")
assert_contains "$v0111_audit" 'evidence.created' 'evidence creation audited'
assert_not_contains "$v0111_audit" 'renamed-receipt' 'audit carries no file names'

v0111_remove=$(owner_ocs DELETE "/workspaces/${admin_workspace_uuid}/members/${v0110_user}")
assert_contains "$v0111_remove" '"statuscode":200' 'remove evidence member'
v0111_revoked=$(dav_as "${v0110_user}:${v0110_password}" PROPFIND "$(dirname "$v0111_upload_path")" --header 'Depth: 0')
assert_contains "$v0111_revoked" '404' 'membership removal revokes folder share'

# Workspace ownership transfer: offer/accept with quota acknowledgement, the
# evidence folder moving to the new owner with references re-pointed and member
# shares re-created, decline/cancel, auto-transfer to the longest-standing
# Manager on owner account deletion, and orphaning plus admin assignment when
# no Manager remains. Users and files are synthetic placeholders.
for xfer_user in xfer_a xfer_b xfer_c xfer_d; do
	docker exec --env OC_PASS="${xfer_user}-test-only" --user www-data "$container" \
		php occ user:add --password-from-env "$xfer_user" >/dev/null
done
xa="xfer_a:xfer_a-test-only"; xb="xfer_b:xfer_b-test-only"; xc="xfer_c:xfer_c-test-only"; xd="xfer_d:xfer_d-test-only"
xfer_ws_list=$(ocs_as "$xa" GET /workspaces)
xfer_ws=$(printf '%s' "$xfer_ws_list" | python3 -c 'import json,sys;print(json.load(sys.stdin)["ocs"]["data"]["items"][0]["uuid"])')
xws="workspace=${xfer_ws}"
xfer_asset_uuid='a1b2c3d4-0000-4000-8000-00000000a001'
xfer_asset=$(ocs_as "$xa" POST "/assets?${xws}" "{\"asset\":{\"uuid\":\"${xfer_asset_uuid}\",\"category\":\"vehicle\",\"assetClass\":\"vehicle\",\"name\":\"Transfer test truck\"}}")
assert_contains "$xfer_asset" '"statuscode":201' 'transfer workspace asset'
assert_contains "$(ocs_as "$xa" POST "/workspaces/${xfer_ws}/members" '{"member":{"userUid":"xfer_b","role":"manager"}}')" '"statuscode":201' 'transfer manager member'
assert_contains "$(ocs_as "$xa" POST "/workspaces/${xfer_ws}/members" '{"member":{"userUid":"xfer_c","role":"contributor"}}')" '"statuscode":201' 'transfer contributor member'
xfer_target=$(ocs_as "$xa" POST "/evidence-folder/upload-target?${xws}" "{\"target\":{\"targetType\":\"asset\",\"targetUuid\":\"${xfer_asset_uuid}\",\"fileName\":\"photo.png\"}}")
xfer_path=$(printf '%s' "$xfer_target" | json_field uploadPath)
assert_contains "$(dav_as "$xa" PUT "$xfer_path" --header 'If-None-Match: *' --upload-file /tmp/v0111.png)" '201' 'owner upload before transfer'
xfer_ev_uuid='a1b2c3d4-0000-4000-8000-00000000e001'
assert_contains "$(ocs_as "$xa" POST "/evidence?${xws}" "{\"evidence\":{\"uuid\":\"${xfer_ev_uuid}\",\"kind\":\"photo\",\"path\":\"${xfer_path}\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${xfer_asset_uuid}\"}]}}")" '"statuscode":201' 'evidence before transfer'
assert_contains "$(ocs_as "$xc" GET "/evidence/${xfer_ev_uuid}?${xws}")" '"availability":"available"' 'member sees evidence before transfer'

assert_contains "$(ocs_as "$xb" POST "/workspaces/${xfer_ws}/ownership-transfers" '{"transfer":{"toUid":"xfer_c"}}')" '"statuscode":403' 'non-owner cannot offer ownership'
assert_contains "$(ocs_as "$xa" POST "/workspaces/${xfer_ws}/ownership-transfers" '{"transfer":{"toUid":"xfer_d"}}')" '"statuscode":400' 'ownership only to existing members'
xfer_offer=$(ocs_as "$xa" POST "/workspaces/${xfer_ws}/ownership-transfers" '{"transfer":{"toUid":"xfer_b","expiresInDays":7}}')
assert_contains "$xfer_offer" '"statuscode":201' 'owner offers ownership'
assert_contains "$xfer_offer" '"status":"pending"' 'offer is pending'
assert_contains "$xfer_offer" '"folderBytes":' 'offer shows folder size'
assert_not_contains "$xfer_offer" 'recipientFreeBytes' 'offerer never sees recipient quota'
xfer_uuid=$(printf '%s' "$xfer_offer" | json_field uuid)
assert_contains "$(ocs_as "$xa" POST "/workspaces/${xfer_ws}/ownership-transfers" '{"transfer":{"toUid":"xfer_c"}}')" '"statuscode":412' 'one open transfer per workspace'
xfer_incoming=$(ocs_as "$xb" GET /ownership-transfers)
assert_contains "$xfer_incoming" "\"uuid\":\"${xfer_uuid}\"" 'recipient sees incoming offer'
assert_contains "$xfer_incoming" '"recipientFreeBytes"' 'recipient sees own quota'
assert_not_contains "$(ocs_as "$xc" GET /ownership-transfers)" "$xfer_uuid" 'other members do not see the offer'
assert_contains "$(ocs_as "$xc" POST "/ownership-transfers/${xfer_uuid}/accept" '{"acknowledgeQuota":true}')" '"statuscode":404' 'only the recipient can accept'
assert_contains "$(ocs_as "$xb" POST "/ownership-transfers/${xfer_uuid}/accept" '{"acknowledgeQuota":false}')" 'acknowledgeQuota' 'acceptance requires quota acknowledgement'
xfer_accept=$(ocs_as "$xb" POST "/ownership-transfers/${xfer_uuid}/accept" '{"acknowledgeQuota":true}')
assert_contains "$xfer_accept" '"statuscode":200' 'recipient accepts'
assert_contains "$xfer_accept" '"status":"completed"' 'transfer completes'
xfer_b_ws=$(ocs_as "$xb" GET /workspaces)
assert_contains "$xfer_b_ws" "\"uuid\":\"${xfer_ws}\",\"name\":\"Personal maintenance\",\"kind\":\"shared\",\"ownerUid\":\"xfer_b\"" 'workspace now owned by recipient'
assert_contains "$(ocs_as "$xa" GET /workspaces)" '"role":"manager"' 'previous owner becomes Manager'
xfer_b_ev=$(ocs_as "$xb" GET "/evidence/${xfer_ev_uuid}?${xws}")
assert_contains "$xfer_b_ev" '"availability":"available"' 'new owner sees moved evidence'
assert_contains "$xfer_b_ev" 'Maintenance Tracker' 'evidence now in new owner Files'
xfer_b_folder=$(ocs_as "$xb" GET "/evidence-folder?${xws}")
assert_contains "$xfer_b_folder" '"available":true' 'new owner has the folder'
assert_contains "$(ocs_as "$xc" GET "/evidence/${xfer_ev_uuid}?${xws}")" '"availability":"available"' 'member share re-created from new owner'
assert_contains "$(ocs_as "$xa" GET "/evidence-folder?${xws}")" '"writable":true' 'previous owner reaches folder as Manager'
xfer_audit=$(ocs_as "$xb" GET "/audit?${xws}&limit=100")
assert_contains "$xfer_audit" 'workspace.ownership.offered' 'offer audited'
assert_contains "$xfer_audit" 'workspace.ownership.transferred' 'transfer audited'

xfer_decline=$(ocs_as "$xb" POST "/workspaces/${xfer_ws}/ownership-transfers" '{"transfer":{"toUid":"xfer_c"}}')
xfer_decline_uuid=$(printf '%s' "$xfer_decline" | json_field uuid)
assert_contains "$(ocs_as "$xc" POST "/ownership-transfers/${xfer_decline_uuid}/decline")" '"status":"declined"' 'recipient declines'
xfer_cancel=$(ocs_as "$xb" POST "/workspaces/${xfer_ws}/ownership-transfers" '{"transfer":{"toUid":"xfer_c"}}')
xfer_cancel_uuid=$(printf '%s' "$xfer_cancel" | json_field uuid)
assert_contains "$(ocs_as "$xb" DELETE "/ownership-transfers/${xfer_cancel_uuid}")" '"status":"cancelled"' 'owner cancels offer'

# Owner account deletion with a Manager present: auto-transfer, files kept.
docker exec --user www-data "$container" php occ user:delete xfer_b >/dev/null
xfer_a_ws=$(ocs_as "$xa" GET /workspaces)
assert_contains "$xfer_a_ws" "\"uuid\":\"${xfer_ws}\",\"name\":\"Personal maintenance\",\"kind\":\"shared\",\"ownerUid\":\"xfer_a\"" 'owner deletion transfers to longest-standing Manager'
assert_contains "$(ocs_as "$xa" GET "/evidence/${xfer_ev_uuid}?${xws}")" '"availability":"available"' 'evidence survives owner deletion'
assert_contains "$(ocs_as "$xc" GET "/evidence/${xfer_ev_uuid}?${xws}")" '"availability":"available"' 'member share restored after owner deletion'

# Owner deletion without a Manager: orphaned for an administrator, not purged.
xfer_d_ws=$(ocs_as "$xd" GET /workspaces | python3 -c 'import json,sys;print(json.load(sys.stdin)["ocs"]["data"]["items"][0]["uuid"])')
assert_contains "$(ocs_as "$xd" POST "/workspaces/${xfer_d_ws}/members" '{"member":{"userUid":"xfer_c","role":"contributor"}}')" '"statuscode":201' 'orphan candidate member'
docker exec --user www-data "$container" php occ user:delete xfer_d >/dev/null
xfer_orphan=$(ocs_as "$xc" GET /workspaces)
assert_contains "$xfer_orphan" "\"uuid\":\"${xfer_d_ws}\"" 'workspace kept after owner deletion'
assert_contains "$xfer_orphan" '"orphaned":true' 'workspace marked orphaned'
assert_contains "$(docker exec --user www-data "$container" php occ maintenance_tracker:workspace:list-orphaned)" "$xfer_d_ws" 'occ lists orphaned workspace'
xfer_assign=$(docker exec --user www-data "$container" php occ maintenance_tracker:workspace:assign-owner "$xfer_d_ws" xfer_c)
assert_contains "$xfer_assign" 'completed' 'occ assigns owner to orphaned workspace'
assert_contains "$(ocs_as "$xc" GET /workspaces)" "\"uuid\":\"${xfer_d_ws}\",\"name\":\"Personal maintenance\",\"kind\":\"shared\",\"ownerUid\":\"xfer_c\",\"orphaned\":false,\"role\":\"owner\"" 'assigned owner holds the workspace'
assert_contains "$(docker exec --user www-data "$container" php occ maintenance_tracker:workspace:list-orphaned)" 'No orphaned workspaces' 'no orphans remain'

# v0.1.12 integrations: audit-projected Activity, ownership-transfer and
# due/overdue notifications, background offer expiry, and idempotent
# projection of due dates into a user-selected writable calendar. Users,
# tasks and calendars are synthetic placeholders.
for ntf_user in ntf_a ntf_b; do
	docker exec --env OC_PASS="${ntf_user}-test-only" --user www-data "$container" \
		php occ user:add --password-from-env "$ntf_user" >/dev/null
done
na="ntf_a:ntf_a-test-only"; nb="ntf_b:ntf_b-test-only"
run_integrations() { docker exec --user www-data "$container" php occ maintenance_tracker:integrations:run; }
# Start from a drained projection so later counts belong to this section.
run_integrations >/dev/null
ntf_ws=$(ocs_as "$na" GET /workspaces | python3 -c 'import json,sys;print(json.load(sys.stdin)["ocs"]["data"]["items"][0]["uuid"])')
nws="workspace=${ntf_ws}"
ntf_asset='a1b2c3d4-0000-4000-8000-00000000b001'
ntf_def='a1b2c3d4-0000-4000-8000-00000000b002'
ntf_done=$(date -u -d '-3 day' +%Y-%m-%d)
ntf_due=$(date -u -d '-2 day' +%Y%m%d)
assert_contains "$(ocs_as "$na" POST "/assets?${nws}" "{\"asset\":{\"uuid\":\"${ntf_asset}\",\"category\":\"vehicle\",\"assetClass\":\"vehicle\",\"name\":\"Reminder test van\"}}")" '"statuscode":201' 'integration asset'
assert_contains "$(ocs_as "$na" POST "/workspaces/${ntf_ws}/members" '{"member":{"userUid":"ntf_b","role":"contributor"}}')" '"statuscode":201' 'integration member'
assert_contains "$(ocs_as "$na" POST "/assets/${ntf_asset}/work-definitions?${nws}" "{\"definition\":{\"uuid\":\"${ntf_def}\",\"key\":\"wiper_check\",\"title\":\"Wiper check\",\"kind\":\"inspection\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"calendar\",\"interval\":{\"value\":1,\"unit\":\"day\"}}]}}}")" '"statuscode":201' 'integration calendar task'
assert_contains "$(ocs_as "$na" POST "/assets/${ntf_asset}/activities?${nws}" "{\"activity\":{\"uuid\":\"a1b2c3d4-0000-4000-8000-00000000b003\",\"performedAt\":\"${ntf_done}T12:00:00Z\",\"summary\":\"Checked\",\"items\":[{\"uuid\":\"a1b2c3d4-0000-4000-8000-00000000b004\",\"definitionUuid\":\"${ntf_def}\"}]}}")" '"statuscode":201' 'integration overdue baseline'

# Calendar selection: only the user's own writable calendars are accepted.
docker exec --user www-data "$container" php occ dav:create-calendar ntf_a maintenance >/dev/null
assert_contains "$(ocs_as "$na" GET /integrations/calendars)" '"uri":"maintenance"' 'writable calendar listed'
assert_contains "$(ocs_as "$na" PUT /integrations/settings '{"settings":{"calendarUri":"not-a-calendar"}}')" '"statuscode":400' 'unknown calendar rejected'
assert_contains "$(ocs_as "$na" PUT /integrations/settings '{"settings":{"calendarUri":"maintenance"}}')" '"calendarUri":"maintenance"' 'calendar selected'
assert_contains "$(ocs_as "$nb" GET /integrations/settings)" '"reminders":true' 'reminders default on'
assert_contains "$(docker exec "$container" curl -s --user "$na" -H 'OCS-APIRequest: true' -H 'Accept: application/json' 'http://127.0.0.1/ocs/v2.php/cloud/capabilities?format=json')" '"calendar-projection"' 'integration capability'

ntf_run=$(run_integrations)
assert_contains "$ntf_run" 'notifications=2' 'overdue reminder for both members'
assert_contains "$ntf_run" 'calendarEvents=1' 'due date projected once'
ntf_run_again=$(run_integrations)
assert_contains "$ntf_run_again" 'projected=0 ' 'audit projection is at-most-once'
assert_contains "$ntf_run_again" 'notifications=0 withdrawn=0 calendarEvents=0' 'reminders and calendar are idempotent'
notifications_of() { docker exec "$container" curl -s --user "$1" -H 'OCS-APIRequest: true' -H 'Accept: application/json' 'http://127.0.0.1/ocs/v2.php/apps/notifications/api/v2/notifications?format=json'; }
activity_of() { docker exec "$container" curl -s --user "$1" -H 'OCS-APIRequest: true' -H 'Accept: application/json' 'http://127.0.0.1/ocs/v2.php/apps/activity/api/v2/activity/maintenance_tracker?format=json&limit=50'; }
assert_contains "$(notifications_of "$nb")" 'Maintenance overdue: Wiper check on Reminder test van' 'member overdue notification'
ntf_b_activity=$(activity_of "$nb")
assert_contains "$ntf_b_activity" 'added you to Personal maintenance as contributor' 'membership activity'
assert_contains "$ntf_b_activity" 'recorded maintenance in Personal maintenance' 'maintenance activity'
assert_not_contains "$ntf_b_activity" 'Checked' 'activity never copies record text'
ntf_cal=$(docker exec "$container" curl -s --user "$na" -X PROPFIND -H 'Depth: 1' 'http://127.0.0.1/remote.php/dav/calendars/ntf_a/maintenance/')
assert_contains "$ntf_cal" 'maintenance-tracker-' 'projected event stored in selected calendar'
ntf_ics=$(docker exec "$container" curl -s --user "$na" "http://127.0.0.1/remote.php/dav/calendars/ntf_a/maintenance/$(printf '%s' "$ntf_cal" | grep -o 'maintenance-tracker-[0-9a-f]*\.ics' | head -n1)")
assert_contains "$ntf_ics" 'SUMMARY:Maintenance due' 'calendar title is neutral'
assert_contains "$ntf_ics" "DTSTART;VALUE=DATE:${ntf_due}" 'calendar date is the derived due date'
assert_not_contains "$ntf_ics" 'Wiper' 'calendar never discloses task names'

# A new completion establishes a new baseline: the overdue reminder is withdrawn
# and the next due date is projected as a new event.
assert_contains "$(ocs_as "$nb" POST "/assets/${ntf_asset}/activities?${nws}" "{\"activity\":{\"uuid\":\"a1b2c3d4-0000-4000-8000-00000000b005\",\"performedAt\":\"$(date -u +%Y-%m-%dT00:00:00Z)\",\"summary\":\"Checked again\",\"items\":[{\"uuid\":\"a1b2c3d4-0000-4000-8000-00000000b006\",\"definitionUuid\":\"${ntf_def}\"}]}}")" '"statuscode":201' 'integration new baseline'
ntf_resolved=$(run_integrations)
assert_contains "$ntf_resolved" 'calendarEvents=1' 'next due date projected'
assert_not_contains "$(notifications_of "$nb")" 'Maintenance overdue: Wiper check' 'overdue reminder withdrawn after completion'
assert_contains "$(ocs_as "$nb" PUT /integrations/settings '{"settings":{"reminders":false}}')" '"reminders":false' 'member opts out of reminders'

# Ownership offers notify the recipient; declining withdraws it and tells the offerer.
ntf_offer=$(ocs_as "$na" POST "/workspaces/${ntf_ws}/ownership-transfers" '{"transfer":{"toUid":"ntf_b"}}')
ntf_offer_uuid=$(printf '%s' "$ntf_offer" | json_field uuid)
run_integrations >/dev/null
assert_contains "$(notifications_of "$nb")" 'offered you ownership of Personal maintenance' 'transfer offer notification'
assert_contains "$(ocs_as "$nb" POST "/ownership-transfers/${ntf_offer_uuid}/decline")" '"status":"declined"' 'integration offer declined'
run_integrations >/dev/null
assert_not_contains "$(notifications_of "$nb")" 'offered you ownership' 'offer notification withdrawn'
assert_contains "$(notifications_of "$na")" 'declined ownership of Personal maintenance' 'offerer told about decline'
assert_contains "$(activity_of "$na")" 'declined ownership of Personal maintenance' 'ownership activity'

# The background job expires an offer past its deadline and notifies the offerer.
ntf_expiring=$(ocs_as "$na" POST "/workspaces/${ntf_ws}/ownership-transfers" '{"transfer":{"toUid":"ntf_b","expiresInDays":1}}')
ntf_expiring_uuid=$(printf '%s' "$ntf_expiring" | json_field uuid)
docker exec --user www-data --workdir /var/www/html "$container" php -r '
	require "lib/base.php";
	$db = \OCP\Server::get(\OCP\IDBConnection::class);
	$q = $db->getQueryBuilder();
	$q->update("maint_ws_transfers")->set("expires_at", $q->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
		->where($q->expr()->eq("uuid", $q->createNamedParameter($argv[1])))->executeStatement();
' "$ntf_expiring_uuid"
assert_contains "$(run_integrations)" 'expired=1' 'background job expires stale offer'
assert_contains "$(ocs_as "$na" GET "/ownership-transfers/${ntf_expiring_uuid}")" '"status":"expired"' 'offer marked expired'
assert_contains "$(notifications_of "$na")" 'expired' 'offerer told about expiry'
assert_not_contains "$(notifications_of "$nb")" 'offered you ownership' 'expired offer notification withdrawn'

# Workspace export: role-filtered sections, viewer-scoped evidence privacy,
# ZIP layout with CSV formula escaping, optional evidence bytes from the
# caller's own Files, and the export audit event. Data is synthetic.
tmp_export_dir="${staging_root}/export"; mkdir -p "$tmp_export_dir"
export_as() {
	docker exec "$container" curl --silent --show-error --user "$1" --header 'OCS-APIRequest: true' \
		--output "/tmp/$3" --write-out '%{http_code} %{content_type}' \
		"http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/export?$2"
}
assert_contains "$(docker exec "$container" curl -s --user "$na" -H 'OCS-APIRequest: true' -H 'Accept: application/json' 'http://127.0.0.1/ocs/v2.php/cloud/capabilities?format=json')" '"workspace-export"' 'export capability'
exp_formula_uuid='a1b2c3d4-0000-4000-8000-00000000e001'
assert_contains "$(ocs_as "$na" POST "/assets?${nws}" "{\"asset\":{\"uuid\":\"${exp_formula_uuid}\",\"category\":\"vehicle\",\"assetClass\":\"vehicle\",\"name\":\"=HYPERLINK(\\\"x\\\")\"}}")" '"statuscode":201' 'export formula-named asset'
assert_contains "$(export_as "$na" "${nws}&format=json" exp-owner.json)" '200 application/json' 'owner JSON export'
exp_owner_json=$(docker exec "$container" cat /tmp/exp-owner.json)
assert_contains "$exp_owner_json" '"format": "maintenance-tracker-export"' 'export format marker'
assert_contains "$exp_owner_json" '"audit": [' 'owner export includes audit'
assert_contains "$exp_owner_json" '"members": [' 'owner export includes members'
assert_contains "$exp_owner_json" 'Reminder test van' 'owner export includes assets'
assert_not_contains "$exp_owner_json" 'workspace_id' 'export drops internal workspace ids'
assert_not_contains "$exp_owner_json" 'write_lock_token' 'export drops lock tokens'
assert_contains "$(export_as "$nb" "${nws}&format=json" exp-member.json)" '200 application/json' 'contributor JSON export'
exp_member_json=$(docker exec "$container" cat /tmp/exp-member.json)
assert_contains "$exp_member_json" '"reason": "not_permitted_for_role"' 'contributor export reports omitted sections'
assert_not_contains "$exp_member_json" '"audit": [' 'contributor export omits audit'
assert_not_contains "$exp_member_json" '"user_uid"' 'contributor export omits members'
assert_contains "$(export_as "$na" "${nws}&format=csv" exp-bad.json)" '400' 'unknown export format rejected'
assert_contains "$(export_as "$na" "${nws}&format=json&includeFiles=1" exp-bad.json)" '400' 'files require ZIP'
docker exec --env OC_PASS=exp_x-test-only --user www-data "$container" php occ user:add --password-from-env exp_x >/dev/null
assert_contains "$(export_as "exp_x:exp_x-test-only" "${nws}&format=json" exp-bad.json)" '403' 'non-member cannot export'
assert_contains "$(ocs_as "$na" GET "/audit?${nws}&limit=5")" 'workspace.exported' 'export is audited'

# Evidence privacy: a viewer's export never names the owner's private file.
docker exec "$container" sh -c "echo '${v0111_png}' | base64 -d > /tmp/exp.png"
assert_contains "$(dav_as "$na" PUT /export-private.png --upload-file /tmp/exp.png)" '201' 'export private file upload'
assert_contains "$(ocs_as "$na" POST "/evidence?${nws}" "{\"evidence\":{\"uuid\":\"a1b2c3d4-0000-4000-8000-00000000e002\",\"kind\":\"photo\",\"path\":\"/export-private.png\",\"links\":[{\"targetType\":\"asset\",\"targetUuid\":\"${ntf_asset}\"}]}}")" '"statuscode":201' 'export private evidence'
assert_contains "$(ocs_as "$na" PATCH "/workspaces/${ntf_ws}/members/ntf_b" '{"member":{"role":"viewer"}}')" '"statuscode":200' 'export viewer role'
assert_contains "$(export_as "$nb" "${nws}&format=zip&includeFiles=1" exp-viewer.zip)" '200 application/zip' 'viewer ZIP export'
assert_contains "$(export_as "$na" "${nws}&format=zip&includeFiles=1" exp-owner.zip)" '200 application/zip' 'owner ZIP export'
docker cp "$container:/tmp/exp-viewer.zip" "$tmp_export_dir/viewer.zip" >/dev/null
docker cp "$container:/tmp/exp-owner.zip" "$tmp_export_dir/owner.zip" >/dev/null
exp_zip_report=$(python3 - "$tmp_export_dir" <<'PY'
import csv, io, json, sys, zipfile
base = sys.argv[1]
formula = "'" + '=HYPERLINK("x")'
out = []
for who in ("owner", "viewer"):
    z = zipfile.ZipFile(f"{base}/{who}.zip")
    names = z.namelist()
    manifest = json.loads(z.read("manifest.json"))
    data = json.loads(z.read("data.json"))
    assets = list(csv.DictReader(io.StringIO(z.read("csv/assets.csv").decode())))
    evidence = z.read("csv/evidence.csv").decode()
    out.append(f"{who}:files={sum(n.startswith('files/') for n in names)}")
    out.append(f"{who}:escape={manifest['csv']['spreadsheetEscape']}")
    out.append(f"{who}:formula={'ok' if any(a['name'] == formula for a in assets) else 'bad'}")
    out.append(f"{who}:named={'yes' if 'export-private' in evidence or 'export-private' in json.dumps(data) else 'no'}")
    out.append(f"{who}:audit={'yes' if 'csv/audit.csv' in names else 'no'}")
    out.append(f"{who}:urls={'yes' if 'downloadUrl' in json.dumps(data) else 'no'}")
    out.append(f"{who}:unsafe={'yes' if any(n.startswith('/') or '..' in n for n in names) else 'no'}")
print(" ".join(out))
PY
)
assert_contains "$exp_zip_report" 'owner:files=1' 'owner ZIP carries the readable evidence file'
assert_contains "$exp_zip_report" 'owner:named=yes' 'owner export names own evidence file'
assert_contains "$exp_zip_report" 'owner:audit=yes' 'owner ZIP carries audit CSV'
assert_contains "$exp_zip_report" 'owner:formula=ok' 'CSV formula cell escaped'
assert_contains "$exp_zip_report" 'owner:escape=leading-apostrophe-v1' 'manifest declares escaping'
assert_contains "$exp_zip_report" 'viewer:files=0' 'viewer ZIP carries no unshared files'
assert_contains "$exp_zip_report" 'viewer:named=no' 'viewer export never names unshared files'
assert_contains "$exp_zip_report" 'viewer:audit=no' 'viewer ZIP omits audit'
assert_contains "$exp_zip_report" 'owner:urls=no' 'export carries no session URLs'
assert_contains "$exp_zip_report" 'owner:unsafe=no' 'ZIP entry names are safe'
# Import/restore: a complete owner export becomes a new workspace owned by the
# caller with fresh UUIDs, remapped references and evidence files written to
# the caller's Files; partial or tampered exports are rejected atomically.
import_as() {
	docker exec "$container" curl --silent --show-error --user "$1" --header 'OCS-APIRequest: true' --header 'Accept: application/json' \
		--form "file=@/tmp/$2" ${3:+--form "name=$3"} \
		'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/import?format=json'
}
workspace_count() { ocs_as "$1" GET /workspaces | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["ocs"]["data"]["items"]))'; }
assert_contains "$(docker exec "$container" curl -s --user "$na" -H 'OCS-APIRequest: true' -H 'Accept: application/json' 'http://127.0.0.1/ocs/v2.php/cloud/capabilities?format=json')" '"workspace-import"' 'import capability'
imp_before=$(workspace_count "$na")
assert_contains "$(import_as "$nb" exp-viewer.zip)" '"statuscode":400' 'viewer export cannot be restored'
docker exec "$container" sh -c 'cat /tmp/exp-owner.json' > "$tmp_export_dir/tampered.json"
python3 - "$tmp_export_dir/tampered.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1])); d["tables"]["assets"][-1]["evil_column"] = 1
json.dump(d, open(sys.argv[1], "w"))
PY
docker cp "$tmp_export_dir/tampered.json" "$container:/tmp/exp-tampered.json"
assert_contains "$(import_as "$na" exp-tampered.json)" '"statuscode":400' 'unknown column rejected'
assert_contains "$(workspace_count "$na")" "$imp_before" 'rejected import leaves no workspace behind'
imp_result=$(import_as "$na" exp-owner.zip 'Restored van')
assert_contains "$imp_result" '"statuscode":201' 'owner ZIP restored'
assert_contains "$imp_result" '"name":"Restored van"' 'restored workspace named'
assert_contains "$imp_result" '"role":"owner"' 'importer owns the restored workspace'
assert_contains "$imp_result" '"restored":1' 'evidence file restored'
assert_contains "$imp_result" '"notRestored":["members","audit"]' 'members and audit reported as not restored'
imp_ws=$(printf '%s' "$imp_result" | json_field workspace.uuid)
iws="workspace=${imp_ws}"
imp_assets=$(ocs_as "$na" GET "/assets?${iws}")
assert_contains "$imp_assets" 'Reminder test van' 'restored assets'
assert_not_contains "$imp_assets" "$ntf_asset" 'restored assets get new UUIDs'
imp_asset=$(printf '%s' "$imp_assets" | python3 -c 'import json,sys;print([a["uuid"] for a in json.load(sys.stdin)["ocs"]["data"]["items"] if a["name"]=="Reminder test van"][0])')
assert_contains "$(ocs_as "$na" GET "/assets/${imp_asset}/work-definitions?${iws}")" 'Wiper check' 'restored work definitions follow remapped asset'
assert_contains "$(ocs_as "$na" GET "/assets/${imp_asset}/activities?${iws}")" 'Checked again' 'restored activities'
assert_contains "$(ocs_as "$na" GET "/assets/${imp_asset}/maintenance-status?${iws}")" '"statuscode":200' 'due state on restored data'
imp_evidence=$(ocs_as "$na" GET "/evidence?${iws}&targetType=asset&targetUuid=${imp_asset}")
assert_contains "$imp_evidence" '"availability":"available"' 'restored evidence opens in Files'
assert_contains "$imp_evidence" 'Maintenance Tracker imports' 'restored evidence written to import folder'
assert_contains "$(ocs_as "$na" GET "/audit?${iws}&limit=5")" 'workspace.imported' 'import audited'
assert_contains "$(ocs_as "$na" GET "/assets?${nws}")" "$ntf_asset" 'original workspace untouched'
assert_contains "$(import_as "$na" exp-owner.zip)" '"statuscode":201' 'same export can be restored twice'
assert_contains "$(ocs_as "$nb" GET "/assets?${iws}")" '"statuscode":403' 'other members do not get access to the restored workspace'
docker exec "$container" rm -f /tmp/exp-tampered.json
docker exec "$container" rm -f /tmp/exp-owner.json /tmp/exp-member.json /tmp/exp-bad.json /tmp/exp-viewer.zip /tmp/exp-owner.zip /tmp/exp.png

# @@V0111_NEXT@@

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

# NC_SMOKE_BROWSER=1: open the app in headless Chromium (Playwright image on
# the Nextcloud container's network), visit every page, add a vendor and a
# part, add and delete an item, and assert there are no app console errors. HTTP 200 alone does not prove the bundles load.
if [ "${NC_SMOKE_BROWSER:-0}" = '1' ]; then
	browser_image="${NC_SMOKE_BROWSER_IMAGE:-mcr.microsoft.com/playwright:v1.64.1-noble@sha256:7852dc5a7d72f5fe458a597d690f6c42fbc8e9e26ddfa95f8e6ef552558d9bbf}"
	browser_container="${container}-browser"
	if [ ! -f node_modules/playwright-core/package.json ]; then
		echo 'NC_SMOKE_BROWSER=1 needs node_modules/playwright-core; run npm ci first.' >&2
		exit 1
	fi
	docker exec --user www-data "$container" php occ app:disable firstrunwizard >/dev/null 2>&1 || true
	docker rm --force "$browser_container" >/dev/null 2>&1 || true
	docker create --name "$browser_container" \
		--network "container:${container}" \
		--workdir /check \
		--env NC_BASE_URL=http://127.0.0.1 \
		--env "NC_USER=$admin_user" \
		--env "NC_PASSWORD=$admin_password" \
		--env NC_SCREENSHOT=/check/app.png \
		--env NC_SCREENSHOT_DIR=/check/pages \
		"$browser_image" node app-mount.mjs >/dev/null
	browser_stage="$staging_root/browser"
	mkdir -p "$browser_stage/node_modules"
	cp tests/browser/app-mount.mjs "$browser_stage/"
	printf '{"type":"module"}\n' > "$browser_stage/package.json"
	cp -a node_modules/playwright-core "$browser_stage/node_modules/"
	docker cp "$browser_stage/." "$browser_container:/check"
	browser_status=0
	docker start --attach "$browser_container" || browser_status=$?
	if [ -n "${NC_SMOKE_ARTIFACTS:-}" ]; then
		mkdir -p "$NC_SMOKE_ARTIFACTS"
		docker cp "$browser_container:/check/app.png" "$NC_SMOKE_ARTIFACTS/app-${nc_label}-${database}.png" >/dev/null 2>&1 || true
		docker cp "$browser_container:/check/pages" "$NC_SMOKE_ARTIFACTS/pages-${nc_label}-${database}" >/dev/null 2>&1 || true
	fi
	docker rm --force "$browser_container" >/dev/null 2>&1 || true
	if [ "$browser_status" -ne 0 ]; then
		echo 'Browser mount check failed.' >&2
		exit 1
	fi
fi

echo "Nextcloud ${nc_label} smoke test passed (${database})."
