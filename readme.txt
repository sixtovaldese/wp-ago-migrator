=== aGo Migrator ===
Contributors: agolab
Donate link: https://paypal.me/sixtovaldes
Tags: migration, export, import, backup, transfer
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Simple WordPress migrator: export site as a single archive (DB + uploads), import on the destination.

== Description ==

aGo Migrator packages WordPress content (database tables + uploads + plugin and theme listings) into a single archive to move a site between environments. Lightweight alternative to enterprise migration plugins.

**Features**

* Export site to a single archive (database, uploads).
* Import archive on the destination site.
* Search-replace of URLs on import.
* No external services.
* English, Spanish (es_ES) and Brazilian Portuguese (pt_BR) bundled.

== Installation ==

1. Upload `ago-migrator` to `/wp-content/plugins/`.
2. Activate the plugin on both source and destination.
3. Source: **aGo Tools, Migrator**, click Export.
4. Destination: same screen, click Import and upload the archive.

== Frequently Asked Questions ==

= How large a site can it move? =

The export and import run in steps and the upload is chunked, so it works on shared hosting. Very large uploads directories are still limited by your host disk space and execution time.

= Does it overwrite the destination site? =

Yes. Importing replaces the destination database and files. Always import into a fresh or disposable site, never over production without a backup.

= Can I use WP-CLI? =

Yes. The plugin registers an `agomigrator` WP-CLI command for export and import.

== Screenshots ==

1. Export and import on a single screen: one-click full-site backup and drag-and-drop restore.
2. Step-by-step export progress with live table-by-table status.

== External services ==

This plugin does not connect to any external service. The archive is generated and read entirely on your own server. The donation links and the aGo Lab link in the admin page point to PayPal and ago.cl, opened only when the user clicks them.

== Privacy ==

The plugin processes your own site data locally to build and restore the archive. It sends no data to third parties. It stores short-lived job transients and a temporary working directory in wp-content; on uninstall, those transients and the temporary directory are removed.

== Changelog ==

= 1.0.0 =
* Initial release.
* One-click full-site export (database and uploads) to a single archive.
* Drag and drop import with manifest preview and confirmation.
* Serialization-safe search-replace of URLs on import.
* WP-CLI export and import commands.
* English, Spanish and Brazilian Portuguese included.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
