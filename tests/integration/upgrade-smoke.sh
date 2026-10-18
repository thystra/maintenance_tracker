#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

# Upgrade-migration test: install an earlier released app state (default: the
# v0.1.10 completion merge), create data through its API, replace the code with
# the working tree, run `occ upgrade`, and check that every migration ran, the
# old data survived, and features added since then work on the upgraded data.
# Same environment knobs as nextcloud-smoke.sh (NC_SMOKE_DATABASE,
# NC_SMOKE_IMAGE, NC_SMOKE_NETWORK_MODE). Needs git history for the old commit.

set -Eeuo pipefail

database="${NC_SMOKE_DATABASE:-sqlite}"
case "$database" in
	sqlite|pgsql|mysql) ;;
	*) echo "Unsupported NC_SMOKE_DATABASE: $database" >&2; exit 2 ;;
esac
network_mode="${NC_SMOKE_NETWORK_MODE:-network}"
case "$network_mode" in
	network|bridge) ;;
	*) echo "Unsupported NC_SMOKE_NETWORK_MODE: $network_mode" >&2; exit 2 ;;
esac
from_commit="${NC_UPGRADE_FROM:-6fc011d}"
image="${NC_SMOKE_IMAGE:-nextcloud:34.0.5-apache}"
nc_label="${image##*:}"; nc_label="${nc_label%%-*}"
container="${NC_SMOKE_CONTAINER:-maintenance-tracker-nc${nc_label%%.*}-${database}-upgrade}"
network="${container}-network"
db_container="${container}-db"
admin="upgrade_admin:upgrade-test-only"
db_name=nextcloud; db_user=nextcloud; db_password=integration-db-only
staging_root="$(mktemp -d)"
created_network=0; started_database=0

cleanup() {
	status=$?
	if [ "$status" -ne 0 ]; then
		docker exec --user www-data "$container" sh -c 'grep -E "\"level\":[34]" /var/www/html/data/nextcloud.log 2>/dev/null | tail -n 20 | cut -c1-600' >&2 || true
	fi
	docker rm --force "$container" >/dev/null 2>&1 || true
	[ "$started_database" -eq 0 ] || docker rm --force "$db_container" >/dev/null 2>&1 || true
	[ "$created_network" -eq 0 ] || docker network rm "$network" >/dev/null 2>&1 || true
	rm -rf "$staging_root"
}
trap cleanup EXIT

fail() { echo "$1" >&2; exit 1; }
assert_contains() { [[ "$1" == *"$2"* ]] || { echo "$3: expected $2" >&2; echo "$1" >&2; exit 1; }; }

stage() { # stage <tree-ish|WORKTREE> <dir>
	local target=$2/maintenance_tracker
	mkdir -p "$target"
	if [ "$1" = WORKTREE ]; then
		cp -a appinfo lib templates img js css profiles LICENSE "$target/"
		[ ! -d l10n ] || cp -a l10n "$target/"
		mkdir -p "$target/fitment"; cp -a fitment/core-v1.json "$target/fitment/"
	else
		git rev-parse --verify --quiet "$1^{commit}" >/dev/null || fail "Upgrade base $1 is not in this checkout (fetch full history)."
		git archive --worktree-attributes "$1" appinfo lib templates img js css profiles LICENSE fitment/core-v1.json | tar -xf - -C "$target"
	fi
}

stage "$from_commit" "$staging_root/old"
stage WORKTREE "$staging_root/new"
old_version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$staging_root/old/maintenance_tracker/appinfo/info.xml")
new_version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$staging_root/new/maintenance_tracker/appinfo/info.xml")
# Intermediate states share a version string; Nextcloud only migrates when the
# version increases, so the old install is labelled as a lower version.
if [ "$old_version" = "$new_version" ]; then
	sed -i "s:<version>${old_version}</version>:<version>${old_version%.*}.0</version>:" "$staging_root/old/maintenance_tracker/appinfo/info.xml"
fi

docker rm --force "$container" "$db_container" >/dev/null 2>&1 || true
run_args=(--rm --detach --name "$container" --env "NEXTCLOUD_ADMIN_USER=${admin%%:*}" --env "NEXTCLOUD_ADMIN_PASSWORD=${admin#*:}")
if [ "$database" = sqlite ]; then
	run_args+=(--env SQLITE_DATABASE=nextcloud)
else
	db_args=(--rm --detach --name "$db_container")
	if [ "$network_mode" = network ]; then
		docker network create "$network" >/dev/null; created_network=1
		db_args+=(--network "$network"); run_args+=(--network "$network")
	fi
	if [ "$database" = pgsql ]; then
		docker run "${db_args[@]}" --env "POSTGRES_DB=$db_name" --env "POSTGRES_USER=$db_user" --env "POSTGRES_PASSWORD=$db_password" \
			"${NC_SMOKE_POSTGRES_IMAGE:-postgres:17-alpine}" >/dev/null
	else
		docker run "${db_args[@]}" --env "MARIADB_DATABASE=$db_name" --env "MARIADB_USER=$db_user" --env "MARIADB_PASSWORD=$db_password" \
			--env "MARIADB_ROOT_PASSWORD=${db_password}-root" "${NC_SMOKE_MARIADB_IMAGE:-mariadb:11.4}" \
			--transaction-isolation=READ-COMMITTED --binlog-format=ROW --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci >/dev/null
	fi
	started_database=1
	for _ in $(seq 1 90); do
		if [ "$database" = pgsql ]; then
			docker exec "$db_container" pg_isready --quiet --username "$db_user" --dbname "$db_name" && break
		else
			docker exec "$db_container" mariadb --user="$db_user" --password="$db_password" --execute='SELECT 1' "$db_name" >/dev/null 2>&1 && break
		fi
		sleep 1
	done
	db_host=$db_container
	[ "$network_mode" = network ] || db_host=$(docker inspect --format '{{.NetworkSettings.IPAddress}}' "$db_container")
	if [ "$database" = pgsql ]; then
		run_args+=(--env "POSTGRES_DB=$db_name" --env "POSTGRES_USER=$db_user" --env "POSTGRES_PASSWORD=$db_password" --env "POSTGRES_HOST=$db_host")
	else
		run_args+=(--env "MYSQL_DATABASE=$db_name" --env "MYSQL_USER=$db_user" --env "MYSQL_PASSWORD=$db_password" --env "MYSQL_HOST=$db_host")
	fi
fi
docker run "${run_args[@]}" "$image" >/dev/null

ready=0
for _ in $(seq 1 120); do
	if docker exec "$container" curl -s http://127.0.0.1/status.php 2>/dev/null | grep -q '"installed":true'; then ready=1; break; fi
	sleep 2
done
[ "$ready" -eq 1 ] || fail 'Nextcloud did not become ready.'

occ() { docker exec --user www-data "$container" php occ "$@"; }
install_app() {
	docker exec "$container" rm -rf /var/www/html/custom_apps/maintenance_tracker
	docker cp "$1/maintenance_tracker" "$container:/var/www/html/custom_apps/maintenance_tracker"
	docker exec "$container" chown -R www-data:www-data /var/www/html/custom_apps/maintenance_tracker
}
ocs() { # ocs METHOD PATH [BODY]
	local args=(--silent --show-error --user "$admin" --request "$1" --header 'OCS-APIRequest: true' --header 'Accept: application/json')
	[ -z "${3:-}" ] || args+=(--header 'Content-Type: application/json' --data "$3")
	local sep='?'; [[ "$2" != *'?'* ]] || sep='&'
	docker exec "$container" curl "${args[@]}" "http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1$2${sep}format=json"
}

install_app "$staging_root/old"
occ app:enable maintenance_tracker >/dev/null
docker exec "$container" apachectl graceful >/dev/null 2>&1
for _ in $(seq 1 30); do ocs GET /capabilities | grep -q '"statuscode":200' && break; sleep 1; done

asset=b0000000-0000-4000-8000-0000000000a1
meter=b0000000-0000-4000-8000-0000000000a2
def=b0000000-0000-4000-8000-0000000000a3
assert_contains "$(ocs POST /assets "{\"asset\":{\"uuid\":\"$asset\",\"category\":\"vehicle\",\"assetClass\":\"vehicle\",\"name\":\"Upgrade truck\",\"serialNumber\":\"UPG-1\"}}")" '"statuscode":201' 'old: asset'
assert_contains "$(ocs POST "/assets/$asset/meters" "{\"meter\":{\"uuid\":\"$meter\",\"key\":\"odometer\",\"name\":\"Odometer\",\"dimension\":\"distance\",\"displayUnit\":\"mi\",\"monotonic\":true}}")" '"statuscode":201' 'old: meter'
assert_contains "$(ocs POST "/meters/$meter/readings" '{"reading":{"uuid":"b0000000-0000-4000-8000-0000000000a4","observedAt":"2026-09-01T12:00:00Z","value":"1000","unit":"mi","source":{"type":"manual"}}}')" '"statuscode":201' 'old: reading'
assert_contains "$(ocs POST "/assets/$asset/work-definitions" "{\"definition\":{\"uuid\":\"$def\",\"key\":\"oil\",\"title\":\"Oil change\",\"kind\":\"maintenance\",\"schedule\":{\"combination\":\"any\",\"rules\":[{\"type\":\"calendar\",\"interval\":{\"value\":6,\"unit\":\"month\"}}]}}}")" '"statuscode":201' 'old: work definition'
assert_contains "$(ocs POST "/assets/$asset/activities" "{\"activity\":{\"uuid\":\"b0000000-0000-4000-8000-0000000000a5\",\"performedAt\":\"2026-09-02T12:00:00Z\",\"summary\":\"Changed oil\",\"items\":[{\"uuid\":\"b0000000-0000-4000-8000-0000000000a6\",\"definitionUuid\":\"$def\"}]}}")" '"statuscode":201' 'old: activity'
assert_contains "$(ocs POST "/assets/$asset/costs" '{"cost":{"uuid":"b0000000-0000-4000-8000-0000000000a7","incurredOn":"2026-09-02","description":"Oil","amount":"40.00","category":"parts"}}')" '"statuscode":201' 'old: cost'
executed_migrations() {
	docker exec --user www-data --workdir /var/www/html "$container" php -r '
		require "lib/base.php";
		$q = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$r = $q->select("version")->from("migrations")->where($q->expr()->eq("app", $q->createNamedParameter("maintenance_tracker")))->executeQuery();
		$v = array_column($r->fetchAllAssociative(), "version"); sort($v); echo implode("\n", $v), "\n";'
}
expected_migrations=$(ls "$staging_root/new/maintenance_tracker/lib/Migration" | sed -n 's/^Version\([0-9]*Date[0-9]*\)\.php$/\1/p' | sort)
before=$(executed_migrations)
[ "$before" != "$expected_migrations" ] || fail 'Upgrade base already has every migration; pick an older NC_UPGRADE_FROM.'

install_app "$staging_root/new"
occ upgrade --no-interaction >/dev/null
docker exec "$container" apachectl graceful >/dev/null 2>&1
assert_contains "$(occ app:list --output=json)" "\"maintenance_tracker\":\"${new_version}\"" 'upgraded app version enabled'
after=$(executed_migrations)
[ "$after" = "$expected_migrations" ] || { echo "expected migrations:"; echo "$expected_migrations"; echo "executed:"; echo "$after"; fail 'upgrade did not execute exactly the shipped migrations'; } >&2

for _ in $(seq 1 30); do ocs GET /capabilities | grep -q '"statuscode":200' && break; sleep 1; done
assert_contains "$(ocs GET /assets)" 'Upgrade truck' 'asset survives upgrade'
assert_contains "$(ocs GET "/meters/$meter/readings")" '"b0000000-0000-4000-8000-0000000000a4"' 'reading survives upgrade'
assert_contains "$(ocs GET "/assets/$asset/costs")" '"amountMinor":4000' 'cost survives upgrade'
assert_contains "$(ocs GET "/assets/$asset/maintenance-status")" '"statuscode":200' 'due state on upgraded data'
assert_contains "$(ocs GET /evidence-folder)" '"statuscode":200' 'v0.1.11 evidence on upgraded workspace'
assert_contains "$(ocs GET /integrations/settings)" '"reminders":true' 'v0.1.12 integration settings'
assert_contains "$(occ maintenance_tracker:integrations:run)" 'projected=' 'v0.1.12 projection starts on upgraded audit trail'
export_status=$(docker exec "$container" curl -s -o /tmp/upgrade-export.json -w '%{http_code}' --user "$admin" -H 'OCS-APIRequest: true' \
	'http://127.0.0.1/ocs/v2.php/apps/maintenance_tracker/api/v1/export?format=json')
assert_contains "$export_status" '200' 'v0.1.13 export of upgraded data'
assert_contains "$(docker exec "$container" cat /tmp/upgrade-export.json)" 'UPG-1' 'export carries upgraded rows'
assert_contains "$(ocs POST /assets '{"asset":{"uuid":"b0000000-0000-4000-8000-0000000000b1","category":"vehicle","assetClass":"vehicle","name":"After upgrade"}}')" '"statuscode":201' 'writes after upgrade'

# Disable and re-enable must be a no-op for the schema.
occ app:disable maintenance_tracker >/dev/null
occ app:enable maintenance_tracker >/dev/null
assert_contains "$(ocs GET /assets)" 'After upgrade' 'data survives disable/enable'

echo "Upgrade ${from_commit} (${old_version}) -> working tree (${new_version}) passed on Nextcloud ${nc_label} (${database})."
