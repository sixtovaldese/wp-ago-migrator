<?php

namespace AgoLab\Migrator\Import;

defined( 'ABSPATH' ) || exit;

/*
 * Restoring files means writing them straight to disk as they come out of the
 * archive. WP_Filesystem would need credentials and cannot stream, so those
 * two sniffs are silenced and nothing else.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
class Files {

    public function extract_from_zip( string $zip_path, string $prefix, string $target_dir ): int {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            throw new \RuntimeException( 'Cannot open the backup archive' );
        }

        if ( ! is_dir( $target_dir ) ) {
            wp_mkdir_p( $target_dir );
        }

        $target_real = self::normalize( realpath( $target_dir ) );
        if ( '' === $target_real ) {
            $zip->close();
            throw new \RuntimeException( 'Destination directory is not writable' );
        }

        $count = 0;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry = $zip->getNameIndex( $i );
            if ( ! is_string( $entry ) || '' === $entry ) {
                continue;
            }

            if ( ! self::entry_is_safe( $entry ) ) {
                continue;
            }

            // Only extract entries matching prefix (e.g., "wp-content/plugins/")
            if ( ! str_starts_with( $entry, $prefix ) ) {
                continue;
            }

            // Skip ago-migrator itself during plugin extraction
            if ( str_starts_with( $entry, 'wp-content/plugins/ago-migrator/' ) ) {
                continue;
            }

            $relative = substr( $entry, strlen( $prefix ) );
            if ( '' === $relative ) {
                continue;
            }

            $dest = rtrim( $target_dir, '/' ) . '/' . $relative;

            // Directory entry
            if ( str_ends_with( $entry, '/' ) ) {
                if ( self::is_inside( $dest, $target_real ) ) {
                    wp_mkdir_p( $dest );
                }
                continue;
            }

            // Ensure parent directory exists
            $parent = dirname( $dest );
            if ( ! is_dir( $parent ) ) {
                wp_mkdir_p( $parent );
            }

            /*
             * Final containment check, made against the path the filesystem
             * actually resolved. This is what stops a symlink already present
             * in the destination from redirecting the write elsewhere.
             */
            if ( ! self::is_inside( $parent, $target_real ) ) {
                continue;
            }

            // Extract file
            $content = $zip->getFromIndex( $i );
            if ( false !== $content ) {
                file_put_contents( $dest, $content );
                ++$count;
            }
        }

        $zip->close();
        return $count;
    }

    public function extract_sql( string $zip_path, string $target_file ): bool {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            return false;
        }

        $content = $zip->getFromName( 'database.sql' );
        $zip->close();

        if ( false === $content ) {
            return false;
        }

        return (bool) file_put_contents( $target_file, $content );
    }

    public function read_manifest( string $zip_path ): ?array {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            return null;
        }

        $content = $zip->getFromName( 'manifest.json' );
        $zip->close();

        if ( false === $content ) {
            return null;
        }

        $manifest = json_decode( $content, true );

        return is_array( $manifest ) ? $manifest : null;
    }

    public function clear_directory( string $dir, array $skip = [] ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $iterator = new \DirectoryIterator( $dir );
        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) {
                continue;
            }

            $name = $item->getFilename();
            if ( in_array( $name, $skip, true ) ) {
                continue;
            }

            if ( $item->isDir() ) {
                $this->recursive_delete( $item->getPathname() );
            } else {
                wp_delete_file( $item->getPathname() );
            }
        }
    }

    /**
     * Reject archive entries that could escape the destination.
     *
     * Anything with a traversal segment, an absolute path, a Windows drive
     * letter, a backslash or a null byte never reaches the filesystem.
     */
    private static function entry_is_safe( string $entry ): bool {
        if ( str_contains( $entry, "\0" ) || str_contains( $entry, '\\' ) ) {
            return false;
        }

        if ( str_starts_with( $entry, '/' ) || preg_match( '#^[A-Za-z]:#', $entry ) ) {
            return false;
        }

        foreach ( explode( '/', $entry ) as $segment ) {
            if ( '..' === $segment ) {
                return false;
            }
        }

        return true;
    }

    /** Whether a path resolves inside the destination root. */
    private static function is_inside( string $path, string $target_real ): bool {
        $real = self::normalize( realpath( $path ) );
        if ( '' === $real ) {
            // Not created yet: fall back to the lexical form of the parent.
            $real = self::normalize( $path );
        }

        return $real === $target_real || str_starts_with( $real . '/', $target_real . '/' );
    }

    private static function normalize( string|false $path ): string {
        if ( ! is_string( $path ) || '' === $path ) {
            return '';
        }

        return rtrim( str_replace( '\\', '/', $path ), '/' );
    }

    private function recursive_delete( string $dir ): void {
        $it    = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
        $files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem has no non-interactive equivalent during an import.
                rmdir( $file->getPathname() );
            } else {
                wp_delete_file( $file->getPathname() );
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem has no non-interactive equivalent during an import.
        rmdir( $dir );
    }
}
