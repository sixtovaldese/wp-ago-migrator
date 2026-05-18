<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Delete job transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ago_migrator_job_%' OR option_name LIKE '_transient_timeout_ago_migrator_job_%'" );

// Remove temp directory
$tmp_dir = WP_CONTENT_DIR . '/ago-migrator-tmp';
if ( is_dir( $tmp_dir ) ) {
    $it    = new RecursiveDirectoryIterator( $tmp_dir, FilesystemIterator::SKIP_DOTS );
    $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Migrator needs direct file IO for streaming and atomic ops.
        $file->isDir() ? rmdir( $file->getPathname() ) : wp_delete_file( $file->getPathname() );
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Migrator needs direct file IO for streaming and atomic ops.
    rmdir( $tmp_dir );
}
