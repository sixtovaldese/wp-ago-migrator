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
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        register_activation_hook( AGO_MIGRATOR_FILE, [ $this, 'activate' ] );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'ago-migrator', false, dirname( plugin_basename( AGO_MIGRATOR_FILE ) ) . '/languages' );
    }

    public function activate(): void {
        $tmp = WP_CONTENT_DIR . '/ago-migrator-tmp';
        if ( ! is_dir( $tmp ) ) {
            wp_mkdir_p( $tmp );
            file_put_contents( $tmp . '/.htaccess', "Deny from all\n" );
            file_put_contents( $tmp . '/index.php', "<?php\n// Silence is golden.\n" );
        }
    }

    public function register_admin_menu(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['ago-tools'] ) ) {
            add_menu_page(
                __( 'aGo Tools', 'ago-migrator' ),
                __( 'aGo Tools', 'ago-migrator' ),
                'manage_options',
                'ago-tools',
                '__return_null',
                'dashicons-hammer',
                81
            );
        }

        add_submenu_page(
            'ago-tools',
            __( 'aGo Migrator', 'ago-migrator' ),
            __( 'Migrator', 'ago-migrator' ),
            'manage_options',
            'ago-migrator',
            [ Admin\Page::class, 'render' ]
        );

        remove_submenu_page( 'ago-tools', 'ago-tools' );
    }

    public function register_rest_routes(): void {
        ( new REST\Controller() )->register_routes();
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_ends_with( $hook, '_page_ago-migrator' ) ) {
            return;
        }

        wp_enqueue_style(
            'ago-migrator-admin',
            AGO_MIGRATOR_URL . 'assets/css/admin.css',
            [],
            AGO_MIGRATOR_VERSION
        );

        wp_enqueue_script(
            'ago-migrator-admin',
            AGO_MIGRATOR_URL . 'assets/js/admin.js',
            [],
            AGO_MIGRATOR_VERSION,
            true
        );

        wp_localize_script( 'ago-migrator-admin', 'agoMigrator', [
            'restUrl'        => rest_url( 'ago-migrator/v1' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'maxUploadChunk' => 512 * 1024,
        ] );
    }
}
