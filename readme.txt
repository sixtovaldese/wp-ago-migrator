=== aGo Migrator ===
Contributors: agolab
Donate link: https://paypal.me/sixtovaldes
Tags: migration, export, import, backup, transfer
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.2
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
* Fully internationalized. Translations are contributed and delivered through translate.wordpress.org.

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

This plugin does not connect to any external service. Nothing is sent anywhere: the archive is generated and read entirely on your own server, and the plugin makes no outbound requests of its own.

The admin page does contain links that open in a new tab only when you click them. No script, style, font or image is ever loaded from any of these sites:

* ago.cl, plugin documentation and the aGo Lab site (https://ago.cl/).
* wordpress.org, the support forum for this plugin, the "Moving WordPress" guide and the "Changing the site URL" guide (https://wordpress.org/).
* PageSpeed Insights, offered as a way to compare your site before and after a move (https://pagespeed.web.dev/).
* PayPal, the voluntary donation links (https://paypal.me/).

== Privacy ==

The plugin processes your own site data locally to build and restore the archive. It sends no data to third parties. It stores short-lived job transients and a temporary working directory in wp-content; on uninstall, those transients and the temporary directory are removed.

== Changelog ==

= 1.0.2 =
* Every file the plugin writes now lives in a folder named after the plugin inside the uploads directory, resolved at runtime, instead of a folder in wp-content.
* Each location the plugin reads or restores (plugins, must-use plugins, themes, uploads, languages) is resolved through its own WordPress accessor, so installs that moved any of them outside wp-content are exported and restored correctly.
* On import, the archive's upload directory is rewritten to wherever uploads live on the destination, which fixes media paths when the two sites do not share the same layout.
* An export or a restore now requires the network capability on multisite, so a single site administrator can no longer act on the whole installation.
* Upgrading or uninstalling removes the working directory used by earlier versions, so no old database dump is left behind.
* Translations are no longer bundled: they come from translate.wordpress.org, where anyone can contribute them.

= 1.0.1 =
* Security: the working directory is created on demand and always carries its server-level denial files, instead of relying on activation having run.
* Security: each backup is written inside a folder whose name is not guessable, so an archive cannot be located by trying URLs.
* Security: archives are deleted after download, by a scheduled cleanup, and whenever a new job starts. A database dump is no longer left on disk when a download is abandoned.
* Security: job identifiers are validated against a strict allow list before they are used to build a filesystem path.
* Security: a restore only runs schema and data statements from an allow list, so a tampered archive cannot reach other database commands.
* Security: archive entries with traversal segments, absolute paths, drive letters or backslashes are refused, and every write is confirmed to land inside its destination directory.
* Security: serialized objects are left untouched during search-replace rather than being unserialized.
* Security: table names are verified against the live schema and all values are escaped through the WordPress database API.
* Fixed: the activation routine never ran, which left the working directory without its protection files.
* Fixed: an SQL value containing a semicolon and a line break no longer splits a statement in two during import.
* Fixed: error messages no longer include server paths or dump contents.
* Added: Quick links and Other aGo Lab plugins cards in the sidebar, both fully translated.

= 1.0.0 =
* Initial release.
* One-click full-site export (database and uploads) to a single archive.
* Drag and drop import with manifest preview and confirmation.
* Serialization-safe search-replace of URLs on import.
* WP-CLI export and import commands.
* Fully internationalized, with translations available through translate.wordpress.org.

== Upgrade Notice ==

= 1.0.2 =
Recommended: backups are now written inside the uploads directory, every content location is resolved through its own WordPress function, and on multisite only a network administrator can export or restore.

= 1.0.1 =
Recommended for every install: hardens where backups are stored, guarantees they are deleted, and restricts what a restore is allowed to execute.

= 1.0.0 =
Initial release.
