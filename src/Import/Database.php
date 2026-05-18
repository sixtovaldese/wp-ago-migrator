<?php

namespace AgoLab\Migrator\Import;

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.EscapeOutput.ExceptionNotEscaped
// Reason: WordPress migrator needs streaming file IO + dynamic table names for SQL dump/load. WP_Filesystem cannot stream large files.
class Database {

    public function get_tables_from_sql( string $sql_file ): array {
        $tables = [];
        $handle = fopen( $sql_file, 'r' );
        if ( ! $handle ) {
            return $tables;
        }

        while ( ( $line = fgets( $handle ) ) !== false ) {
            if ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`/', $line, $m ) ) {
                $tables[] = $m[1];
            }
        }

        fclose( $handle );
        return $tables;
    }

    public function import_sql( string $sql_file ): array {
        global $wpdb;

        $handle = fopen( $sql_file, 'r' );
        if ( ! $handle ) {
            throw new \RuntimeException( "Cannot open $sql_file" );
        }

        $statement   = '';
        $executed    = 0;
        $errors      = [];

        while ( ( $line = fgets( $handle ) ) !== false ) {
            $trimmed = trim( $line );

            // Skip comments and empty lines
            if ( '' === $trimmed || str_starts_with( $trimmed, '--' ) ) {
                continue;
            }

            $statement .= $line;

            // Execute when we find a complete statement (ends with ;)
            if ( str_ends_with( $trimmed, ';' ) ) {
                $result = $wpdb->query( $statement );
                if ( false === $result ) {
                    $errors[] = $wpdb->last_error . ' | SQL: ' . substr( $statement, 0, 200 );
                }
                ++$executed;
                $statement = '';
            }
        }

        fclose( $handle );

        return [
            'executed' => $executed,
            'errors'   => $errors,
        ];
    }
}
