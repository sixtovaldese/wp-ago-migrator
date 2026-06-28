<?php
/**
 * Plugin Name: aGo Migrator
 * Plugin URI:  https://ago.cl/herramientas/
 * Description: Full-site backup & migration. One-click export, drag & drop import, serialization-safe search-replace.
 * Version:     1.0.0
 * Requires PHP: 8.1
 * Author:      aGo Lab
 * Author URI:  https://ago.cl/
 * License:     GPL-2.0-or-later
 * Text Domain: ago-migrator
 */

defined( 'ABSPATH' ) || exit;

define( 'AGOMIGRATOR_VERSION', '1.0.0' );
define( 'AGOMIGRATOR_FILE', __FILE__ );
define( 'AGOMIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'AGOMIGRATOR_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloader
spl_autoload_register( function ( string $class ): void {
    $prefix = 'AgoLab\\Migrator\\';
    if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $file     = AGOMIGRATOR_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Boot
add_action( 'plugins_loaded', [ AgoLab\Migrator\Plugin::class, 'instance' ] );

// WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'agomigrator', AgoLab\Migrator\CLI\Commands::class );
}
