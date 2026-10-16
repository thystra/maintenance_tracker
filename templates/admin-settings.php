<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array{outsideLinksEnabled: bool, action: string, requesttoken: string} $_ */
?>
<div id="maintenance-tracker-admin" class="section">
	<h2>Outside links</h2>
	<p class="settings-hint">
		Store and vendor links are https-only URLs attached to parts and vendors. They are never fetched by the server.
		When disabled, no new links are stored (including links carried by maintenance profiles or fitment packs) and existing links are hidden from every user. Stored links reappear if you enable them again.
	</p>
	<form method="post" action="<?php p($_['action']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<input type="hidden" name="enabled" value="0">
		<input type="checkbox" class="checkbox" id="maintenance-tracker-outside-links" name="enabled" value="1"<?php if ($_['outsideLinksEnabled']) { ?> checked<?php } ?>>
		<label for="maintenance-tracker-outside-links">Allow store and vendor links</label>
		<p>
			<button type="submit" class="button primary">Save</button>
		</p>
	</form>
</div>
