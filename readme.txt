=== Lucid Lab Media & Products Cleanup ===
Contributors: vanschaik12
Tags: media, cleanup, duplicate images, thumbnails, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 6.0.395
Works with Pro: 6.0.282
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe media cleanup, WebP conversion, usage reports and thumbnail regeneration. Pro adds advanced scanning and automation.

== Description ==

Lucid Lab Media & Products Cleanup finds unused, duplicate and missing media in your WordPress library and helps you clean it up safely. Nothing is deleted without your confirmation, and files go to a restorable quarantine first.

Free includes:
* Orphan Images scan, with shared-reference protection for featured images, galleries, categories and supported page-builder content.
* Byte-identical duplicate detection (SHA-256) and manual image comparison.
* Shared Images usage report and Missing Images report.
* Manual selection, restorable quarantine, and undo of earlier merges and renames (also after Pro is deactivated).
* Single-image renaming from the attached post title, image title or a cleaned-up slug, with collision handling and rename history.
* Thumbnail regeneration with progress, Stop, Resume and Cancel.
* Full-library WebP conversion and delivery, plus a ten-image WebP/AVIF comparison.
* Check live pages: opens every public page of the site and reports broken images, stylesheets, scripts, fonts, video, audio, documents and internal links, with the page each was found on.
* Saved scan statistics, CSV reports, and optional weekly or monthly email summaries.
* Database, uploads and full-site backups. Database backups are signed, and Restore only accepts dumps this plugin made on this site.

The separate Pro add-on (not included in this package) adds the Uploads Scanner, unused image-size cleanup, visually similar candidates, reference merging, Duplicate Products, bulk renaming, AVIF conversion, AI filename and alt-text suggestions, Run All, scheduled scans and WP-CLI, and checking of links on other sites.

The source code of this Free plugin is on GitHub: https://github.com/lucidlab-eu/mpc

= Safety =
* Quarantine can be undone as long as the files are in quarantine. Manual Purge and the Missing Images record purge are permanent; without a backup they cannot be undone.
* Free never purges automatically. The automatic 30-day quarantine cleanup needs Pro and is off until you turn it on in Options.
* Reference checks are the same in Free and Pro. Review the findings before you change media.
* The full-site backup contains WordPress core files, plugins, themes, uploads and a database dump. Other wp-content folders and custom files in the site root are not included. Restore a full-site backup with your hosting provider's tools or a WordPress recovery tool.

== Installation ==
1. Install and activate this Free core.
2. For premium features install and activate the separate Media & Products Cleanup Pro ZIP.
3. Both editions use the same settings, queues and recovery records. No data migration is required.
4. Deactivate Pro to return to Free. Do not delete quarantine folders manually.
5. Uninstall preserves recovery data and settings for reinstallation.

== Frequently Asked Questions ==
= Does Free stop working after a trial or image quota? =
No. Free has no trial period or image-count limit.
= Does Pro require an AI API key? =
Pro AI supports a configured hosted provider or local Ollama. Hosted providers require your own credentials and may charge for requests.
= Does licence expiry disable recovery? =
No. The Free plugin never contacts a licensing server and has no licence to expire. Only the separate Pro add-on checks its licence with Lucid Lab. Quarantine, restore and undo are part of Free and keep working without Pro.

== External services ==

AI suggestions connect only when an administrator configures a provider and requests an analysis or connection test. The selected image and any related title, filename or product text in that request, together with the configured API credential, are sent to that provider. OpenAI requests go to api.openai.com (terms: https://openai.com/policies/business-terms/ ; privacy: https://openai.com/policies/privacy-policy/). Anthropic requests go to api.anthropic.com (commercial terms: https://www.anthropic.com/legal/commercial-terms ; privacy: https://www.anthropic.com/legal/privacy). The local Ollama option sends the same analysis input to the administrator-configured local endpoint, normally 127.0.0.1:11434; it is not a hosted third-party service. AI is optional and is not contacted on activation.

Install count (off by default): only when an administrator opts in, with "Yes, count this site" in the notice shown on the plugin screen 7 days after installation or by ticking "Tell Lucid Lab that this site uses the plugin" under Options, the site sends a POST request to Lucid Lab at https://lucidlab.eu/wp-json/lucidlab/v1/free/ping with the site address (home URL), the plugin version, the plugin name and a random install ID. It is sent right after opting in, after each plugin update and once a week, and is used only to count active installs. No users, e-mail addresses, content, database data or settings are sent; the HTTP request necessarily includes the site's network address. Unticking the box stops it at once. A licensed Pro add-on does not turn it on: it is sent only after the opt-in. Lucid Lab privacy policy: https://lucidlab.eu/privacy/ .

Check live pages requests only this site's own pages and the addresses they load or link to; it sends nothing to Lucid Lab. With the Pro add-on and "Check other sites" ticked, it also sends HEAD or GET requests (no cookies, no content of this site) to the other sites the pages link to or load from, which receive the site's network address and user agent like any visitor request.

Core-file checking requests official checksums from api.wordpress.org only when an administrator starts that check. WordPress version and locale are sent; the HTTP request necessarily includes the site's network address. WordPress.org terms: https://wordpress.org/about/license/ ; privacy: https://wordpress.org/about/privacy/ .

== Source code ==

The JavaScript and CSS are shipped unminified and are the editable sources: assets/js/admin.js and assets/css/admin.css. No build step or bundler is needed. The paid Pro add-on has its own source and is not part of this package.

== Changelog ==

= 6.0.395 =
* Install count: 7 days after installation the plugin screen asks once whether this site may tell Lucid Lab that it uses the plugin, the same notice as in Database Cleaner. "Yes, count this site" turns it on, "No thanks" hides the notice. The setting stays under Options.
* Install count: nothing is sent unless an administrator opts in, also on sites with a licensed Pro add-on. The ping now goes to the shared Lucid Lab address https://lucidlab.eu/wp-json/lucidlab/v1/free/ping and also names the plugin. Uninstalling removes the install ID and turns the setting off.
* Header: a Documentation link next to the version number, and the page title now lines up with the icon.
* Getting started: the Free and Pro feature lists now match what each edition does.
* Get Pro buttons now link to the Pro product page (https://lucidlab.eu/product/media-products-cleanup-pro/).
* Finished jobs: the panel can be collapsed to the right with the arrow button next to its title, and expanded again. The choice is remembered per user.
* Finished Jobs panel remembers its collapsed state across pages, reloads, other open plugin tabs and Back/Forward.
* The main plugin page now uses the full screen width.
* The Activate Pro button (Pro installed but not activated) is slightly less tall.
= 6.0.394 =
* Pro pages: locked Pro tabs now show a clearer Pro card with a feature list and a Get Pro button. Pro tabs carry a Pro badge in the tab menu.

= 6.0.393 =
* Fixed a PHP deprecation notice on WordPress 6.8 and later (setted_transient is now set_transient).
* Import database backup: .sql.gz files were refused with "Import a .sql or .sql.gz database backup." They are accepted again; unsigned or changed dumps are still refused.
* Deactivating or deleting the plugin now removes its rewrite block from uploads/.htaccess (the file is deleted if nothing else is in it). Reactivating adds it back.

= 6.0.392 =
* AVIF copies are now sent as image/avif. On servers that do not know the .avif type, photo.jpg.avif was sent as image/jpeg. The rewrite block in uploads/.htaccess is updated in place on the next admin visit or daily cron; the rest of the file is left as it is.
* Background jobs: a WebP/AVIF conversion whose page was closed, whose cron event was lost or whose Run All is gone now recovers by itself instead of blocking other jobs and Run All. A conversion with no progress for 6 hours is marked failed and releases its slot.
* Quarantine: the tab now lists sets from every source (also Image Sizes, Shared Images and WordPress files), so it shows the same count as Diagnostics.
* Restore: the signature pre-check before the RESTORE prompt reuses an earlier full check of the same, unchanged file, and shows "Verifying…" while it runs. The restore itself always checks the file in full.

= 6.0.391 =
* Storage: all plugin data now lives in one folder, wp-content/uploads/lucidlab-mpc-cleanup/ (work files, quarantine, backups, previews and new WebP/AVIF copies). Existing folders are moved there once, automatically; if a move fails, the old folder keeps working and the move is retried.
* Security: database backups are written straight into gzip, so plain SQL never sits on disk. Older .sql backups are compressed, and the temporary copy used during a restore is deleted when the restore ends.
* WebP/AVIF copies made by earlier versions stay where they are and keep being served.

= 6.0.390 =
* Security: Import database backup now refuses SQL files that were not made by this plugin on this site, or that were changed afterwards. Stored dumps that fail the check are marked "Not signed by this site" and cannot be restored (Download and Delete still work). The check runs before the RESTORE prompt and again on the server before any SQL runs.

= 6.0.389 =
* Data safety: automatic purge of expired quarantine items is now off by default.
* Data safety: quarantined media records are deleted with wp_delete_attachment(), so WordPress and other plugins clean up as usual. Files in use stay in uploads.
* Security: database backups are signed with a key unique to this site, and Restore only accepts unchanged dumps made on this site. Older unsigned backups are refused (server administrators can set LUCIDLAB_MPC_ALLOW_UNSIGNED_DB_RESTORE in wp-config.php).
* New option: delete all plugin data on uninstall (off by default).
* Translations: messages are now complete, translatable sentences.

= 6.0.388 =
* Data safety: when several media records share one file on disk, quarantining or merging one record no longer moves the shared file, so the other records keep their image.
* Byte Identical Images: records that share a file are no longer counted as copies, and the space freed by a merge is now accurate.

= 6.0.387 =
* Check live pages is gentler on the host: at most 4 requests per second, a recognisable user agent, and an automatic pause and slower restart when the server answers 429 or 503.
* Admin pages send fewer background requests, and back off when the server asks them to slow down.

= Older versions =
Release notes for earlier versions are at https://lucidlab.eu/changelog/ .
