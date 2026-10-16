# Accessibility

Maintenance Tracker aims at WCAG 2.2 AA inside the Nextcloud web UI. It uses
Nextcloud's theme variables for color, so contrast follows the user's
Nextcloud theme, including the high-contrast and dark themes.

## Automated checks

`npm run check:a11y` (also run in CI) parses every Vue single-file component
template and fails on:

| Rule | Requirement |
| --- | --- |
| `control-label` | Every `input`, `select` and `textarea` has a `<label>`, `for`/`id`, `aria-label` or `aria-labelledby`. A placeholder is not a label. |
| `button-name` | Buttons have text, `aria-label`, `aria-labelledby` or `title`. |
| `button-type` | Buttons declare `type="button"` or `type="submit"`. |
| `img-alt` | Images have `alt` (empty for decorative images). |
| `link-noopener` | `target="_blank"` links have `rel="noopener noreferrer"`. |
| `anchor-href` | Anchors have `href`; actions use buttons. |
| `tabindex` | No positive `tabindex`. |
| `click-keyboard` | `@click` on a non-interactive element also needs a role, `tabindex` and a keydown handler. Prefer a `<button>`. |
| `error-alert` | Conditionally shown error notices have a live role (`alert` or `status`). |
| `heading-content` | Headings are not empty. |

The check is static. It does not replace testing in a browser.

## Conventions

- Errors are shown in `role="alert"` notices. Confirmations use `role="status"`.
  The main view announces “Saved.” politely when a save finishes without an error.
- Toggles that show or hide a panel set `aria-expanded` and `aria-controls`.
- Keyboard focus has a visible outline (`:focus-visible`) using the theme's primary color.
- Long-running actions disable their button and set `aria-busy` (export, for example).
- Forms use native controls so keyboard and screen-reader behavior come from the browser.

## Manual check before a release

1. Do every workflow with the keyboard alone: Tab order, Enter/Space on buttons, and no keyboard traps.
2. Use a screen reader (Orca with Firefox, or NVDA, or VoiceOver) to check that control names, errors and save confirmations are announced.
3. Check the default, dark and high-contrast Nextcloud themes, and 200% zoom.
4. Optionally run axe DevTools on the app page. A scripted axe run against a live Nextcloud is not part of CI yet.
