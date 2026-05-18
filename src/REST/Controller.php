<?php

namespace AgoLab\Migrator\REST;

defined( 'ABSPATH' ) || exit;

use AgoLab\Migrator\Export\Exporter;
use AgoLab\Migrator\Import\Importer;

class Controller {

    private const NS = 'ago-migrator/v1';

    public function register_routes(): void {
        // Export
        register_rest_route( self::NS, '/export/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'export_start' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NS, '/export/step', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'export_step' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'job_id' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::NS, '/export/download', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_download' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'job_id' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        // Import
        register_rest_route( self::NS, '/import/upload', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'import_upload' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NS, '/import/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'import_start' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'job_id' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::NS, '/import/step', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'import_step' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'job_id' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    // ── Export ──────────────────────────────────────────

    public function export_start( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $exporter = new Exporter();
            $result   = $exporter->start();
            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function export_step( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $exporter = new Exporter();
            $result   = $exporter->step( $request['job_id'] );
            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function export_download( \WP_REST_Request $request ): void {
        $job = get_transient( 'ago_migrator_job_' . $request['job_id'] );

        if ( ! $job || ! file_exists( $job['zip_path'] ) ) {
            status_header( 404 );
            echo 'File not found';
            exit;
        }

        $filename = basename( $job['zip_path'] );

        // Clean output buffers
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $job['zip_path'] ) );
        header( 'Cache-Control: no-cache, must-revalidate' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Migrator needs direct file IO for streaming and atomic ops.
        readfile( $job['zip_path'] );

        // Cleanup after download
        @wp_delete_file( $job['zip_path'] );
        delete_transient( 'ago_migrator_job_' . $request['job_id'] );

        exit;
    }

    // ── Import ─────────────────────────────────────────

    public function import_upload( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $params   = $request->get_json_params();
            $importer = new Importer();
            $result   = $importer->receive_chunk(
                sanitize_text_field( $params['job_id'] ?? '' ),
                (int) ( $params['chunk_index'] ?? 0 ),
                $params['chunk'] ?? '',
                (int) ( $params['total_chunks'] ?? 1 )
            );
            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function import_start( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $importer = new Importer();
            $result   = $importer->start( sanitize_text_field( $request['job_id'] ) );
            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function import_step( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $importer = new Importer();
            $result   = $importer->step( sanitize_text_field( $request['job_id'] ) );
            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }
}
