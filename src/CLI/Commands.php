<?php

namespace AgoLab\Migrator\CLI;

defined( 'ABSPATH' ) || exit;

use AgoLab\Migrator\Export\Exporter;
use AgoLab\Migrator\Import\Importer;
use AgoLab\Migrator\Storage;

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
     *     wp agomigrator export
     *     wp agomigrator export --output=/tmp/backup.zip
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

        for ( $i = 0; $i < $total; $i++ ) {
            $result = $exporter->step( $job_id );
            $progress->tick();

            if ( ! empty( $result['error'] ) ) {
                \WP_CLI::error( $result['error'] );
            }

            if ( $result['done'] ) {
                break;
            }
        }
        $progress->finish();

        $job = get_transient( 'agomigrator_job_' . $job_id );

        if ( ! $job || ! file_exists( $job['zip_path'] ) ) {
            Storage::purge_job( $job_id );
            \WP_CLI::error( 'Export failed: the archive was not created' );
        }

        /*
         * Without --output the archive lands in the directory the command was
         * run from, the way any other dump tool behaves. It is never left in
         * the plugin working directory, which gets purged.
         */
        $output = $assoc_args['output'] ?? rtrim( (string) getcwd(), '/\\' ) . '/' . basename( $job['zip_path'] );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Local move of a freshly written archive; WP_Filesystem would prompt for credentials in CLI.
        if ( ! copy( $job['zip_path'], $output ) ) {
            Storage::purge_job( $job_id );
            \WP_CLI::error( "Could not write the archive to: $output" );
        }

        $size = size_format( (int) filesize( $output ) );

        // A full database dump inside the document root is reachable over HTTP.
        $resolved = wp_normalize_path( (string) realpath( $output ) );
        $docroot  = \AgoLab\Migrator\Locations::install_root();
        if ( '' !== $docroot && str_starts_with( $resolved, $docroot . '/' ) ) {
            \WP_CLI::warning( 'The archive is inside the site document root and may be downloadable. Move it somewhere outside the web server.' );
        }

        wp_clear_scheduled_hook( Storage::PURGE_HOOK, [ $job_id ] );
        Storage::purge_job( $job_id );

        \WP_CLI::success( "Backup saved: $output ($size)" );
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
     *     wp agomigrator import backup.zip
     *     wp agomigrator import backup.zip --yes
     *
     * @when after_wp_load
     */
    public function import( array $args, array $assoc_args ): void {
        $file = $args[0] ?? '';
        if ( ! file_exists( $file ) ) {
            \WP_CLI::error( "File not found: $file" );
        }

        $job_id   = 'imp_' . wp_generate_password( 12, false );
        $job_dir  = Storage::job_dir( $job_id );
        $zip_path = $job_dir . '/upload.zip';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Local copy into the plugin working directory; WP_Filesystem would prompt for credentials in CLI.
        if ( ! copy( $file, $zip_path ) ) {
            Storage::purge_job( $job_id );
            \WP_CLI::error( 'Could not stage the archive for import' );
        }

        $importer = new Importer();
        $info     = $importer->start( $job_id );

        if ( ! empty( $info['manifest'] ) ) {
            $m = $info['manifest'];
            \WP_CLI::log( "Source: {$m['site_url']}" );
            \WP_CLI::log( "WP: {$m['wp_version']} | PHP: {$m['php_version']}" );
            \WP_CLI::log( 'Tables: ' . count( $m['tables'] ?? [] ) );
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
                Storage::purge_job( $job_id );
                \WP_CLI::error( $result['error'] );
            }

            if ( $result['done'] ) {
                break;
            }
        }
        $progress->finish();

        wp_clear_scheduled_hook( Storage::PURGE_HOOK, [ $job_id ] );
        Storage::purge_job( $job_id );

        \WP_CLI::success( 'Import complete. Please re-login.' );
    }
}
