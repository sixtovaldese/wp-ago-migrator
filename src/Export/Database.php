<?php

namespace AgoLab\Migrator\Export;

defined( 'ABSPATH' ) || exit;

/*
 * Direct queries and streaming file writes are inherent to dumping a database:
 * the table list is dynamic, the output is appended to a file line by line, and
 * WP_Filesystem cannot stream. Only those sniffs are silenced here. Table names
 * are validated against the live schema before interpolation and every value is
 * escaped, so the security sniffs stay enabled.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
class Database {

    private ?array $tables = null;

    public function get_all_tables(): array {
        if ( null === $this->tables ) {
            global $wpdb;
            $this->tables = $wpdb->get_col( 'SHOW TABLES' );
        }
        return $this->tables;
    }

    /**
     * Only tables reported by the server are ever interpolated into a query.
     * Anything else is refused, so a tampered job payload cannot inject SQL.
     */
    private function assert_known_table( string $table ): void {
        if ( ! in_array( $table, $this->get_all_tables(), true ) ) {
            throw new \RuntimeException( 'Unknown table requested' );
        }
    }

    public function dump_table( string $table, string $sql_file ): void {
        global $wpdb;

        $this->assert_known_table( $table );
        $quoted = '`' . str_replace( '`', '``', $table ) . '`';

        $handle = fopen( $sql_file, 'a' );
        if ( ! $handle ) {
            throw new \RuntimeException( 'Cannot open the SQL dump for writing' );
        }

        fwrite( $handle, "\n-- Table: $table\n" );
        fwrite( $handle, "DROP TABLE IF EXISTS $quoted;\n" );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name validated by assert_known_table() against SHOW TABLES and backtick-quoted; SQL placeholders cannot describe identifiers.
        $create = $wpdb->get_row( "SHOW CREATE TABLE $quoted", ARRAY_N );
        if ( $create && isset( $create[1] ) ) {
            fwrite( $handle, $create[1] . ";\n\n" );
        }

        $batch_size = 500;
        $offset     = 0;

        while ( true ) {
            // Identifier validated against SHOW TABLES above; the limits are bound.
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM $quoted LIMIT %d OFFSET %d", $batch_size, $offset ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( empty( $rows ) ) {
                break;
            }

            $columns = array_keys( $rows[0] );
            $cols    = '`' . implode( '`, `', array_map( static fn( $c ) => str_replace( '`', '``', $c ), $columns ) ) . '`';

            $values_list = [];
            foreach ( $rows as $row ) {
                $vals = [];
                foreach ( $row as $value ) {
                    $vals[] = $this->escape_value( $value );
                }
                $values_list[] = '(' . implode( ', ', $vals ) . ')';
            }

            fwrite( $handle, "INSERT INTO $quoted ($cols) VALUES\n" );
            fwrite( $handle, implode( ",\n", $values_list ) . ";\n\n" );

            $offset += $batch_size;

            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        fclose( $handle );
    }

    public function write_header( string $sql_file ): void {
        $header  = "-- aGo Migrator Database Dump\n";
        $header .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        file_put_contents( $sql_file, $header );
    }

    public function write_footer( string $sql_file ): void {
        file_put_contents( $sql_file, "\nSET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND );
    }

    /**
     * Quote one value for the dump.
     *
     * esc_sql() is the documented public escaper. It is used instead of
     * $wpdb->prepare() because a dump escapes millions of values and prepare()
     * carries per-call formatting overhead that would dominate the export.
     */
    private function escape_value( mixed $value ): string {
        if ( null === $value ) {
            return 'NULL';
        }
        return "'" . esc_sql( (string) $value ) . "'";
    }
}
