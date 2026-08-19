<?php

namespace AgoLab\Migrator;

defined( 'ABSPATH' ) || exit;

class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_admin_menu(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['agolab-tools'] ) ) {
            add_menu_page(
                __( 'aGo Tools', 'ago-migrator' ),
                __( 'aGo Tools', 'ago-migrator' ),
                'manage_options',
                'agolab-tools',
                '__return_null',
                'dashicons-hammer',
                81
            );
        }

        add_submenu_page(
            'agolab-tools',
            __( 'aGo Migrator', 'ago-migrator' ),
            __( 'Migrator', 'ago-migrator' ),
            'manage_options',
            'agomigrator',
            [ Admin\Page::class, 'render' ]
        );

        remove_submenu_page( 'agolab-tools', 'agolab-tools' );
    }

    public function register_rest_routes(): void {
        ( new REST\Controller() )->register_routes();
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_ends_with( $hook, '_page_agomigrator' ) ) {
            return;
        }

        wp_enqueue_style(
            'agomigrator-admin',
            AGOMIGRATOR_URL . 'assets/css/admin.css',
            [],
            AGOMIGRATOR_VERSION
        );

        wp_enqueue_script(
            'agomigrator-admin',
            AGOMIGRATOR_URL . 'assets/js/admin.js',
            [],
            AGOMIGRATOR_VERSION,
            true
        );

        wp_localize_script( 'agomigrator-admin', 'agomigratorMigrator', [
            'restUrl'        => rest_url( 'agomigrator/v1' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'maxUploadChunk' => 512 * 1024,
            'i18n'           => [
                'exporting'      => __( 'Exporting...', 'ago-migrator' ),
                'startingExport' => __( 'Starting export...', 'ago-migrator' ),
                'exportDone'     => __( 'Export complete. Downloading...', 'ago-migrator' ),
                'exportBtn'      => __( 'Export Backup', 'ago-migrator' ),
                'onlyZip'        => __( 'Only .zip files are accepted', 'ago-migrator' ),
                'uploading'      => __( 'Uploading...', 'ago-migrator' ),
                'uploadingFile'  => __( 'Uploading:', 'ago-migrator' ),
                'uploadComplete' => __( 'Upload complete. Reading manifest...', 'ago-migrator' ),
                'manifestRead'   => __( 'Manifest read. Waiting for confirmation...', 'ago-migrator' ),
                'importing'      => __( 'Importing...', 'ago-migrator' ),
                'importBtn'      => __( 'Confirm & Import', 'ago-migrator' ),
                'importDone'     => __( 'Import complete. Redirecting to login...', 'ago-migrator' ),
                'requestFailed'  => __( 'Request failed', 'ago-migrator' ),
                'errorLabel'     => __( 'Error', 'ago-migrator' ),
                'origin'         => __( 'Origin:', 'ago-migrator' ),
                'tables'         => __( 'Tables:', 'ago-migrator' ),
                'theme'          => __( 'Theme:', 'ago-migrator' ),
                'backupDate'     => __( 'Backup date:', 'ago-migrator' ),
            ],
        ] );
    }
}
