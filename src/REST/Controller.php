<?php

namespace AgoLab\Migrator\REST;

defined( 'ABSPATH' ) || exit;

use AgoLab\Migrator\Export\Exporter;
use AgoLab\Migrator\Import\Importer;
use AgoLab\Migrator\Storage;

class Controller {

    private const NS = 'agomigrator/v1';

    public function register_routes(): void {
        $job_arg = [
            'job_id' => [
                'required'          => true,
                'type'              => 'string',
                'validate_callback' => [ self::class, 'validate_job_id' ],
            ],
        ];

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
            'args'                => $job_arg,
        ] );

        register_rest_route( self::NS, '/export/download', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_download' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => $job_arg,
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
            'args'                => $job_arg,
        ] );

        register_rest_route( self::NS, '/import/step', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'import_step' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => $job_arg,
        ] );
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Job ids end up inside filesystem paths, so the route refuses anything
     * outside the allow list before a callback ever sees it.
     */
    public static function validate_job_id( mixed $value ): bool {
        return '' !== Storage::job_id( $value );
    }

    // ── Export ──────────────────────────────────────────

    public function export_start( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $exporter = new Exporter();
            return new \WP_REST_Response( $exporter->start(), 200 );
        } catch ( \Throwable $e ) {
            return self::failure( $e );
        }
    }

    public function export_step( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $exporter = new Exporter();
            return new \WP_REST_Response( $exporter->step( (string) $request['job_id'] ), 200 );
        } catch ( \Throwable $e ) {
            return self::failure( $e );
        }
    }

    public function export_download( \WP_REST_Request $request ) {
        $job_id = Storage::job_id( $request['job_id'] );
        if ( '' === $job_id ) {
            return new \WP_Error( 'agomigrator_bad_job', __( 'Invalid job id.', 'ago-migrator' ), [ 'status' => 400 ] );
        }

        $job = get_transient( 'agomigrator_job_' . $job_id );

        if ( ! $job || empty( $job['zip_path'] ) || ! file_exists( $job['zip_path'] ) ) {
            return new \WP_Error(
                'agomigrator_not_found',
                __( 'The backup is no longer available. Run the export again.', 'ago-migrator' ),
                [ 'status' => 404 ]
            );
        }

        $zip_path = $job['zip_path'];
        $filename = sanitize_file_name( basename( $zip_path ) );
        $size     = filesize( $zip_path );

        // Clean output buffers so nothing precedes the archive bytes.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        if ( false !== $size ) {
            header( 'Content-Length: ' . $size );
        }
        header( 'X-Content-Type-Options: nosniff' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the archive to the browser; reading it into memory would exhaust it on large sites.
        readfile( $zip_path );

        /*
         * The archive holds the whole database, so it leaves disk the moment it
         * has been handed over, together with the scheduled backstop deletion.
         */
        wp_clear_scheduled_hook( Storage::PURGE_HOOK, [ $job_id ] );
        Storage::purge_job( $job_id );

        exit;
    }

    // ── Import ─────────────────────────────────────────

    public function import_upload( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $params = $request->get_json_params();

            $job_id = Storage::job_id( $params['job_id'] ?? '' );
            if ( '' === $job_id ) {
                return new \WP_REST_Response( [ 'error' => __( 'Invalid job id.', 'ago-migrator' ) ], 400 );
            }

            $chunk = $params['chunk'] ?? '';
            if ( ! is_string( $chunk ) ) {
                return new \WP_REST_Response( [ 'error' => __( 'Invalid chunk.', 'ago-migrator' ) ], 400 );
            }

            $importer = new Importer();
            $result   = $importer->receive_chunk(
                $job_id,
                (int) ( $params['chunk_index'] ?? 0 ),
                $chunk,
                (int) ( $params['total_chunks'] ?? 1 )
            );

            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return self::failure( $e );
        }
    }

    public function import_start( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $importer = new Importer();
            return new \WP_REST_Response( $importer->start( (string) $request['job_id'] ), 200 );
        } catch ( \Throwable $e ) {
            return self::failure( $e );
        }
    }

    public function import_step( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $importer = new Importer();
            return new \WP_REST_Response( $importer->step( (string) $request['job_id'] ), 200 );
        } catch ( \Throwable $e ) {
            return self::failure( $e );
        }
    }

    /**
     * Turn a failure into a response.
     *
     * Only the plugin's own messages travel back to the browser. Anything else
     * could carry a filesystem path or a database detail, so it is replaced.
     */
    private static function failure( \Throwable $e ): \WP_REST_Response {
        $message = $e instanceof \RuntimeException
            ? $e->getMessage()
            : __( 'The operation could not be completed.', 'ago-migrator' );

        return new \WP_REST_Response( [ 'error' => $message ], 500 );
    }
}
