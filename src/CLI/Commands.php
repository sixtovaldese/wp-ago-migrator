<?php

namespace AgoLab\Migrator\CLI;

defined( 'ABSPATH' ) || exit;

use AgoLab\Migrator\Export\Exporter;
use AgoLab\Migrator\Import\Importer;

class Commands {

    /**
     * Export full site backup.
     *
     * ## OPTIONS
     *
     * [--output=<path>]
     * : Output path for the ZIP file.
     *
     * ## EXAMPLES
     *
     *     wp ago export
     *     wp ago export --output=/tmp/backup.zip
     *
     * @when after_wp_load
     */
    public function export( array $args, array $assoc_args ): void {
        $exporter = new Exporter();
        $info     = $exporter->start();
        $job_id   = $info['job_id'];
        $total    = $info['total_steps'];

        \WP_CLI::log( "Job: $job_id | Steps: $total" );
        $progress = \WP_CLI\Utils\make_progress_bar( 'Exporting', $total );

        $download_url = null;
        for ( $i = 0; $i < $total; $i++ ) {
            $result = $exporter->step( $job_id );
            $progress->tick();

            if ( ! empty( $result['error'] ) ) {
                \WP_CLI::error( $result['error'] );
            }

            if ( $result['done'] ) {
                $download_url = $result['download_url'] ?? null;
                break;
            }
        }
        $progress->finish();

        // Get zip path from job
        $job = get_transient( 'ago_migrator_job_' . $job_id );

        if ( $job && file_exists( $job['zip_path'] ) ) {
            $output = $assoc_args['output'] ?? $job['zip_path'];
            if ( $output !== $job['zip_path'] ) {
                copy( $job['zip_path'], $output );
                wp_delete_file( $job['zip_path'] );
            }
            $size = size_format( filesize( $output ) );
            \WP_CLI::success( "Backup saved: $output ($size)" );
        } else {
            \WP_CLI::error( 'Export failed: ZIP not found' );
        }

        delete_transient( 'ago_migrator_job_' . $job_id );
    }

    /**
     * Import site from backup ZIP.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the backup ZIP file.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp ago import backup.zip
     *     wp ago import backup.zip --yes
     *
     * @when after_wp_load
     */
    public function import( array $args, array $assoc_args ): void {
        $file = $args[0] ?? '';
        if ( ! file_exists( $file ) ) {
            \WP_CLI::error( "File not found: $file" );
        }

        // Copy to tmp dir
        $job_id   = 'imp_' . wp_generate_password( 12, false );
        $tmp_dir  = WP_CONTENT_DIR . '/ago-migrator-tmp';
        wp_mkdir_p( $tmp_dir );
        $zip_path = $tmp_dir . '/' . $job_id . '.zip';
        copy( $file, $zip_path );

        $importer = new Importer();
        $info     = $importer->start( $job_id );

        if ( ! empty( $info['manifest'] ) ) {
            $m = $info['manifest'];
            \WP_CLI::log( "Source: {$m['site_url']}" );
            \WP_CLI::log( "WP: {$m['wp_version']} | PHP: {$m['php_version']}" );
            \WP_CLI::log( "Tables: " . count( $m['tables'] ?? [] ) );
        }

        if ( empty( $assoc_args['yes'] ) ) {
            \WP_CLI::confirm( 'This will REPLACE the entire site. Continue?' );
        }

        $total    = $info['total_steps'];
        $progress = \WP_CLI\Utils\make_progress_bar( 'Importing', $total );

        for ( $i = 0; $i < $total; $i++ ) {
            $result = $importer->step( $job_id );
            $progress->tick();

            if ( ! empty( $result['error'] ) ) {
                \WP_CLI::error( $result['error'] );
            }

            if ( $result['done'] ) {
                break;
            }
        }
        $progress->finish();

        \WP_CLI::success( 'Import complete. Please re-login.' );
    }
}
