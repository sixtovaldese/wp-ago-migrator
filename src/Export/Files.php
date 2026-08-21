<?php

namespace AgoLab\Migrator\Export;

use AgoLab\Migrator\Locations;

defined( 'ABSPATH' ) || exit;

/*
 * The file index is written and read as a stream, one line at a time, because a
 * site with a hundred thousand files must never have its whole file list in
 * memory at once. WP_Filesystem only reads and writes a file whole, so it
 * cannot express that, and only the sniffs about streaming are silenced here.
 * Every path written to the index comes from a directory walk of the install
 * itself, never from a request.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread

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

    /** A batch closes at this many files, or at this many bytes, whichever comes first. */
    public const BATCH_FILES = 500;
    public const BATCH_BYTES = 60 * MB_IN_BYTES;

    /**
     * Write the index of one content location: one line per file, size first.
     *
     * The index is built once, before anything is compressed, and every later
     * batch reads its slice from it. That is what keeps a batch's contents
     * fixed: walking the directory again on each request would shift the
     * offsets if a single file appeared or disappeared while the job runs, and
     * a file would be archived twice or skipped.
     *
     * @return int Number of files indexed.
     */
    public function write_index( string $name, string $index_path ): int {
        $base = Locations::path( $name );
        if ( '' === $base ) {
            return 0;
        }

        $handle = fopen( $index_path, 'wb' );
        if ( false === $handle ) {
            return 0;
        }

        $work_root = Locations::work_root();
        $count     = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $normalized = wp_normalize_path( (string) $file->getRealPath() );

            /*
             * The plugin's own working directory lives inside uploads, so it is
             * excluded by its absolute path. Matching its name against every
             * entry would also drop wp-content/plugins/ago-migrator, which
             * belongs in the backup.
             */
            if ( '' !== $work_root && str_starts_with( $normalized, $work_root . '/' ) ) {
                continue;
            }

            $relative = substr( $normalized, strlen( $base ) + 1 );

            if ( $this->is_excluded( $relative ) ) {
                continue;
            }

            fwrite( $handle, $file->getSize() . "	" . $relative . "
" );
            ++$count;
        }

        fclose( $handle );

        return $count;
    }

    /**
     * Split an index into batches, so the step plan is known before compressing.
     *
     * @return array<int, array{offset:int, count:int}>
     */
    public function plan_batches( string $index_path ): array {
        $handle = fopen( $index_path, 'rb' );
        if ( false === $handle ) {
            return [];
        }

        $batches = [];
        $offset  = 0;
        $files   = 0;
        $bytes   = 0;

        while ( false !== ( $line = fgets( $handle ) ) ) {
            $size   = (int) strtok( $line, "	" );
            $bytes += $size;
            ++$files;

            if ( $files >= self::BATCH_FILES || $bytes >= self::BATCH_BYTES ) {
                $batches[] = [ 'offset' => $offset, 'count' => $files ];
                $offset   += $files;
                $files     = 0;
                $bytes     = 0;
            }
        }

        if ( $files > 0 ) {
            $batches[] = [ 'offset' => $offset, 'count' => $files ];
        }

        fclose( $handle );

        return $batches;
    }

    /**
     * Add one batch of a content location to the archive.
     *
     * @return int Number of files added.
     */
    public function add_batch_to_zip( string $name, string $index_path, int $offset, int $limit, string $zip_path ): int {
        $base = Locations::path( $name );
        if ( '' === $base ) {
            return 0;
        }

        $handle = fopen( $index_path, 'rb' );
        if ( false === $handle ) {
            return 0;
        }

        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );

        $line_number = 0;
        $added       = 0;

        while ( false !== ( $line = fgets( $handle ) ) ) {
            if ( $line_number < $offset ) {
                ++$line_number;
                continue;
            }

            if ( $added >= $limit ) {
                break;
            }

            ++$line_number;

            $parts = explode( "	", rtrim( $line, "
" ), 2 );
            if ( 2 !== count( $parts ) ) {
                continue;
            }

            $relative = $parts[1];
            $absolute = $base . '/' . $relative;

            if ( ! is_file( $absolute ) ) {
                continue;
            }

            $zip->addFile( $absolute, 'wp-content/' . $name . '/' . $relative );
            ++$added;
        }

        $zip->close();
        fclose( $handle );

        return $added;
    }

    /** Whether a path inside a content location is one of the directories left out. */
    private function is_excluded( string $relative ): bool {
        $candidate = '/' . $relative;

        foreach ( self::SKIP_DIRS as $skip_dir ) {
            if ( str_contains( $candidate, '/' . $skip_dir . '/' ) || str_ends_with( $candidate, '/' . $skip_dir ) ) {
                return true;
            }
        }

        return false;
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
