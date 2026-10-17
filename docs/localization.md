# Localization

Maintenance Tracker uses Nextcloud's standard translation workflow: English
source strings in the code, extracted by Nextcloud's `translationtool` and
translated on Transifex, with translations committed under `l10n/` by the
Nextcloud translation sync.

## In code

- PHP: inject `OCP\IL10N` (or `IFactory::get('maintenance_tracker', $language)`
  for notifications and activities rendered for another user) and call
  `$l->t('Text with %s', [$value])` / `$l->n('%n item', '%n items', $count)`.
  Templates print with `p($l->t('...'))`.
- Vue/TypeScript: import `t`/`n` from `src/l10n.ts` and always call
  `t('maintenance_tracker', 'Literal text {name}', { name })` or
  `n('maintenance_tracker', '%n day', '%n days', count)`. The app id and the
  English text must be string literals so the extractor finds them. The wrappers
  disable HTML escaping because Vue interpolation already escapes output; never
  bind their result with `v-html`.
- Write whole sentences with placeholders instead of concatenating fragments.

Not translated by design: OCS API error messages (part of the English API
contract; the UI shows them as returned), `occ` command output, log messages,
audit detail keys, and stored data such as calendar event titles.

## Tooling

- `npm run l10n:check` (also in CI) fails on template text or human-looking
  attributes (`placeholder`, `aria-label`, `title`, `label`, `alt`) that are not
  translated, on user-facing-looking string literals in scripts that are not
  wrapped in `t()`, on `t()`/`n()` calls without literal arguments, and on raw
  text in PHP templates.
- `npm run l10n:extract` writes `translationfiles/templates/maintenance_tracker.pot`
  (git-ignored) to review what translators will see.
- `.l10nignore` keeps generated bundles and third-party code out of extraction.

## Enabling translations

Translations become available after the repository is connected to Nextcloud's
Transifex sync (or another workflow that writes `l10n/<lang>.js` and
`l10n/<lang>.json`). `Util::addScript` loads the app's translation bundle
automatically.
