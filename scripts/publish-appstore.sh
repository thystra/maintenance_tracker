#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Publish (or update) a Maintenance Tracker release on the Nextcloud App Store.
#
# POSTs to https://apps.nextcloud.com/api/v1/apps/releases with:
#   download  — HTTPS URL of maintenance_tracker.tar.gz (App Store fetches it)
#   signature — base64 SHA-512 signature of that exact archive (APP_SIGNING_KEY)
#   nightly   — optional; default false
#
# Required env:
#   APPSTORE_TOKEN   App Store API token (account settings → API token). Separate
#                    from Forgejo RELEASE_TOKEN / APP_SIGNING_KEY.
#
# Required args:
#   --download URL   Public HTTPS URL of the .tar.gz (e.g. Forgejo release asset)
#   --signature FILE Path to maintenance_tracker.tar.gz.sig (or a file of the
#                    base64 signature). Trailing whitespace is stripped.
#
# Optional:
#   --nightly        Mark as a nightly (replaces previous nightlies)
#   --dry-run        Print the JSON body and exit without calling the API
#   --api URL        Override API endpoint (default: apps.nextcloud.com)
#
# Exits 0 on HTTP 200/201. Does not create tags, Forgejo releases, or archives.
# The app id must already be registered on the App Store.
set -euo pipefail

api='https://apps.nextcloud.com/api/v1/apps/releases'
download=''
sig_file=''
nightly=false
dry_run=0

usage() {
	sed -n '2,28p' "$0" | sed 's/^# \?//'
	exit 2
}

while [ $# -gt 0 ]; do
	case "$1" in
		--download) download="${2:-}"; shift 2 ;;
		--signature) sig_file="${2:-}"; shift 2 ;;
		--nightly) nightly=true; shift ;;
		--dry-run) dry_run=1; shift ;;
		--api) api="${2:-}"; shift 2 ;;
		-h|--help) usage ;;
		*) echo "Unknown argument: $1" >&2; usage ;;
	esac
done

[ -n "$download" ] || { echo '--download URL is required' >&2; exit 2; }
[ -n "$sig_file" ] || { echo '--signature FILE is required' >&2; exit 2; }
[ -f "$sig_file" ] || { echo "Signature file not found: $sig_file" >&2; exit 2; }

case "$download" in
	https://*) ;;
	*) echo "Download URL must be https:// (got: $download)" >&2; exit 2 ;;
esac

signature="$(tr -d '[:space:]' < "$sig_file")"
[ -n "$signature" ] || { echo "Signature file is empty: $sig_file" >&2; exit 2; }

if [ -z "${APPSTORE_TOKEN:-}" ]; then
	echo 'APPSTORE_TOKEN is not set. Create a token at apps.nextcloud.com (account → API token) and export it.' >&2
	exit 2
fi

body="$(php -r 'echo json_encode(["download" => $argv[1], "signature" => $argv[2], "nightly" => $argv[3] === "true"], JSON_UNESCAPED_SLASHES);' "$download" "$signature" "$nightly")"

if [ "$dry_run" -eq 1 ]; then
	echo "DRY RUN: would POST to $api"
	# Redact signature mid-string for the log; keep length.
	php -r '$d=json_decode(stream_get_contents(STDIN), true); $s=$d["signature"]; $d["signature"]=substr($s,0,12)."…(".strlen($s)." chars)"; echo json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";' <<<"$body"
	exit 0
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
code="$(curl -sS -o "$tmp" -w '%{http_code}' -X POST "$api" \
	-H "Authorization: Token $APPSTORE_TOKEN" \
	-H 'Content-Type: application/json; charset=utf-8' \
	-d "$body")"

case "$code" in
	200|201)
		echo "App Store accepted the release (HTTP $code)."
		# Response may be empty or JSON; print a short preview if any.
		if [ -s "$tmp" ]; then
			head -c 400 "$tmp"; echo
		fi
		;;
	*)
		echo "App Store publish failed (HTTP $code):" >&2
		cat "$tmp" >&2 || true
		echo >&2
		exit 1
		;;
esac
