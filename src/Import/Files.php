<?php

namespace AgoLab\Migrator\Import;

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.EscapeOutput.ExceptionNotEscaped
// Reason: WordPress migrator needs streaming file IO + dynamic table names for SQL dump/load. WP_Filesystem cannot stream large files.
class Files {

    public function extract_from_zip( string $zip_path, string $prefix, string $target_dir ): int {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            throw new \RuntimeException( "Cannot open ZIP: $zip_path" );
        }

        $count = 0;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry = $zip->getNameIndex( $i );

            // Security: prevent path traversal
            if ( str_contains( $entry, '..' ) ) {
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
                wp_mkdir_p( $dest );
                continue;
            }

            // Ensure parent directory exists
            $parent = dirname( $dest );
            if ( ! is_dir( $parent ) ) {
                wp_mkdir_p( $parent );
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

        return json_decode( $content, true );
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

    private function recursive_delete( string $dir ): void {
        $it    = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
        $files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Migrator needs direct file IO for streaming and atomic ops.
            $file->isDir() ? rmdir( $file->getPathname() ) : wp_delete_file( $file->getPathname() );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Migrator needs direct file IO for streaming and atomic ops.
        rmdir( $dir );
    }
}
