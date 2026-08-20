<?php

namespace AgoLab\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves every filesystem location the plugin touches.
 *
 * Each location is asked of WordPress through its own accessor instead of
 * being built by appending a name to the content directory. That is what makes
 * the plugin work on installs that moved uploads, themes or plugins somewhere
 * else, which is precisely the kind of install a migration tool has to handle.
 *
 * The archive keeps the familiar `wp-content/<name>/` layout for its entries.
 * Those are labels inside the ZIP, not paths on disk: on import each label is
 * resolved again through the accessor of the site being restored, so an archive
 * made on a default install restores correctly onto a site with a custom
 * uploads directory and the other way round.
 */
class Locations {

    /** Folder created inside the uploads directory to hold work in progress. */
    public const WORK_DIR = 'ago-migrator';

    /**
     * Content locations, keyed by the name used inside the archive.
     *
     * @return array<string, string> name => absolute path, only existing ones.
     */
    public static function content(): array {
        $paths = [
            'plugins'    => WP_PLUGIN_DIR,
            'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
            'themes'     => get_theme_root(),
            'uploads'    => self::uploads(),
            'languages'  => defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : '',
        ];

        $found = [];
        foreach ( $paths as $name => $path ) {
            if ( '' === $path ) {
                continue;
            }
            $path = untrailingslashit( wp_normalize_path( $path ) );
            if ( is_dir( $path ) ) {
                $found[ $name ] = $path;
            }
        }

        return $found;
    }

    /** Absolute path of one content location, or an empty string if unknown. */
    public static function path( string $name ): string {
        return self::content()[ $name ] ?? '';
    }

    /** The uploads base directory, which is where this plugin is allowed to write. */
    public static function uploads(): string {
        $uploads = wp_get_upload_dir();

        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            return '';
        }

        return untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
    }

    /** Working directory for jobs: a folder named after the plugin, inside uploads. */
    public static function work_root(): string {
        $uploads = self::uploads();

        return '' === $uploads ? '' : $uploads . '/' . self::WORK_DIR;
    }

    /**
     * Where WordPress is installed.
     *
     * Read, never written to. A migration tool has to know the path of the
     * install it is restoring onto, because the archive carries the absolute
     * paths of the site it came from and they have to be rewritten.
     */
    public static function install_root(): string {
        return untrailingslashit( wp_normalize_path( ABSPATH ) );
    }

    /** The content directory of this install. Read, never written to. */
    public static function content_root(): string {
        return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
    }
}
