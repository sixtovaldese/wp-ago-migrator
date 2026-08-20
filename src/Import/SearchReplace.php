<?php

namespace AgoLab\Migrator\Import;

use AgoLab\Migrator\Locations;

defined( 'ABSPATH' ) || exit;

/*
 * Rewriting URLs across every table means reading and writing rows directly,
 * on tables whose names are only known at run time. Those sniffs are silenced.
 * Identifiers are checked against the live schema, values go through prepare()
 * and rows are written with $wpdb->update(), so nothing is interpolated raw.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

        /*
         * Filesystem paths. The archive carries the absolute paths of the site
         * it came from, and rows such as an attachment's _wp_attached_file or a
         * cached template path hold them verbatim, so each one is rewritten to
         * where that location lives on this install. The paths are read, never
         * written to, and each comes from its own accessor rather than being
         * assembled by hand.
         */
        $pairs = [
            [ $manifest['upload_basedir'] ?? '', Locations::uploads() ],
            [ $manifest['wp_content_dir'] ?? '', Locations::content_root() ],
            [ $manifest['abspath'] ?? '', Locations::install_root() ],
        ];

        foreach ( $pairs as list( $source, $target ) ) {
            $source = untrailingslashit( wp_normalize_path( (string) $source ) );

            if ( '' === $source || '' === $target || $source === $target ) {
                continue;
            }

            $this->search[]  = $source;
            $this->replace[] = $target;
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

        // The table list comes from the archive, so it is confirmed against
        // the live schema before its name reaches a query.
        if ( ! $this->table_exists( $table ) ) {
            return 0;
        }

        $quoted = '`' . str_replace( '`', '``', $table ) . '`';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier verified by table_exists() and backtick-quoted; SQL placeholders cannot describe identifiers.
        $columns = $wpdb->get_results( "DESCRIBE $quoted", ARRAY_A );
        if ( empty( $columns ) ) {
            return 0;
        }

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

        if ( ! $pk ) {
            return 0;
        }

        // Only fetch rows that actually contain one of the search strings.
        $like_parts = [];
        $like_args  = [];
        foreach ( $this->search as $needle ) {
            foreach ( $text_cols as $col ) {
                $like_parts[] = '`' . str_replace( '`', '``', $col ) . '` LIKE %s';
                $like_args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
            }
        }
        $where = implode( ' OR ', $like_parts );

        $replacements = 0;
        $batch_size   = 500;
        $offset       = 0;

        while ( true ) {
            /*
             * $quoted is the table name confirmed by table_exists() and $where is
             * built only from column names returned by DESCRIBE, each followed by
             * a %s placeholder. Every search value is bound through prepare().
             * SQL placeholders cannot stand in for identifiers, and the number of
             * placeholders is one per column-needle pair, so it is not knowable
             * statically. Those three sniffs are silenced for this query alone.
             */
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $quoted WHERE ($where) LIMIT %d OFFSET %d",
                    ...array_merge( $like_args, [ $batch_size, $offset ] )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

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

                if ( ! empty( $updates ) && isset( $row[ $pk ] ) ) {
                    // $wpdb->update() prepares both the values and the where clause.
                    $wpdb->update( $table, $updates, [ $pk => $row[ $pk ] ] );
                }
            }

            $offset += $batch_size;

            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        return $replacements;
    }

    private function table_exists( string $table ): bool {
        global $wpdb;

        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        );

        return is_string( $found ) && $found === $table;
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
                /*
                 * Serialized objects are left exactly as they are. Restoring them
                 * would run whatever __wakeup or __destruct the archive carries,
                 * and stripping the classes instead would re-serialize incomplete
                 * objects and corrupt the value. A missed replacement inside an
                 * object is recoverable; a mangled option is not.
                 */
                if ( self::contains_object( $data ) ) {
                    return $data;
                }

                $unserialized = unserialize( $data, [ 'allowed_classes' => false ] );
                if ( false !== $unserialized ) {
                    $unserialized = $this->recursive_unserialize_replace( $search, $replace, $unserialized );
                    return serialize( $unserialized );
                }
            }

            /*
             * Plain strings are safe to rewrite directly. Serialized payloads never
             * reach this line, so no length prefix can fall out of sync.
             */
            return str_replace( $search, $replace, $data );
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = $this->recursive_unserialize_replace( $search, $replace, $value );
            }
            return $data;
        }

        return $data;
    }

    /** Whether a serialized payload holds an object or enum at any depth. */
    private static function contains_object( string $data ): bool {
        return 1 === preg_match( '/(?:^|[;{])[OCE]:\d+:/', $data );
    }
}
