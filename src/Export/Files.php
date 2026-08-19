<?php

namespace AgoLab\Migrator\Export;

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

    public function add_directory_to_zip( string $subdir, string $zip_path ): int {
        $base = WP_CONTENT_DIR . '/' . $subdir;
        if ( ! is_dir( $base ) ) {
            return 0;
        }

        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );

        $count    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $real_path = $file->getRealPath();
            $relative  = 'wp-content/' . $subdir . '/' . substr( str_replace( '\\', '/', $real_path ), strlen( str_replace( '\\', '/', $base ) ) + 1 );

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

    public function get_content_subdirs(): array {
        $dirs   = [ 'plugins', 'themes', 'uploads', 'mu-plugins', 'languages' ];
        $result = [];
        foreach ( $dirs as $dir ) {
            if ( is_dir( WP_CONTENT_DIR . '/' . $dir ) ) {
                $result[] = $dir;
            }
        }
        return $result;
    }
}
