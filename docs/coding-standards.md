# Coding standards gate

`build-release.ps1` refuses to build a release when PHPCS reports any error or warning in the shipped PHP files of Free or Pro. It runs two checks per plugin:

1. `phpcs.xml.dist` in the plugin folder: WordPress (Core + Docs + Extra) and PHPCompatibilityWP, PHP 8.1+, WordPress 6.4+, with the plugin's own text domain.
2. The Plugin Check `plugin-review.xml` ruleset (the same PHPCS rules Plugin Check runs, including the `PluginCheck.*` sniffs).

## One-time setup on Windows

1. Install PHP 8.1+ (for example from windows.php.net) and add it to PATH.
2. Install Composer (getcomposer.org).
3. Install the standards globally:

   ```
   composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
   composer global require --dev wp-coding-standards/wpcs:^3 phpcompatibility/phpcompatibility-wp:*
   ```

4. Download the Plugin Check plugin (wordpress.org/plugins/plugin-check) and unzip it, for example to `D:\Development\_tools\plugin-check`.
5. Add the Plugin Check sniffs to PHPCS. Keep the paths that are already listed and add the sniffs folder:

   ```
   phpcs --config-show installed_paths
   phpcs --config-set installed_paths "<existing paths>,D:\Development\_tools\plugin-check\vendor\plugin-check\phpcs-sniffs"
   ```

6. Tell the build script where Plugin Check is:

   ```
   setx LUCIDLAB_PLUGIN_CHECK_DIR D:\Development\_tools\plugin-check
   ```

   If `phpcs` is not on PATH, also set `LUCIDLAB_PHPCS` to the full path of `phpcs.bat` (or pass `-Phpcs`).

## Running the check by hand

```
phpcs --standard=phpcs.xml.dist
```

Run it in the Free or Pro folder. Use `phpcbf --standard=phpcs.xml.dist` to fix alignment and spacing automatically.

## Ignoring a finding

Only ignore a finding when the code is correct and there is no WordPress API for it. Use a line-level annotation that names the sniff and gives the reason:

```php
$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason here.
```

Do not add file-wide `phpcs:disable` comments.

`-SkipCodingStandards` builds without the gate. Use it only in an emergency, and never for a build you publish.
