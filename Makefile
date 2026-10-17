# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

.PHONY: appstore clean-appstore

# Build build/artifacts/maintenance_tracker.tar.gz (signed when APP_SIGNING_KEY is set).
appstore:
	bash scripts/build-appstore.sh

clean-appstore:
	rm -rf build/stage build/artifacts
