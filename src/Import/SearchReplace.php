<?php

namespace AgoLab\Migrator\Import;

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.EscapeOutput.ExceptionNotEscaped
// Reason: WordPress migrator needs streaming file IO + dynamic table names for SQL dump/load. WP_Filesystem cannot stream large files.
class SearchReplace {

    private array $search;
    private array $replace;

    public function __construct( array $manifest ) {
        $this->search  = [];
        $this->replace = [];

        $source_url = rtrim( $manifest['site_url'] ?? '', '/' );
        $target_url = rtrim( site_url(), '/' );

        $source_home = rtrim( $manifest['home_url'] ?? $source_url, '/' );
        $target_home = rtrim( home_url(), '/' );

        if ( $source_url && $source_url !== $target_url ) {
            // Full URLs
            $this->search[]  = $source_url;
            $this->replace[] = $target_url;

            // Home URL if different
            if ( $source_home !== $source_url ) {
                $this->search[]  = $source_home;
                $this->replace[] = $target_home;
            }

            // Without protocol
            $this->search[]  = preg_replace( '#^https?:#', '', $source_url );
            $this->replace[] = preg_replace( '#^https?:#', '', $target_url );

            // Escaped URLs (JSON)
            $this->search[]  = str_replace( '/', '\\/', $source_url );
            $this->replace[] = str_replace( '/', '\\/', $target_url );
        }

        // Filesystem paths
        $source_path = rtrim( $manifest['wp_content_dir'] ?? '', '/' );
        $target_path = rtrim( WP_CONTENT_DIR, '/' );

        if ( $source_path && $source_path !== $target_path ) {
            $this->search[]  = $source_path;
            $this->replace[] = $target_path;
        }

        $source_abspath = rtrim( $manifest['abspath'] ?? '', '/' );
        $target_abspath = rtrim( ABSPATH, '/' );

        if ( $source_abspath && $source_abspath !== $target_abspath ) {
            $this->search[]  = $source_abspath;
            $this->replace[] = $target_abspath;
        }
    }

    public function needs_replace(): bool {
        return ! empty( $this->search );
    }

    public function process_table( string $table ): int {
        global $wpdb;

        if ( ! $this->needs_replace() ) {
            return 0;
        }

        // Get text columns
        $columns = $wpdb->get_results( "DESCRIBE `$table`", ARRAY_A );
        $text_cols = [];
        foreach ( $columns as $col ) {
            if ( preg_match( '/char|text|varchar|longtext|mediumtext/i', $col['Type'] ) ) {
                $text_cols[] = $col['Field'];
            }
        }

        if ( empty( $text_cols ) ) {
            return 0;
        }

        // Find primary key
        $pk = null;
        foreach ( $columns as $col ) {
            if ( 'PRI' === $col['Key'] ) {
                $pk = $col['Field'];
                break;
            }
        }

        $replacements = 0;
        $batch_size   = 500;
        $offset       = 0;

        // Build WHERE clause to only fetch rows containing search strings
        $like_parts = [];
        foreach ( $this->search as $s ) {
            foreach ( $text_cols as $col ) {
                $like_parts[] = "`$col` LIKE '%" . $wpdb->_real_escape( $s ) . "%'";
            }
        }
        $where = implode( ' OR ', $like_parts );

        while ( true ) {
            $rows = $wpdb->get_results(
                "SELECT * FROM `$table` WHERE ($where) LIMIT $batch_size OFFSET $offset",
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $updates = [];
                foreach ( $text_cols as $col ) {
                    if ( empty( $row[ $col ] ) ) {
                        continue;
                    }
                    $new_value = $this->apply_replace( $row[ $col ] );
                    if ( $new_value !== $row[ $col ] ) {
                        $updates[ $col ] = $new_value;
                        ++$replacements;
                    }
                }

                if ( ! empty( $updates ) && $pk && isset( $row[ $pk ] ) ) {
                    $set_parts = [];
                    foreach ( $updates as $col => $val ) {
                        $set_parts[] = "`$col` = '" . $wpdb->_real_escape( $val ) . "'";
                    }
                    $wpdb->query(
                        "UPDATE `$table` SET " . implode( ', ', $set_parts ) .
                        " WHERE `$pk` = '" . $wpdb->_real_escape( $row[ $pk ] ) . "' LIMIT 1"
                    );
                }
            }

            $offset += $batch_size;

            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        return $replacements;
    }

    private function apply_replace( string $data ): string {
        foreach ( $this->search as $i => $search ) {
            $data = $this->recursive_unserialize_replace( $search, $this->replace[ $i ], $data );
        }
        return $data;
    }

    private function recursive_unserialize_replace( string $search, string $replace, mixed $data ): mixed {
        if ( is_string( $data ) ) {
            if ( is_serialized( $data ) ) {
                $unserialized = @unserialize( $data );
                if ( false !== $unserialized ) {
                    $unserialized = $this->recursive_unserialize_replace( $search, $replace, $unserialized );
                    return serialize( $unserialized );
                }
            }
            return str_replace( $search, $replace, $data );
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = $this->recursive_unserialize_replace( $search, $replace, $value );
            }
            return $data;
        }

        if ( is_object( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data->$key = $this->recursive_unserialize_replace( $search, $replace, $value );
            }
            return $data;
        }

        return $data;
    }
}
