<?php
/**
 * Plugin Name: aGo Migrator
 * Plugin URI:  https://ago.cl/herramientas/
 * Description: Full-site backup & migration. One-click export, drag & drop import, serialization-safe search-replace.
 * Version:     1.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author:      aGo Lab
 * Author URI:  https://ago.cl/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ago-migrator
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AGOMIGRATOR_VERSION', '1.0.1' );
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

/*
 * Lifecycle hooks are registered at file scope on purpose. During activation
 * WordPress includes this file after plugins_loaded has already fired, so a
 * hook registered from inside that callback would never run.
 */
register_activation_hook( __FILE__, [ AgoLab\Migrator\Storage::class, 'dir' ] );
register_deactivation_hook( __FILE__, [ AgoLab\Migrator\Storage::class, 'purge_all' ] );

// Scheduled removal of a finished job, in case the archive is never downloaded.
add_action( AgoLab\Migrator\Storage::PURGE_HOOK, [ AgoLab\Migrator\Storage::class, 'purge_job' ] );

// WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'agomigrator', AgoLab\Migrator\CLI\Commands::class );
}
