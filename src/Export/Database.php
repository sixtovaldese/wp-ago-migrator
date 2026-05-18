<?php

namespace AgoLab\Migrator\Export;

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.EscapeOutput.ExceptionNotEscaped
// Reason: WordPress migrator needs streaming file IO + dynamic table names for SQL dump/load. WP_Filesystem cannot stream large files.
class Database {

    public function get_all_tables(): array {
        global $wpdb;
        return $wpdb->get_col( 'SHOW TABLES' );
    }

    public function dump_table( string $table, string $sql_file ): void {
        global $wpdb;

        $handle = fopen( $sql_file, 'a' );
        if ( ! $handle ) {
            throw new \RuntimeException( "Cannot open $sql_file for writing" );
        }

        // DROP + CREATE
        fwrite( $handle, "\n-- Table: $table\n" );
        fwrite( $handle, "DROP TABLE IF EXISTS `$table`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
        if ( $create && isset( $create[1] ) ) {
            fwrite( $handle, $create[1] . ";\n\n" );
        }

        // INSERT in batches
        $batch_size = 500;
        $offset     = 0;

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `$table` LIMIT %d OFFSET %d", $batch_size, $offset ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            $columns = array_keys( $rows[0] );
            $cols    = '`' . implode( '`, `', $columns ) . '`';

            $values_list = [];
            foreach ( $rows as $row ) {
                $vals = [];
                foreach ( $row as $value ) {
                    $vals[] = $this->escape_value( $value );
                }
                $values_list[] = '(' . implode( ', ', $vals ) . ')';
            }

            fwrite( $handle, "INSERT INTO `$table` ($cols) VALUES\n" );
            fwrite( $handle, implode( ",\n", $values_list ) . ";\n\n" );

            $offset += $batch_size;

            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        fclose( $handle );
    }

    public function write_header( string $sql_file ): void {
        $header = "-- aGo Migrator Database Dump\n";
        $header .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        file_put_contents( $sql_file, $header );
    }

    public function write_footer( string $sql_file ): void {
        file_put_contents( $sql_file, "\nSET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND );
    }

    private function escape_value( mixed $value ): string {
        if ( null === $value ) {
            return 'NULL';
        }
        global $wpdb;
        return "'" . $wpdb->_real_escape( (string) $value ) . "'";
    }
}
