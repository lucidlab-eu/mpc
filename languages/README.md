# Language packs

Admin UI strings follow the **signed-in user’s profile language** (`get_user_locale()`), not only the site language.

## Update on every release

1. Add or change gettext strings in PHP (`__()`, `_e()`, …) with domain `mpc-cleanup` (Free) or `mpc-cleanup-pro` (Pro).
2. Expose new JS labels through `wp_localize_script` → `MPC.i18n` and use `mpcT( 'key', 'English' )` in `assets/js/admin.js`.
3. Fill missing locale entries (optional when many new strings):

```bash
cd languages
node fill-translations.mjs
```

4. Rebuild catalogs:

```bash
php languages/build-i18n.php
```

`tools/build-zips.ps1` runs step 4 automatically before packaging.

## Sources (priority)

For each English source string and locale:

1. `translations.php` curated map  
2. `languages/cache/<locale>.json` (from `fill-translations.mjs`)  
3. `nl-extra.php` (Dutch overrides)  
4. English fallback (should be zero after a full fill + rebuild)

Free release ZIPs bundle only Spanish (`es_ES`); other Free languages come from WordPress.org language packs when available. Pro is a separate commercial add-on and continues to bundle all current translations. Each bundled locale uses `.po`, `.mo`, and `.l10n.php` files.
