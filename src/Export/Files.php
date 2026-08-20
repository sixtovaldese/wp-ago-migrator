<?php

namespace AgoLab\Migrator\Export;

use AgoLab\Migrator\Locations;

defined( 'ABSPATH' ) || exit;


class Files {

    private const SKIP_DIRS = [
        'ago-migrator-tmp',
        'cache',
        'upgrade',
        'updraft',
        'ai1wm-backups',
        'backups',
        'debug.log',
    ];

    /**
     * Add one content location to the archive.
     *
     * @param string $name     Location name, as used inside the archive.
     * @param string $zip_path Archive being written.
     */
    public function add_directory_to_zip( string $name, string $zip_path ): int {
        $base = Locations::path( $name );
        if ( '' === $base ) {
            return 0;
        }

        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );

        $count    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /*
         * The plugin's own working directory lives inside uploads, so it is
         * excluded by its absolute path. Matching its name against every entry
         * would also drop wp-content/plugins/ago-migrator, which belongs in the
         * backup.
         */
        $work_root = Locations::work_root();

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $real_path  = $file->getRealPath();
            $normalized = wp_normalize_path( (string) $real_path );

            if ( '' !== $work_root && str_starts_with( $normalized, $work_root . '/' ) ) {
                continue;
            }

            $relative   = 'wp-content/' . $name . '/' . substr( $normalized, strlen( $base ) + 1 );

            // Skip excluded directories
            $skip = false;
            foreach ( self::SKIP_DIRS as $skip_dir ) {
                if ( str_contains( $relative, '/' . $skip_dir . '/' ) || str_ends_with( $relative, '/' . $skip_dir ) ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            $zip->addFile( $real_path, $relative );
            ++$count;
        }

        $zip->close();
        return $count;
    }

    public function add_file_to_zip( string $local_path, string $zip_entry, string $zip_path ): void {
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFile( $local_path, $zip_entry );
        $zip->close();
    }

    public function add_string_to_zip( string $content, string $zip_entry, string $zip_path ): void {
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString( $zip_entry, $content );
        $zip->close();
    }

    /**
     * Names of the content locations present on this install.
     *
     * @return string[]
     */
    public function get_content_subdirs(): array {
        return array_keys( Locations::content() );
    }
}
