<?php

namespace AgoLab\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the temporary working directory.
 *
 * Every archive this plugin builds contains a full database dump, so the
 * directory that holds it must never be reachable over HTTP and must not
 * survive longer than the job that created it. Three layers do that:
 *
 * 1. Server-level denial (.htaccess, web.config) written every time the
 *    directory is created, not only at activation.
 * 2. An unguessable path: each job lives in a subdirectory named after its
 *    random job id, which is the only layer nginx honours.
 * 3. Deletion after download, on a scheduled purge, and by sweeping expired
 *    jobs whenever a new one starts.
 */
class Storage {

    public const DIR_NAME   = 'ago-migrator-tmp';
    public const PURGE_HOOK = 'agomigrator_purge_job';
    public const TTL        = HOUR_IN_SECONDS;

    /** Root working directory, created and protected on demand. */
    public static function dir(): string {
        $dir = WP_CONTENT_DIR . '/' . self::DIR_NAME;

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        self::protect( $dir );

        return $dir;
    }

    /** Per-job subdirectory. The random job id is what keeps the URL unguessable. */
    public static function job_dir( string $job_id ): string {
        $dir = self::dir() . '/' . $job_id;

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        return $dir;
    }

    /**
     * Strict allow list for job ids.
     *
     * Job ids are concatenated into filesystem paths, so anything outside
     * this character set is rejected outright rather than sanitised.
     * Returns an empty string when the value is not usable.
     */
    public static function job_id( mixed $raw ): string {
        if ( ! is_string( $raw ) ) {
            return '';
        }

        return preg_match( '/^[A-Za-z0-9_]{1,64}$/', $raw ) ? $raw : '';
    }

    /** Delete one job directory and its transient. */
    public static function purge_job( string $job_id ): void {
        $job_id = self::job_id( $job_id );
        if ( '' === $job_id ) {
            return;
        }

        delete_transient( 'agomigrator_job_' . $job_id );

        $dir = WP_CONTENT_DIR . '/' . self::DIR_NAME . '/' . $job_id;
        if ( is_dir( $dir ) ) {
            self::delete_tree( $dir );
        }

        // Legacy layout: archives written straight into the root directory.
        $legacy = WP_CONTENT_DIR . '/' . self::DIR_NAME . '/' . $job_id . '.zip';
        if ( file_exists( $legacy ) ) {
            wp_delete_file( $legacy );
        }
    }

    /**
     * Remove anything older than the job lifetime.
     *
     * This is the backstop for the case where cron never runs and the user
     * never downloads: without it, a full database dump stays on disk.
     */
    public static function purge_expired(): void {
        $root = WP_CONTENT_DIR . '/' . self::DIR_NAME;
        if ( ! is_dir( $root ) ) {
            return;
        }

        $cutoff   = time() - self::TTL;
        $iterator = new \DirectoryIterator( $root );

        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) {
                continue;
            }

            $name = $item->getFilename();
            if ( in_array( $name, [ '.htaccess', 'index.php', 'web.config' ], true ) ) {
                continue;
            }

            if ( $item->getMTime() > $cutoff ) {
                continue;
            }

            if ( $item->isDir() ) {
                self::delete_tree( $item->getPathname() );
            } else {
                wp_delete_file( $item->getPathname() );
            }
        }
    }

    /** Remove the whole working directory. Used on deactivation and uninstall. */
    public static function purge_all(): void {
        $root = WP_CONTENT_DIR . '/' . self::DIR_NAME;
        if ( is_dir( $root ) ) {
            self::delete_tree( $root );
        }
    }

    /** Write the server-level denial files. Cheap enough to repeat. */
    private static function protect( string $dir ): void {
        $files = [
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
            'web.config' => "<configuration><system.webServer><authorization>"
                . "<deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ( $files as $name => $contents ) {
            $path = $dir . '/' . $name;
            if ( ! file_exists( $path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs before WP_Filesystem is available and must not prompt for credentials.
                file_put_contents( $path, $contents );
            }
        }
    }

    private static function delete_tree( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem offers no streaming-safe equivalent here.
                rmdir( $item->getPathname() );
            } else {
                wp_delete_file( $item->getPathname() );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem offers no streaming-safe equivalent here.
        rmdir( $dir );
    }
}
