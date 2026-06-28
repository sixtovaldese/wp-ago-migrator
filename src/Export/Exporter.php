<?php

namespace AgoLab\Migrator\Export;

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.EscapeOutput.ExceptionNotEscaped
// Reason: WordPress migrator needs streaming file IO + dynamic table names for SQL dump/load. WP_Filesystem cannot stream large files.
class Exporter {

    private Database $db;
    private Files $files;

    public function __construct() {
        $this->db    = new Database();
        $this->files = new Files();
    }

    public function start(): array {
        $job_id  = 'exp_' . wp_generate_password( 12, false );
        $tmp_dir = WP_CONTENT_DIR . '/ago-migrator-tmp/' . $job_id;
        wp_mkdir_p( $tmp_dir );

        $site_name = sanitize_file_name( get_bloginfo( 'name' ) );
        $site_name = substr( preg_replace( '/[^a-z0-9]/i', '', $site_name ), 0, 10 );
        if ( empty( $site_name ) ) {
            $site_name = 'site';
        }
        $timestamp = gmdate( 'Ymd-His' );
        $zip_path  = WP_CONTENT_DIR . '/ago-migrator-tmp/' . strtolower( $site_name ) . '-' . $timestamp . '.zip';
        $sql_file  = $tmp_dir . '/database.sql';

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
            'tmp_dir'      => $tmp_dir,
            'zip_path'     => $zip_path,
            'sql_file'     => $sql_file,
            'current_step' => 0,
            'steps'        => $steps,
        ];

        set_transient( 'agomigrator_job_' . $job_id, $job, HOUR_IN_SECONDS );

        return [
            'job_id'      => $job_id,
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
                $message = 'SQL added to ZIP';
                break;

            case 'files':
                $count   = $this->files->add_directory_to_zip( $step['subdir'], $job['zip_path'] );
                $message = "wp-content/{$step['subdir']}/ ($count files)";
                break;

            case 'cleanup':
                $this->cleanup_tmp( $job['tmp_dir'] );
                $message = 'Cleanup completed';
                break;
        }

        $job['current_step'] = $idx + 1;
        $done                = $job['current_step'] >= count( $job['steps'] );

        set_transient( 'agomigrator_job_' . $job_id, $job, HOUR_IN_SECONDS );

        $result = [
            'step'     => $idx + 1,
            'total'    => count( $job['steps'] ),
            'progress' => round( ( $idx + 1 ) / count( $job['steps'] ) * 100, 1 ),
            'message'  => $message,
            'done'     => $done,
        ];

        if ( $done ) {
            $result['download_url'] = rest_url( 'ago-migrator/v1/export/download?job_id=' . $job_id );
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
            'wp_content_dir' => WP_CONTENT_DIR,
            'abspath'        => ABSPATH,
            'active_plugins' => get_option( 'active_plugins', [] ),
            'active_theme'   => get_stylesheet(),
            'multisite'      => is_multisite(),
        ];
    }

    private function cleanup_tmp( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
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
