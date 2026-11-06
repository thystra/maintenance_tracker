#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later
# shellcheck disable=SC2016 # PHP variables are intentionally single-quoted.
set -euo pipefail
case "${1:-}" in
 frontend)
  tools=(bash node npm python3 git tar gzip cut grep awk sed mktemp rm mkdir cp chmod cat dirname)
  ;;
 php)
  tools=(bash node cut php composer git tar gzip openssl curl sha256sum find touch chmod
   mktemp rm mkdir cp dirname basename wc cat tr sed awk grep cmp head ls)
  ;;
 *) echo 'Expected frontend or php toolchain.' >&2; exit 2 ;;
esac
for tool in "${tools[@]}"; do
 command -v "$tool" >/dev/null || { echo "Missing release tool: $tool" >&2; exit 1; }
done
test "$(node --version | cut -d. -f1)" = v24
case "$(tar --version)" in *'GNU tar'*) ;; *) echo 'GNU tar is required.' >&2; exit 1 ;; esac
if [ "$1" = frontend ]; then
 npm --version
 python3 --version
else
 php --version
 composer --version
 php -r 'foreach (["dom","libxml","mbstring","xml","xmlwriter","zip","SimpleXML","openssl"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: $ext\n"); exit(1); } }'
fi
