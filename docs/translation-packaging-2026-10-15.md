# Translation packaging — 15 October 2026

Free 6.0.373 bundles only Spanish (`es_ES`) as `.po`, `.mo`, and `.l10n.php`.
The English source template (`.pot`) remains. The loader looks in
`wp-content/languages/plugins/` first for the current Free text domain and
falls back to the bundled Spanish file. Other Free languages can be supplied
as WordPress.org language packs after directory approval and translation.
Until such a pack exists, untranslated strings appear in English.

Pro 6.0.265 remains unchanged and bundles all 27 existing locale packs. Pro is
a separate commercial add-on, so it does not automatically receive
WordPress.org community packs under the Free plugin slug. Its current
`media-products-cleanup-pro` text domain and catalog filenames were preserved
to avoid losing working translations.

Verification: the loader priority test covers a WordPress.org Spanish pack,
German community pack, and bundled Spanish fallback. All PHP lint, JavaScript
syntax, and 13 standalone Free regressions pass. The release-content check
confirms exactly three Spanish locale files in the new Free ZIP, 81 locale files
in the unchanged Pro ZIP, and no tests or Markdown files in either package.

Release ZIPs:
- `D:\Development\Releases\lucid-lab-media-products-cleanup-6.0.373.zip`
- `D:\Development\Releases\lucid-lab-media-products-cleanup-pro-6.0.265.zip`

These ZIPs have not been installed on a production site. Free 6.0.372 remains
available as an earlier package; use 6.0.373 for the Spanish-only bundle.
