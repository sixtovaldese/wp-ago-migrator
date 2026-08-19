<?php

namespace AgoLab\Migrator\Import;

defined( 'ABSPATH' ) || exit;

use AgoLab\Migrator\Storage;

/*
 * Chunk uploads are appended to a single file as they arrive, which
 * WP_Filesystem cannot do without holding the whole archive in memory.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
class Importer {

    private Files $files;
    private Database $db;

    public function __construct() {
        $this->files = new Files();
        $this->db    = new Database();
    }

    public function receive_chunk( string $job_id, int $chunk_index, string $chunk_data, int $total_chunks ): array {
        $job_id = Storage::job_id( $job_id );
        if ( '' === $job_id ) {
            throw new \RuntimeException( 'Invalid job id' );
        }

        if ( 0 === $chunk_index ) {
            // A fresh upload starts clean, and stale archives go away with it.
            Storage::purge_expired();
            Storage::purge_job( $job_id );
        }

        $job_dir   = Storage::job_dir( $job_id );
        $part_file = $job_dir . '/upload.zip.part';

        $decoded = base64_decode( $chunk_data, true );
        if ( false === $decoded ) {
            throw new \RuntimeException( 'Invalid base64 data' );
        }

        file_put_contents( $part_file, $decoded, FILE_APPEND | LOCK_EX );

        if ( $chunk_index >= $total_chunks - 1 ) {
            $zip_file = $job_dir . '/upload.zip';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move of a local temporary file; WP_Filesystem has no atomic equivalent.
            rename( $part_file, $zip_file );
        }

        return [
            'chunk'    => $chunk_index + 1,
            'total'    => $total_chunks,
            'received' => strlen( $decoded ),
        ];
    }

    public function start( string $job_id ): array {
        $job_id = Storage::job_id( $job_id );
        if ( '' === $job_id ) {
            throw new \RuntimeException( 'Invalid job id' );
        }

        $job_dir  = Storage::job_dir( $job_id );
        $zip_path = $job_dir . '/upload.zip';

        if ( ! file_exists( $zip_path ) ) {
            throw new \RuntimeException( 'Backup archive not found' );
        }

        $manifest = $this->files->read_manifest( $zip_path );
        if ( ! $manifest || ( $manifest['generator'] ?? '' ) !== 'ago-migrator' ) {
            throw new \RuntimeException( 'Invalid backup: this archive was not produced by aGo Migrator' );
        }

        $sql_file = $job_dir . '/database.sql';
        $this->files->extract_sql( $zip_path, $sql_file );

        $tables = $this->db->get_tables_from_sql( $sql_file );

        // Build step plan
        $subdirs = [ 'plugins', 'themes', 'uploads', 'mu-plugins', 'languages' ];
        $steps   = [];

        foreach ( $subdirs as $subdir ) {
            $steps[] = [ 'type' => 'extract_files', 'subdir' => $subdir ];
        }

        $steps[] = [ 'type' => 'import_sql' ];

        // Search-replace as separate step
        $sr = new SearchReplace( $manifest );
        if ( $sr->needs_replace() ) {
            foreach ( $tables as $table ) {
                $steps[] = [ 'type' => 'search_replace', 'table' => $table ];
            }
        }

        $steps[] = [ 'type' => 'cleanup' ];

        $job = [
            'job_id'       => $job_id,
            'zip_path'     => $zip_path,
            'sql_file'     => $sql_file,
            'manifest'     => $manifest,
            'current_step' => 0,
            'steps'        => $steps,
        ];

        set_transient( 'agomigrator_job_' . $job_id, $job, Storage::TTL );
        wp_schedule_single_event( time() + Storage::TTL, Storage::PURGE_HOOK, [ $job_id ] );

        return [
            'manifest'    => $manifest,
            'total_steps' => count( $steps ),
        ];
    }

    public function step( string $job_id ): array {
        $job_id = Storage::job_id( $job_id );
        if ( '' === $job_id ) {
            return [ 'done' => true, 'error' => 'Invalid job id' ];
        }

        $job = get_transient( 'agomigrator_job_' . $job_id );
        if ( ! $job ) {
            return [ 'done' => true, 'error' => 'Job not found or expired' ];
        }

        $idx  = $job['current_step'];
        $step = $job['steps'][ $idx ] ?? null;

        if ( ! $step ) {
            return [ 'done' => true, 'error' => 'Invalid step' ];
        }

        $message = '';

        switch ( $step['type'] ) {
            case 'extract_files':
                $subdir     = $step['subdir'];
                $target_dir = WP_CONTENT_DIR . '/' . $subdir;
                $skip       = ( 'plugins' === $subdir ) ? [ 'ago-migrator' ] : [];

                // Clear existing content (except self)
                $this->files->clear_directory( $target_dir, $skip );

                // Extract from ZIP
                $count   = $this->files->extract_from_zip(
                    $job['zip_path'],
                    'wp-content/' . $subdir . '/',
                    $target_dir
                );
                $message = "Extracted wp-content/$subdir/ ($count files)";
                break;

            case 'import_sql':
                $result  = $this->db->import_sql( $job['sql_file'] );
                $message = "SQL imported: {$result['executed']} statements";
                if ( $result['skipped'] > 0 ) {
                    $message .= ", {$result['skipped']} not allowed and skipped";
                }
                if ( $result['failed'] > 0 ) {
                    $message .= ", {$result['failed']} failed";
                }
                break;

            case 'search_replace':
                $sr      = new SearchReplace( $job['manifest'] );
                $count   = $sr->process_table( $step['table'] );
                $message = "Search-replace: {$step['table']} ($count replacements)";
                break;

            case 'cleanup':
                Storage::purge_job( $job_id );
                $message = 'Import completed';
                break;
        }

        $job['current_step'] = $idx + 1;
        $done                = $job['current_step'] >= count( $job['steps'] );

        if ( ! $done ) {
            set_transient( 'agomigrator_job_' . $job_id, $job, Storage::TTL );
        }

        $result = [
            'step'     => $idx + 1,
            'total'    => count( $job['steps'] ),
            'progress' => round( ( $idx + 1 ) / count( $job['steps'] ) * 100, 1 ),
            'message'  => $message,
            'done'     => $done,
        ];

        if ( $done ) {
            $result['redirect_url'] = wp_login_url();
        }

        return $result;
    }
}
