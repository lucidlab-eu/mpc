# Lucid Lab Media & Products Cleanup (Free): full changelog

Complete release history, moved out of readme.txt on 2026-10-19 so the wordpress.org readme stays short.
readme.txt keeps only the most recent versions. Each entry below is unchanged from the old readme.

## 6.0.393

* Works with Pro 6.0.281 (Pro unchanged; minimum Free stays 6.0.389).
* Deprecation: the result-summary listener was hooked on setted_transient, deprecated in WordPress 6.8, so every scan result logged "Hook setted_transient is deprecated" with WP_DEBUG on. It now uses set_transient on WordPress 6.8+ and keeps setted_transient on 6.4–6.7.
* Import database backup: the file name was checked after sanitize_file_name(), which turns name.sql.gz into name.sql_.gz (.sql is not an allowed upload type), so every .sql.gz import, including the plugin's own downloaded backups, was refused with "Import a .sql or .sql.gz database backup.". The .sql_.gz form is now recognised. Signature checks are unchanged: unsigned and changed dumps are still refused.
* uploads/.htaccess: the marker block was left behind after deactivation and after uninstall, even with "Delete all plugin data" on. Deactivation now removes the block (admin_init adds it back after reactivation), and uninstall.php removes it whether or not data is kept. Everything outside the block is kept byte for byte; a file that held only the block is deleted.
* Verified on a clean WordPress 7.1.3 / PHP 8.4 / MariaDB 11.8 install with WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY and SCRIPT_DEBUG on, with and without WooCommerce 11.2.1: no PHP notices, warnings or deprecations from the plugin.

## 6.0.392

* Works with Pro 6.0.281.
* AVIF MIME type: copies are named <original>.jpg.avif. Apache picks the type from the rightmost extension it knows, so on servers whose mime.types has no .avif entry, direct AVIF addresses (uploads/avif/… and uploads/lucidlab-mpc-cleanup/avif/…, as used in <picture> sources) were sent as image/jpeg. The plugin's marker block in uploads/.htaccess now adds `AddType image/avif .avif` and `AddType image/webp .webp` (mod_mime), types direct AVIF requests with `T=image/avif`, and keeps `Vary: Accept` on negotiated responses (for both the REDIRECT_ and the plain environment variable). Only the marker block is rewritten, in place; everything before and after it is kept byte for byte, and a duplicate block is dropped. The rules are synced on admin_init and now also on the daily cron. The plugin writes no nginx or IIS image rules; on nginx add `image/avif avif;` to the types block.
* Stale WebP/AVIF jobs: the job's "running" status was the only thing that held the job slot, with no check that a worker was still alive, so a conversion whose browser tab was closed (page-driven), whose cron event was lost after a PHP fatal error, or whose Run All had ended stayed pending forever and blocked Run All ("Another cleanup job is pending…"). Now a job with no saved progress for 15 minutes is recovered when the job list, the job-slot check or the daily cron looks at it: page-driven jobs continue in the background, lost cron events are rescheduled, and jobs owned by a Run All that is no longer running are released. A job without progress for 6 hours is marked failed (shown in Finished Jobs and the log) and releases its slot. Each step also marks itself in flight; after 3 steps in a row that ended without saving, the job is marked failed with a pointer to the PHP error log.
* Quarantine count: the Quarantine tab showed only sources with a fixed card, so sets from Image Sizes, Shared Images and WordPress-file repair were counted by Diagnostics but not listed. The tab now lists every source, and both places show the same summary ("N restorable sets (all sources) · includes N protected sets").
* Restore pre-check: a passed full signature check is remembered per file (full path, size, mtime, signing key and sha256 of the stored file). The pre-check before the RESTORE prompt hashes the stored .sql.gz (a fraction of a second for 40 MB) instead of decompressing and checking the whole dump when nothing changed. Backups made or imported here are remembered right away. A changed file, a failed check or a missing entry runs the full check. Starting the restore always runs the full server-side check. The admin shows "Verifying the backup signature…" while the pre-check runs.
* Tests: the WebP discovery test now uses Free's WebP class (it used Pro's unused copy). New tests cover stale-job recovery, the AVIF type rules, marker-block updates and the pre-check cache.

## 6.0.391

* Works with Pro 6.0.280.
* Storage (wordpress.org review: data written to disallowed locations): every file the plugin writes is now under wp-content/uploads/lucidlab-mpc-cleanup/ — work/ (scan files), quarantine/<random key>/, backups-<random key>/, format-preview/, webp/ and avif/. Private folders carry index.php, .htaccess and web.config deny files; the base and the public image folders carry index.php only. Nothing is written to the plugin folder, the wp-content root or the WordPress root. Paths come from wp_upload_dir(), so each multisite site uses its own uploads folder.
* Migration: on the first admin request after the update (or the daily cron) the old folders uploads/_cleanup-fsindex, _cleanup-quarantine, _cleanup-format-preview and lucidlab-mpc-backups-<key> are moved with one rename each, keeping their random names and manifests. Not while a backup or restore runs. If a move fails, the old folder stays in place and in use, and the move is retried later. Existing uploads/webp and uploads/avif copies are not moved because Media Library records and post content can point at them; they keep being served and found.
* Backups: database dumps are written straight into gzip (one gzip member per request, under a .sql.gz.part name that is never listed or served), signed while streaming and renamed to .sql.gz when finished. PHP zlib is now required for database backups. Full-site archives contain __lucidlab_mpc_database.sql.gz instead of a plain SQL file. Imported .sql files are checked and stored compressed. Stored .sql backups from older versions are compressed to .sql.gz (signature kept). Restore decompresses to a random .restore.tmp file in the protected backup folder and deletes it at the end or on failure; stale temporary files are swept.
* WebP/AVIF: new copies go to uploads/lucidlab-mpc-cleanup/webp|avif/<upload path>.<ext>.webp|avif; delivery, rewrite rules, copy search and statistics check the new folder first, then uploads/webp|avif and sidecars. With "replace originals" (Pro), the copy that becomes the Media Library file is moved next to its original first.
* Reset and uninstall remove the new folder as well as the old ones. The AI image resize temp file always uses the system temp folder.

## 6.0.390

* Works with Pro 6.0.279.
* Security: Import database backup now checks the signature and refuses SQL files that were not created by this plugin on this site, or that were changed afterwards. Stored dumps that fail the check are marked "Not signed by this site" and their Restore button is disabled (Download and Delete still work). Restore asks the server to check the file before it shows the RESTORE prompt, and the server checks again before it decompresses the file or runs any SQL. The emergency override LUCIDLAB_MPC_ALLOW_UNSIGNED_DB_RESTORE turns all of these checks off.

## 6.0.389

* Works with Pro 6.0.279.
* Data safety: Auto-purge of expired quarantine items is now off by default. Permanent deletion only runs after you turn it on under Options. Sites that never saved Options now have it off.
* Data safety: deleting quarantined media records now uses wp_delete_attachment(), so WordPress and other plugins clean up terms, meta and caches as usual. Files are never deleted this way; shared and kept files stay in uploads, and Quarantine and Restore work as before.
* Security: database backups are signed with a key that is unique to this site, and Restore only accepts dumps this plugin made on this site that were not changed afterwards. Other SQL files are refused with a message; restore them with your hosting provider's tools. Backups made before 6.0.389 are unsigned and are refused (emergency override for server administrators: LUCIDLAB_MPC_ALLOW_UNSIGNED_DB_RESTORE).
* New option: Delete all plugin data on uninstall (off by default). When on, uninstalling removes quarantine, backups, indexes, tables and settings.
* Translations: messages that were assembled from fragments are now complete sentences with placeholders, so they can be translated properly. Some changed messages show in English until the translations are updated.
* Code quality: file-wide coding-standard exclusions were replaced by line-level ones that name the rule and the reason. build-release.ps1 now refuses to build when PHPCS (WordPress Coding Standards and Plugin Check rules) reports any issue. New phpcs.xml.dist and docs/coding-standards.md.
* The running jobs data in the page is now window.lucidlabMpcInitialJobs.

## 6.0.388

* Works with Pro 6.0.278.
* Fixed, data safety: several media records can point at the same file on disk (for example after an import that created one record per product for the same photo). Moving such a record to Quarantine, also when merging Byte or Visually Identical images, moved the file itself, so the Keep image and every other record of that file showed a broken image until Undo. The file now stays in place while any other media record still uses it; only the extra record (and files only it uses) goes to Quarantine. Undo and Restore work as before, also when several records of one file are in the same set.
* Byte Identical Images: records that use the same file are no longer counted as copies of it. Each group shows "N media records using M file(s) on disk", records sharing a file are marked "Same file as #ID", and Reclaimable counts every file once, so merging such records shows 0 bytes freed. New counters: Extra files on disk and Records sharing one file. Groups are sorted by the space that merging really frees. Run the scan again to see the new numbers.
* Merge confirmation: the space freed is computed on the server per file, so records sharing the Keep image's file count as 0 bytes.
* Visually Identical (stored lookup for AI context): each image now points at its group once. Storing the whole group under every member made one option of about 23 MB for four groups of roughly 600 images. The previous format is still read.
* New regression test tests/shared-file-duplicates.php.

## 6.0.387

* Works with Pro 6.0.277.
* Check live pages, polite to the host: this site gets at most 4 requests per second (new filter lucidlab_mpc_crawler_rate, 0.2–50) and 4 at a time (pages and other addresses each; lucidlab_mpc_crawler_parallel_assets now defaults to the page value instead of twice it). Requests identify themselves as "Mozilla/5.0 (compatible; LucidLabLiveCheck/6.0.387; +https://lucidlab.eu/privacy/)" (lucidlab_mpc_crawler_user_agent filter), so hosts can recognise or allow them.
* Check live pages, 429 and 503 from this site: the check pauses for as long as Retry-After asks (up to 1 hour), otherwise 60 s doubling per pause up to 30 minutes. It then continues one request at a time at one request per second and speeds up by one step per 30 answers without 429/503, to at most three quarters of the speed that was refused. Addresses and pages that got 429/503 are not counted as checked and are asked again after the pause (up to 8 times; after that they count as inconclusive, never as broken). The job stays running, shows "Paused: the server asked us to slow down (HTTP 429). Resuming automatically at HH:MM …" and resumes by itself, in the browser and in the background (WP-Cron). Another site that answers 429 is still inconclusive and does not pause the check.
* Check live pages, less duplicate work: links to a listed page with only sorting, filter or paging arguments (for example WooCommerce ?orderby=, ?filter_color=, ?min_price=) need no request of their own. Addresses shared by many pages (header, footer, theme files) were already checked once per run.
* Check live pages: the progress bar is now pages checked / pages listed, the same numbers as the text. Before, it ran ahead of the pages (for example 30% at 805 of 4,419 pages).
* Admin pages, fewer requests: only one plugin tab drives running jobs; other tabs read the saved status every 15 seconds (every minute while hidden) and take over within seconds when the driving tab is hidden or closed. Hidden tabs poll Running Jobs every minute instead of every 30 seconds, and the live list of findings refreshes every 4 seconds only while the tab is visible.
* Admin pages, HTTP 429 and 503 from admin-ajax: the tab waits as long as Retry-After asks (otherwise 15 s doubling up to 5 minutes) before it sends anything again, and shows "The server asked us to slow down … Trying again at HH:MM". This no longer counts as a failed request; after other repeated errors the tab keeps reconnecting once a minute.
* Admin pages: the "running for" timers keep ticking from the saved start time in every tab, also while a tab is reconnecting. Before, they could stop in a second tab until it reloaded.
* New regression tests: tests/crawler-throttle.php and tests/admin-polling.php.

## 6.0.386

* Works with Pro 6.0.276.
* Check live pages, faster: pages and what they load are now requested in parallel even when WooCommerce (or another plugin) hooks into the WordPress HTTP API. Before, any such hook switched the check to one request at a time, so it was serial on every WooCommerce shop. Parallel requests now pass the same WordPress HTTP filters and actions as wp_remote_request() (http_request_args, pre_http_request, http_api_curl, https_ssl_verify, http_api_debug, http_response), so blocked hosts, proxies and custom cURL options still apply. Each round sends the next pages (GET) together with the not yet checked addresses of the pages already read (HEAD). Files of this site that exist on disk, links to pages that the check already lists, and addresses checked healthy in the last 5 minutes need no request. In a test with 184 pages and 300 ms per WordPress request it took 25 s instead of 78 s, while checking 97 instead of 31 kinds of addresses.
* Check live pages, more checks: besides images it now finds broken srcset sizes, CSS backgrounds (inline, style blocks, lazy-load data attributes and url() in the site's stylesheets), stylesheets and @import, scripts, fonts, video, audio and subtitles, documents and downloads (PDF, Office, ZIP …), icons and og:image, same-site iframes and links to other pages of this site (can be switched off). Problems reported: 4xx and 5xx answers, timeouts, redirects that end in an error (with the target), redirect loops and pages that cannot be read. 429 and 503 answers count as inconclusive, because they usually mean the server was busy. Admin, login, feed, REST, cart and nonce links are skipped (lucidlab_mpc_crawler_skip_url filter).
* Check live pages, report: one row per page and broken address with its type and problem (for example "404 Not Found after a redirect to …"), the stylesheet an address was found in, a filter by type and a summary per type. An address broken on many pages lists the first 25 pages and counts the rest.
* Check live pages: new filters lucidlab_mpc_crawler_parallel (pages at once, 1–8, default 4) and lucidlab_mpc_crawler_parallel_assets (other addresses at once, 1–16, default 8).
* Pro: checking addresses on other sites, and the CSV export of the findings.
* Privacy: the readme, the suggested privacy policy text and Options now link to the Lucid Lab privacy policy at https://lucidlab.eu/privacy/.
* New regression tests: tests/crawler-extraction.php; tests/crawler-redirects.php now covers redirects to a 404, loops, private targets, login redirects, HEAD fallback, timeouts and the verdicts.

## 6.0.385

* Works with Pro 6.0.275.
* Install count (optional, off by default): a new checkbox in Options, "Tell Lucid Lab that this site uses the plugin". Only after you tick it, the site sends its address (home URL), the plugin version and a random install ID to https://lucidlab.eu/wp-json/mpc/v1/free/ping: right away, after each plugin update and once a week (WP-Cron). Unticking it removes the weekly event at once; so do Reset plugin data, deactivation and uninstall. Options shows when it was last sent. The licensed Pro add-on sends the same ping without the checkbox. See External services.
* Media index: a Ready index is now compared with the number of images in the library after every plugin update and on each load of the plugin page (two COUNT queries), and rebuilt when they differ. Before, an index that a 6.0.383 build had finished with 5 of 26 images stayed at 5 after the update to 6.0.384: the table schema was already current and no build was running, so the 6.0.384 restart and the build-end check never ran, and a Ready index was trusted as is. When the same difference is still there after one rebuild, it is logged and kept, so the index does not rebuild on every page load.
* New regression tests: tests/site-ping-opt-in.php, and the "Ready with 5 rows, library 26" cases in tests/media-index-autobuild.php.

## 6.0.384

* Works with Pro 6.0.274.
* Orphan / Shared / Missing scans: a scan only uses a reference index when every stage (static, terms, posts, meta, settings) has finished. Builds now record each finished stage, and a build from an older format is started over instead of resumed. Before this, an older build left half-way could be finished and handed to the Orphan scan.
* Orphan scan: the Logs show what the result rests on: images checked, in use, orphans, the size of the reference index, and every setting that names 200 or more files ("A setting names many files"). One plugin setting that lists the whole library makes every image count as in use. The Logs now name it.
* Media index: a build that ran while the table schema was outdated (its writes failed, but its position moved on) now starts again from the first image after the schema update. Before this, it indexed only the newest images (5 of 26 on one site). A finished build is also checked against the number of images in the library, and restarts once when rows are missing.
* New regression tests: tests/reference-index-complete.php, tests/orphan-unreferenced.php, and more cases in tests/media-index-autobuild.php.

## 6.0.383

* Works with Pro 6.0.273.
* Media index: a table left by an older build (missing the "removed" column) is now detected and repaired. Before, writes failed silently and Diagnostics showed "Media index NOT BUILT" for good. Diagnostics shows "Building: X of Y images" while the build runs. Plugin AJAX requests also advance the build (about 2 seconds each).
* Orphan / Shared scans: the settings index now scans long values in linear time. One 7.5 MB setting with 8,000 URLs used to take over a minute and ended in "Failed to fetch". Settings over 1 MB are read in 1 MB slices. New regression test tests/reference-url-scan-linear.php.
* Logs: structured diagnostics for the media index build steps, the reference index stages (with slow-batch warnings) and schema errors.
* Jobs and AJAX: a PHP fatal error (message, file, line, peak memory) is saved in the job status and in the Logs instead of leaving the job stuck.

## 6.0.382

* Works with Pro 6.0.272.
* Media index: the automatic build after an update no longer depends on WP-Cron. Opening the plugin page builds it right away (about 5 seconds per page load), a lost background event is queued again on the next admin page, and the cron step and the page step never run at the same time.

## 6.0.381

* Works with Pro 6.0.272.
* Orphan, Shared and Missing scans: the shared reference index no longer starts over. A build is always resumed from its saved cursor (also after 15 minutes without progress), content edits made while it runs are patched in by the next scan instead of restarting it, and the cursor is saved after every batch. Progress only moves forward.
* Reference index speed: custom fields are read in one pass per range (values up to 64 KB come with the range read), values without a digit or dot are skipped in SQL, and ranges and settings batches are larger.
* Jobs: every job type counts against the job limit, including backups, regenerate, WebP, AI and rename, and jobs started by other admins. Start, resume and queue promotion decide one at a time, so two clicks can no longer both take the last slot. Resuming a paused job when all slots are busy queues it.
* Running timers use the job's saved start time, so they no longer restart after a reload or a poll.
* Finished Jobs: queued or cancelled jobs no longer show "undefined" before the date.
* Cancel and Pause are shown at once in Current Running Jobs, even while a batch is still finishing; a paused or queued job is cancelled immediately.
* Media index: built in the background after activation or an update when it is missing or empty; saved scan results are shared by all admins and dropped when their format changes.

## 6.0.380

* Works with Pro 6.0.271.
* Jobs: Pro runs at most 2 jobs at the same time (set LUCIDLAB_MPC_PRO_MAX_CONCURRENT to 1–3 in wp-config.php, or use the lucidlab_mpc_max_concurrent_jobs filter). A scan started while all slots are busy waits as "Queued" under Current Running Jobs and starts by itself in the background when a slot is free. Free still runs one job at a time and Run All still runs its stages one after another.
* Orphan, Shared and Missing scans: the reference index reads custom fields in meta_id ranges with a time budget and a saved cursor, skips shop fields that never hold images (price, stock, SKU …) in SQL, and fetches values in size-limited slices. Scans started together now share one index build instead of each reading every table, and Orphan Images shows a real percentage while indexing.
* Less load on admin-ajax: browser-driven batches pause briefly between requests, background and queued jobs are polled less often (and much less in a hidden tab), Current Running Jobs refreshes every 8 seconds instead of 5, and 502/503/504 errors back off exponentially up to 30 seconds.
* Database backup: tables with an integer primary key are dumped by key range in pages of 2,000 rows for about 8 seconds per request (no more slow LIMIT/OFFSET on large postmeta tables). The progress bar moves within a large table, and the "running for" timer no longer resets.
* Uploads backup no longer packs earlier backups into the new archive.
* Check live pages: images in the uploads folder are checked on disk instead of over HTTP, pages and remaining images are requested 4 at a time (lucidlab_mpc_crawler_parallel filter, 1–6), and duplicate page URLs are checked once. Its Completed/Cancelled line now sits under its own button; cancelling it no longer marks the Missing Images scan as cancelled.
* Finished Jobs shows "Completed on" whenever the tab has a saved summary (no more "Never run" next to visible results). Saved result lists are kept for 30 days instead of 1, so the Review Queue and the tabs agree.
* Ready Check: Media index only shows Ready when the index is current and actually holds rows.
* Backup texts now say consistently that backups are stored in a protected, randomly named folder under uploads.
* AI provider: the endpoint is normalized on save, on Test connection and before every request (a repeated segment such as /api/api/v1 becomes /api/v1; a bare …/v1 base gets the chat path). An invalid endpoint is not saved. Test connection names the URL it tested and fails when the answer is not a chat API response.
* WebP/AVIF conversion labels use thousands separators.
* Running job timers re-sync with the server instead of drifting.
* Check WP files: readme.html, wp-includes/version.php and other files that match the official checksum for either the site language or en_US are no longer flagged.


## 6.0.379

* Works with Pro 6.0.270 (signed and checksum-verified Pro updates).
* Options → AI provider: the "Remove the saved API key" row is laid out like the other fields: label first, then a normal-size checkbox with its explanation next to it. Checkboxes and radio buttons in the plugin screens are no longer stretched to the width of text fields.


## 6.0.378

Security
* Quarantine: sets now live in uploads/_cleanup-quarantine/<random 128-bit key>/ (key stored in the lucidlab_mpc_quarantine_dir_key option), and every new set name ends in a random 16-character suffix, so the folder cannot be guessed. Existing sets are moved into the key folder automatically on first use.
* Quarantine: scripts and server config files (.php, .phtml, .phar, .cgi, .htaccess, .user.ini …) are stored with a .quarantined suffix so they can never run from quarantine; restore removes the suffix. Database dumps (tables/, rows/) and every set folder get index.php, .htaccess and web.config deny files.
* New admin warning on Nginx (or when a probe file in quarantine can be downloaded over HTTP), with the Nginx deny rule to add for the quarantine and backup folders.
* WordPress file check: View/Compare only opens files listed in the latest check result, resolves the real path and requires it to stay inside the WordPress folder. wp-content (any case, also via ./ or a custom WP_CONTENT_DIR), dotfiles (.env, .git, .htaccess), wp-config* and secret/backup files (.sql, .bak, .log, .key, .zip …) are always refused.
* AI: cloud providers (OpenAI, Anthropic) need an https endpoint on a public host and are called with wp_safe_remote_post(), which refuses private and reserved addresses; redirects are not followed. The saved API key is never printed in the page: the field shows a masked placeholder, an empty field keeps the saved key, and a checkbox removes it. Testing with a changed endpoint never uses the saved key.
* CSV exports: cells that start with = + - @ tab or carriage return are prefixed with a single quote (spreadsheet formula injection).
* Stored data: serialized meta is decoded with unserialize( …, allowed_classes => false ) everywhere.
* Path check: the NUL-byte test in relative path sanitising now checks a real NUL byte.
Fixes
* Reset kept nothing from its keep-list (licence state, install id, language packs, …) because names were compared without the lucidlab_ prefix; it now keeps them, plus the backup and quarantine folder keys.
* Leftover pre-rename prefixes: the scan-state autoload fix now targets _transient_lucidlab_mpc_* (runs once more), and the orphan scanner skips the plugin's own _lucidlab_mpc meta.


## 6.0.377

* New plugin slug: lucidlab-mpc-cleanup. The plugin folder, main file (lucidlab-mpc-cleanup.php), text domain and language files now use it, and Free loads Pro translations from the lucidlab-mpc-cleanup-pro text domain. Release zips are named lucidlab-mpc-cleanup-<version>.zip. WordPress treats this as a new plugin: deactivate and delete the old lucid-lab-media-products-cleanup folder after installing.


## 6.0.376

* WordPress.org review fixes:
* Prefix: every class, function, constant, option, transient, meta key, hook, AJAX action, nonce, cron hook, table, CSS/JS handle and admin page now uses lucidlab_mpc_ / LucidLab_MPC_ / LUCIDLAB_MPC_ (lucidlab-mpc- for handles and slugs). The legacy mpc_ bridges were removed. The WP-CLI command is now "wp lucidlab-mpc".
* Requests: execute_task() no longer overwrites the $_POST superglobal. Parameters are passed explicitly, and every saved task re-checks manage_options plus a verified nonce (or cron/WP-CLI ownership) before it runs.
* Input: JSON request fields are decoded with a new helper and recursively sanitized against expected schemas. Whole-array wp_unslash($_POST) passing was removed.
* Paths: wp-admin and wp-includes are resolved through admin_url()/ABSPATH . WPINC, and plugin, theme and upload folders through WordPress APIs. Backups are now written only inside uploads, in a protected folder with a random name (uploads/lucidlab-mpc-backups-…).
* Time limits: set_time_limit() is only raised inside the long operation that needs it (backup compression and restore, AI requests), bounded to 600 seconds.
* Output buffering: every ob_start() is closed in the same function with try/finally. Report styles and scripts are enqueued; no inline style or script blocks remain.
* Database: queries use $wpdb->prepare() with %i/%d/%s placeholders; the remaining dynamic identifiers are documented.
* Code quality: all PHPCS WordPress-standard errors and warnings were cleared (no assignments in while conditions, no reserved-word parameter names, no unused parameters).


## 6.0.375

* Restore the Works with Pro readme field (6.0.266) so the Lucid Lab release manager pairs this Free release with the matching Pro release. No code changes.


## 6.0.374

* Code cleanup to the WordPress Coding Standards: zero PHPCS errors (WordPress standard), clean PHPCompatibilityWP for PHP 8.1+, docblocks on all functions, classes and properties, Yoda conditions and no short ternaries.
* Rename the Pro add-on slug references to lucid-lab-media-products-cleanup-pro and remove the previous-folder and old-text-domain fallbacks.
* Security hardening: database queries use $wpdb->prepare() with %i identifiers and placeholder lists, request input is unslashed and sanitized, error suppression (@) is replaced by explicit file checks, and every remaining phpcs:ignore carries a reason.
* Move the initial job data from an inline script tag to wp_add_inline_script().
* Rename PHP files to class-lucidlab-mpc-*.php and trait-lucidlab-mpc-*.php; split the task response class and the Pro stub traits into their own files.
* Backups now use pure PHP (gzip SQL dumps and ZIP archives). The mysqldump, mysql and 7-Zip command-line paths are removed; .7z backups can no longer be restored from the plugin.


## 6.0.373

* Bundle Spanish as the Free fallback translation and use WordPress.org language packs for other locales when available.
* Prefer updated WordPress.org translations over the bundled Spanish file.


## 6.0.372

* Prefix PHP declarations and new stored data with Lucid Lab identifiers. Existing settings, saved jobs, and index tables remain readable during the transition.
* Coordinate the matching Pro update and preserve scheduled work through compatibility listeners.


## 6.0.371

* Enqueue the printable report assets instead of embedding CSS and an inline click handler; use WPINC for core file checks.
* Fix the link after an AI filename rename so it opens Recent renames on the Rename Images tab.
* Product AI now focuses on featured-image title and description suggestions; AI media review supports caption and description with manual approval.
* Add confirmed Quarantine and Delete actions for freshly verified unexpected extra WordPress files; official core files remain view-only.
* Show top-level uploads-folder counts, including AVIF, in the Getting Started inventory; clarify Free WebP conversion and the ten-image AVIF comparison.
* Show the last WordPress core-file check time and fix the results renderer error; tighten Email report and Reset spacing.
* Show Free and Pro version numbers separately in the admin header without changing either plugin version.
* Show only configured report and automation frequencies in Diagnostics; omit the internal WebP worker event from its schedule list.
* Contain the Scan performance table in a horizontal scroll frame and keep WebP comparison previews within their table.
* Recognize the renamed Pro add-on folder and entry file while continuing to support the previous installation path.


## 6.0.370

* Update compatibility metadata for the Pro add-on that requires the new Lucid Lab Free slug.


## 6.0.369

* Rename bundled translation catalogs to match the Lucid Lab text domain, retaining compatibility with older Free-domain integrations.


## 6.0.368

* Point the plugin header to the Lucid Lab Pro product page.


## 6.0.367

* Keep the AVIF settings visible but disabled in Free, while WebP conversion remains available.


## 6.0.366

* Prepare a distinct Lucid Lab WordPress.org identity while retaining existing MPC data keys and hooks.
* Stop automatic language-pack writes and web-accessible emergency restore creation in this edition.
* Make the WordPress core check read-only; use WordPress Updates or hosting tools for repairs.
* Document optional external AI requests and the included editable asset sources.


## 6.0.363

* Normalize mixed line endings in 23 generated translation PHP files flagged by Plugin Check.
* Reject generated language files with CR or mixed line endings during release packaging.


## 6.0.362

* Keep a running backup's file list while refreshing stored backups; premature list cleanup caused empty uploads ZIPs and incomplete full-site archives.
* Add a regression check for active-list preservation and orphan-list cleanup.


## 6.0.361

* Include named WordPress folders even when a managed host symlinks them; reject full-site ZIPs that omit any listed file.
* Report uploads ZIP open codes and expected-versus-stored entry counts to identify live-host failures.
* Add a full-site archive test that verifies core, plugin, theme, uploads and SQL entries.


## 6.0.360

* Make uploads ZIP failures identify the file or batch involved, rather than reporting only a generic archive error.
* Detect unreadable full-site folders and identify any missing ZIP section; apply the same symlink rule during verification as during backup.


## 6.0.359

* Create uploads ZIP archives with the first file instead of closing an empty archive that PHP may remove.
* Verify completed full-site ZIP archives include the SQL dump and standard WordPress folders present on the site.


## 6.0.358

* Limit full-site archives to WordPress core, plugins, themes, uploads and SQL; emergency restore preserves excluded folders and custom root files.
* Verify uploads ZIP creation and contents before saying a backup is ready; fail visibly and remove partial archives when creation fails.
* Translate the updated backup scope descriptions in all 27 language packs. Works with Pro 6.0.259.


## 6.0.357

* Refresh stored backup lists when a job finishes and avoid stale file-stat results.
* Show each backup completion once; show successful deletion in a yellow notice instead of replaying an old completion.
* Verify backup deletion before reporting success. Works with Pro 6.0.259.


## 6.0.356

* Keep the Spanish Pro badge compact and use a consistent tone in the Spanish tagline.
* Update the release compatibility metadata to Pro 6.0.259.


## 6.0.355

* Load Pro language packs using the current text domain while retaining compatibility with the legacy domain.
* Add two recent interface strings to all 27 Free translation packs and rebuild their compiled files.


## 6.0.354

* Align the Performance auto-purge checkbox, update WebP / AVIF navigation, and style the standalone emergency restore page.
* Clear Generate copies busy state after cancellation and honor a pending cancellation while a conversion worker holds its lock.
* Works with Pro 6.0.259.


## 6.0.353

* Sanitize the Free WebP conversion settings before decoding the request payload.
* Shorten the WordPress.org readme summary to fit the 150-character limit.
* Works with Pro 6.0.258.


## 6.0.352

* Keep the Free WebP intro focused on WebP and place the Pro AVIF gate in its own block below the WebP controls.
* Hide the library AVIF quality card in Free, while Compare 10 still makes temporary AVIF previews at default quality.
* Works with Pro 6.0.258.


## 6.0.351

* Label the navigation and job sidebar WebP in both editions; keep AVIF controls and explanations in the page.
* Put the Free AVIF Pro notice below the WebP controls and simplify the Free page subtitle.
* Show comparison samples processed separately from successful conversions and reload the saved final table before completion.
* Explain why WebP library statistics may differ from uploads-folder file totals.
* Works with Pro 6.0.258.


## 6.0.350

* Explain logging reset and visual fingerprint rebuilding in the confirmation dialog.
* Keep Analyze Sizes and Other Sizes on Disk completion times separate during Run All, including the live sidebar.
* Show a measured visual-stage progress bar and a final Run All summary.
* Clarify that the uploads inventory counts excluded directory instances, and label the Free sidebar tool WebP.
* Works with Pro 6.0.257.


## 6.0.349

* Allow WebP library conversion and delivery in Free while retaining the ten-image WebP/AVIF comparison.
* Keep AVIF conversion, original-image quarantine and Pro scanner actions behind an active Pro license.
* Show the standard Pro gate for Other Sizes on Disk and Pro quarantine sources; keep restoration available after Pro ends.
* Pause saved Pro jobs and automatic purge when Pro becomes inactive. Existing generated files and quarantine sets are preserved.
* Works with Pro 6.0.256.


## 6.0.348

* Log completed Run All stages and the overall job when logging is enabled.
* Keep scanner completion times separate from Run All quarantine actions.
* Record completed uploads-folder inventories in Job history and the log.
* Explain that reset disables logging and requires visual fingerprints to be rebuilt.
* Refresh the media-index Ready check when Run All finishes rebuilding it.
* Works with Pro 6.0.255.


## 6.0.347

* Identify the retained scan completion as the previous result during a new scan.
* Animate the active progress track while measured progress remains unchanged; percentages still reflect recorded work.
* Works with Pro 6.0.254.


## 6.0.346

* Show completed stages still retained in older Run All jobs as read-only Job history rows.
* Label the prior scan result immediately when a new scan starts; remove redundant completion text.
* Show the current and total Job history pages.
* Works with Pro 6.0.254.


## 6.0.345

* Clarify that automatic purge is paused while Pro is inactive.
* Label the previous completion time during an active rescan.
* Let Logs page through the 50 retained Job history entries.
* Hide the Pro-only Other Sizes completion notice in Free.
* Works with Pro 6.0.254.


## 6.0.344

* Redirect the inactive Pro license URL before WordPress rejects its removed menu page.
* Recover Finished Jobs dates from saved scan results and keep nearby job timestamps aligned with scan notices.
* Keep the previous Completed on notice visible during a new scan; distinguish the Free WebP comparison from Pro conversion controls.
* Show the automatic purge as paused in Diagnostics while Pro is inactive.
* Works with Pro 6.0.254.


## 6.0.343

* Redirect the Pro license URL to Getting Started when the Pro add-on is deactivated, instead of leaving a WordPress 403 page.
* Works with Pro 6.0.254.


## 6.0.342

* Record completed Run All stages separately in Job history, alongside the overall Run All entry.
* Keep quarantine completion from replacing a scanner's Completed on notice.
* Works with Pro 6.0.253.


## 6.0.341

* Cleared the Plugin Check database warnings. Scans, backups and uninstall still use $wpdb for batches that have no core API, and those results are not cached across requests.
* Optional OpenAI and Anthropic calls stay behind the key saved in Options. WordPress 6.4 has no built-in AI client.
* Works with Pro 6.0.253.


## 6.0.340

* Package the standalone emergency restore script as a template. Backup still copies the same PHP source to the WordPress root as mpc-restore.php, while direct requests to plugin files cannot execute it.
* Works with Pro 6.0.241.


## 6.0.339

* Cleared the remaining Plugin Check warnings. Nonce checks stay in the page POST handler and the AJAX handler. Dynamic IN-list queries stay prepared. Long backups may still raise the time limit.
* Works with Pro 6.0.241.


## 6.0.338

* Cleared the remaining Plugin Check errors: numbered translation placeholders, LIKE wildcards passed as values, and a direct-access guard on the emergency restore script that still lets mpc-restore.php run from the site root.
* Streamed backup and media moves stay on the local filesystem, with that exception recorded for the check.
* Works with Pro 6.0.241.


## 6.0.337

* Simplified WooCommerce usage preview markup; two output-escaping warnings are resolved without changing the displayed results.


## 6.0.336

* Documented the exception-message escaping boundary and suppressed its construction-site false positive without disabling output checks.


## 6.0.335

* Use standalone-safe HTML and attribute escaping in the emergency restore page.


## 6.0.334

* Added contextual translator notes to all reported formatted interface strings without changing their translations.


## 6.0.333

* Prepared additional quarantine ID-list reads and media-index summary/search reads.


## 6.0.332

* Prepared database table and column identifiers and ID lists in table quarantine, optimize and row collection.


## 6.0.331

* Prepared paged Shared Images queries, the product-copy scan's post-type filter, and the rename-undo table identifier without changing job behavior.


## 6.0.330

* Parameterized Orphan scanner ID-list reads for posts, metadata and terms without changing scan batches or results.


## 6.0.329

* Escaped admin card attributes and dynamic labels at output while preserving the existing markup.


## 6.0.328

* Prepared backup table identifiers with WordPress SQL placeholders.
* Removed an unnecessary AI query option; default query behavior is unchanged.
* Kept release notes in this readme while preserving the existing plugin behavior.


## 6.0.327

* Use the plugin slug as the Free translation domain while retaining compatibility with the existing language packs and older integrations.
* Corrected CSV export permission/nonce handling and a PHP compatibility warning; normalized emergency restore line endings.


## 6.0.326

* WebP conversion now shows a pending Start state immediately and avoids briefly repainting the previous cancelled run.
* Automatically refreshes WebP/AVIF file counts after a run changes copies.
* Works with Pro 6.0.241.


## 6.0.325

* Updated translation templates and all 27 locale packs with newly identified interface strings and previously blank translations.
* Preserved format placeholders and HTML tags when compiling the updated language files.
* Works with Pro 6.0.240.


## 6.0.324

* Enforced the recent database-backup requirement in the table-quarantine engine itself, including calls outside the preview and AJAX handlers.
* Added direct-gate and resumable WebP-discovery regression checks.
* Works with Pro 6.0.239.


## 6.0.323

* The recent database-backup safety check uses file metadata instead of inspecting every backup archive.
* Added a standalone regression test and source-check runner for PHP-equipped development environments.
* Works with Pro 6.0.238.


## 6.0.322

* Database-table quarantine preview checks for an existing database or full-site backup from the last 24 hours.
* Works with Pro 6.0.235.


## 6.0.321

* Advanced file actions use the same on-page confirmation as the other cleanup controls. Permanent deletion requires typing CONFIRM.
* Works with Pro 6.0.234.


## 6.0.320

* Logs maintenance actions use separate nonce-protected forms. Disabling logging records the change before logging turns off.
* Email report settings show the next scheduled run and the last scheduled successful send; send attempts are tracked without changing existing cron hooks.
* Email report settings include a cached-totals preview. Mobile Performance fields and muted text contrast are improved.
* Release ZIPs are built from an allowlist with checks for development-only files.
* Works with Pro 6.0.234.


## 6.0.319

* Saving Options no longer turns logging off. Logging stays on the Logs tab, and Options saved is written to the log.
* Advanced Database Cleaner select-all uses the same on-page confirm as quarantine. The emergency restore key sits in its own block under the backup actions.
* The release zip no longer includes the tests folder.
* Works with Pro 6.0.233.


## 6.0.318

* Completed times within a minute of the saved job stay on the job clock, so the tab, sidebar and log show the same minute.
* The missing-results notice stays hidden on a fresh scan page. Image Sizes keeps Analyze sizes above Other Sizes on Disk. Duplicate Products keeps Completed on above the result cards.
* Database Cleaner record jobs are titled as records. Quarantine purge controls are buttons. Backup, quarantine, options and logging writes are added to the log.
* Works with Pro 6.0.232.


## 6.0.317

* Generated WebP / AVIF folder copies with an original still on disk are excluded from Orphan Images findings.
* WebP stats completion and conversion messages have separate status elements; scan performance shows readable scanner names.
* Works with Pro 6.0.231.
