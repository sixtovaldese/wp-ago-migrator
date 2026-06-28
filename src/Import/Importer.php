<?php

namespace AgoLab\Migrator\Import;

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.EscapeOutput.ExceptionNotEscaped
// Reason: WordPress migrator needs streaming file IO + dynamic table names for SQL dump/load. WP_Filesystem cannot stream large files.
class Importer {

    private Files $files;
    private Database $db;

    public function __construct() {
        $this->files = new Files();
        $this->db    = new Database();
    }

    public function receive_chunk( string $job_id, int $chunk_index, string $chunk_data, int $total_chunks ): array {
        $tmp_dir = WP_CONTENT_DIR . '/ago-migrator-tmp';
        wp_mkdir_p( $tmp_dir );

        $part_file = $tmp_dir . '/' . $job_id . '.zip.part';

        // Decode and append
        $decoded = base64_decode( $chunk_data, true );
        if ( false === $decoded ) {
            throw new \RuntimeException( 'Invalid base64 data' );
        }

        file_put_contents( $part_file, $decoded, FILE_APPEND | LOCK_EX );

        // If last chunk, finalize
        if ( $chunk_index >= $total_chunks - 1 ) {
            $zip_file = $tmp_dir . '/' . $job_id . '.zip';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Migrator needs direct file IO for streaming and atomic ops.
            rename( $part_file, $zip_file );
        }

        return [
            'chunk'    => $chunk_index + 1,
            'total'    => $total_chunks,
            'received' => strlen( $decoded ),
        ];
    }

    public function start( string $job_id ): array {
        $zip_path = WP_CONTENT_DIR . '/ago-migrator-tmp/' . $job_id . '.zip';

        if ( ! file_exists( $zip_path ) ) {
            throw new \RuntimeException( 'ZIP file not found' );
        }

        $manifest = $this->files->read_manifest( $zip_path );
        if ( ! $manifest ) {
            throw new \RuntimeException( 'Invalid backup: manifest.json not found in ZIP' );
        }

        // Extract SQL to temp file for analysis
        $sql_file = WP_CONTENT_DIR . '/ago-migrator-tmp/' . $job_id . '.sql';
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

        set_transient( 'agomigrator_job_' . $job_id, $job, HOUR_IN_SECONDS );

        return [
            'manifest'    => $manifest,
            'total_steps' => count( $steps ),
        ];
    }

    public function step( string $job_id ): array {
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
                if ( ! empty( $result['errors'] ) ) {
                    $message .= ' (' . count( $result['errors'] ) . ' errors)';
                }
                break;

            case 'search_replace':
                $sr    = new SearchReplace( $job['manifest'] );
                $count = $sr->process_table( $step['table'] );
                $message = "Search-replace: {$step['table']} ($count replacements)";
                break;

            case 'cleanup':
                @wp_delete_file( $job['sql_file'] );
                @wp_delete_file( $job['zip_path'] );
                delete_transient( 'agomigrator_job_' . $job_id );
                $message = 'Import completed';
                break;
        }

        $job['current_step'] = $idx + 1;
        $done                = $job['current_step'] >= count( $job['steps'] );

        if ( ! $done ) {
            set_transient( 'agomigrator_job_' . $job_id, $job, HOUR_IN_SECONDS );
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
