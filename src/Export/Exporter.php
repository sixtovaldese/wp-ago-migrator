<?php

namespace AgoLab\Migrator\Export;

use AgoLab\Migrator\Locations;

defined( 'ABSPATH' ) || exit;

use AgoLab\Migrator\Storage;

class Exporter {

    private Database $db;
    private Files $files;

    public function __construct() {
        $this->db    = new Database();
        $this->files = new Files();
    }

    public function start(): array {
        // Never leave an earlier dump behind: it contains the whole database.
        Storage::purge_expired();

        $job_id  = 'exp_' . wp_generate_password( 12, false );
        $job_dir = Storage::job_dir( $job_id );

        $site_name = sanitize_file_name( get_bloginfo( 'name' ) );
        $site_name = substr( preg_replace( '/[^a-z0-9]/i', '', $site_name ), 0, 10 );
        if ( empty( $site_name ) ) {
            $site_name = 'site';
        }
        $timestamp = gmdate( 'Ymd-His' );

        /*
         * The archive lives inside the job directory, whose name carries 12
         * random characters. That is the layer which holds on servers that
         * ignore .htaccess, so the path must never become predictable.
         */
        $zip_path = $job_dir . '/' . strtolower( $site_name ) . '-' . $timestamp . '.zip';
        $sql_file = $job_dir . '/database.sql';

        $tables  = $this->db->get_all_tables();
        $subdirs = $this->files->get_content_subdirs();

        // Build step plan
        $steps   = [];
        $steps[] = [ 'type' => 'manifest' ];
        $steps[] = [ 'type' => 'db_header' ];

        foreach ( $tables as $table ) {
            $steps[] = [ 'type' => 'db_table', 'table' => $table ];
        }

        $steps[] = [ 'type' => 'db_footer' ];
        $steps[] = [ 'type' => 'db_to_zip' ];

        foreach ( $subdirs as $subdir ) {
            $steps[] = [ 'type' => 'files', 'subdir' => $subdir ];
        }

        $steps[] = [ 'type' => 'cleanup' ];

        $job = [
            'job_id'       => $job_id,
            'tmp_dir'      => $job_dir,
            'zip_path'     => $zip_path,
            'sql_file'     => $sql_file,
            'current_step' => 0,
            'steps'        => $steps,
        ];

        set_transient( 'agomigrator_job_' . $job_id, $job, Storage::TTL );

        // Backstop deletion for the case where the archive is never downloaded.
        wp_schedule_single_event( time() + Storage::TTL, Storage::PURGE_HOOK, [ $job_id ] );

        return [
            'job_id'      => $job_id,
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
            return [ 'done' => true, 'error' => 'Invalid step index' ];
        }

        $message = '';

        switch ( $step['type'] ) {
            case 'manifest':
                $manifest = $this->build_manifest( $job );
                $this->files->add_string_to_zip(
                    wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                    'manifest.json',
                    $job['zip_path']
                );
                $message = 'Manifest generated';
                break;

            case 'db_header':
                $this->db->write_header( $job['sql_file'] );
                $message = 'Starting SQL dump';
                break;

            case 'db_table':
                $this->db->dump_table( $step['table'], $job['sql_file'] );
                $message = 'Table: ' . $step['table'];
                break;

            case 'db_footer':
                $this->db->write_footer( $job['sql_file'] );
                $message = 'SQL dump completed';
                break;

            case 'db_to_zip':
                $this->files->add_file_to_zip( $job['sql_file'], 'database.sql', $job['zip_path'] );

                /*
                 * The loose dump goes as soon as it is inside the archive, so a
                 * plain .sql file with the whole database is on disk only for
                 * the length of the export and not until the job ends.
                 */
                if ( file_exists( $job['sql_file'] ) ) {
                    wp_delete_file( $job['sql_file'] );
                }

                $message = 'SQL added to ZIP';
                break;

            case 'files':
                $count   = $this->files->add_directory_to_zip( $step['subdir'], $job['zip_path'] );
                $message = "wp-content/{$step['subdir']}/ ($count files)";
                break;

            case 'cleanup':
                // Safety net: the dump is normally gone by now, removed as soon
                // as it entered the archive. The archive itself stays until it
                // is downloaded, then Storage::purge_job() takes the folder.
                if ( ! empty( $job['sql_file'] ) && file_exists( $job['sql_file'] ) ) {
                    wp_delete_file( $job['sql_file'] );
                }
                $message = 'Cleanup completed';
                break;
        }

        $job['current_step'] = $idx + 1;
        $done                = $job['current_step'] >= count( $job['steps'] );

        set_transient( 'agomigrator_job_' . $job_id, $job, Storage::TTL );

        $result = [
            'step'     => $idx + 1,
            'total'    => count( $job['steps'] ),
            'progress' => round( ( $idx + 1 ) / count( $job['steps'] ) * 100, 1 ),
            'message'  => $message,
            'done'     => $done,
        ];

        if ( $done ) {
            $result['download_url'] = rest_url( 'agomigrator/v1/export/download?job_id=' . $job_id );
        }

        return $result;
    }

    private function build_manifest( array $job ): array {
        global $wpdb;
        return [
            'generator'      => 'ago-migrator',
            'version'        => AGOMIGRATOR_VERSION,
            'timestamp'      => gmdate( 'c' ),
            'site_url'       => site_url(),
            'home_url'       => home_url(),
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => PHP_VERSION,
            'table_prefix'   => $wpdb->prefix,
            'db_charset'     => $wpdb->charset,
            'tables'         => $this->db->get_all_tables(),
            'wp_content_dir' => Locations::content_root(),
            'abspath'        => Locations::install_root(),
            'upload_basedir' => Locations::uploads(),
            'active_plugins' => get_option( 'active_plugins', [] ),
            'active_theme'   => get_stylesheet(),
            'multisite'      => is_multisite(),
        ];
    }
}
