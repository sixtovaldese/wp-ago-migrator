<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
 * Job transients are short-lived, but a site uninstalling the plugin should not
 * be left with rows behind. The pattern is fixed, and prepare() keeps the query
 * parameterised anyway.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_agomigrator_job_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_agomigrator_job_' ) . '%'
    )
);

/*
 * Remove the working directories, which may still hold an undownloaded archive.
 * The first is where the plugin writes, inside the uploads directory. The
 * second is where versions up to 1.0.1 wrote, and is listed so uninstalling
 * after an upgrade does not leave a database dump behind.
 */
$agomigrator_uploads = wp_get_upload_dir();
$agomigrator_dirs    = [];

if ( empty( $agomigrator_uploads['error'] ) && ! empty( $agomigrator_uploads['basedir'] ) ) {
    $agomigrator_dirs[] = untrailingslashit( wp_normalize_path( $agomigrator_uploads['basedir'] ) ) . '/ago-migrator';
}

$agomigrator_dirs[] = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/ago-migrator-tmp';

foreach ( $agomigrator_dirs as $agomigrator_tmp_dir ) {
    if ( ! is_dir( $agomigrator_tmp_dir ) ) {
        continue;
    }

    $agomigrator_items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $agomigrator_tmp_dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $agomigrator_items as $agomigrator_item ) {
        if ( $agomigrator_item->isDir() ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall runs without WP_Filesystem credentials.
            rmdir( $agomigrator_item->getPathname() );
        } else {
            wp_delete_file( $agomigrator_item->getPathname() );
        }
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall runs without WP_Filesystem credentials.
    rmdir( $agomigrator_tmp_dir );
}
