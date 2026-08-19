<?php

namespace AgoLab\Migrator\Import;

defined( 'ABSPATH' ) || exit;

/*
 * Restoring a dump means issuing schema and data statements straight to the
 * server, so direct queries are unavoidable. Only those sniffs are silenced.
 * Every statement is matched against an allow list before it runs.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
class Database {

    /**
     * Statements a restore is allowed to execute.
     *
     * An archive is user-supplied input. Without this list a crafted dump
     * could reach for anything the database user can do, including writing
     * files on the database host through SELECT ... INTO OUTFILE, creating
     * accounts, or installing triggers that survive the import.
     */
    private const ALLOWED = [
        '/^SET\s+(NAMES|FOREIGN_KEY_CHECKS|SQL_MODE|UNIQUE_CHECKS|AUTOCOMMIT|TIME_ZONE|CHARACTER_SET_CLIENT|CHARACTER_SET_RESULTS|CHARACTER_SET_CONNECTION|COLLATION_CONNECTION)\b/i',
        '/^DROP\s+TABLE\b/i',
        '/^CREATE\s+TABLE\b/i',
        '/^INSERT\s+INTO\b/i',
        '/^REPLACE\s+INTO\b/i',
        '/^ALTER\s+TABLE\b/i',
        '/^TRUNCATE\s+(TABLE\s+)?/i',
        '/^LOCK\s+TABLES\b/i',
        '/^UNLOCK\s+TABLES\b/i',
        '/^START\s+TRANSACTION\b/i',
        '/^COMMIT\b/i',
    ];

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
        $handle = fopen( $sql_file, 'r' );
        if ( ! $handle ) {
            throw new \RuntimeException( 'Cannot read the database dump' );
        }

        $statement = '';
        $in_string = false;
        $executed  = 0;
        $skipped   = 0;
        $failed    = 0;

        while ( ( $line = fgets( $handle ) ) !== false ) {
            // Comment and blank lines only count as such outside a quoted value.
            if ( ! $in_string ) {
                $trimmed = trim( $line );
                if ( '' === $trimmed || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '/*' ) ) {
                    continue;
                }
            }

            $statement .= $line;
            $in_string  = self::closes_open_string( $line, $in_string );

            // A statement ends on a semicolon that is not inside a value.
            if ( ! $in_string && str_ends_with( rtrim( $line ), ';' ) ) {
                $result = $this->run( trim( $statement ) );

                if ( 'executed' === $result ) {
                    ++$executed;
                } elseif ( 'skipped' === $result ) {
                    ++$skipped;
                } else {
                    ++$failed;
                }

                $statement = '';
            }
        }

        fclose( $handle );

        return [
            'executed' => $executed,
            'skipped'  => $skipped,
            'failed'   => $failed,
        ];
    }

    /** Execute one statement if the allow list accepts it. */
    private function run( string $statement ): string {
        global $wpdb;

        if ( '' === $statement || ';' === $statement ) {
            return 'skipped';
        }

        if ( ! self::is_allowed( $statement ) ) {
            return 'skipped';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A dump is a sequence of complete statements with nothing to bind. What may run is constrained by self::ALLOWED, checked immediately above.
        $result = $wpdb->query( $statement );

        if ( false === $result ) {
            /*
             * The failing statement is deliberately not returned to the caller:
             * a dump line can carry password hashes or private option values.
             */
            return 'failed';
        }

        return 'executed';
    }

    private static function is_allowed( string $statement ): bool {
        foreach ( self::ALLOWED as $pattern ) {
            if ( preg_match( $pattern, $statement ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a quoted value is still open at the end of this line.
     *
     * Escaped backslashes and escaped quotes are removed first so only real
     * string delimiters are counted. An odd count flips the state.
     */
    private static function closes_open_string( string $line, bool $in_string ): bool {
        $stripped = str_replace( [ '\\\\', "\\'" ], '', $line );
        $quotes   = substr_count( $stripped, "'" );

        if ( 0 === $quotes % 2 ) {
            return $in_string;
        }

        return ! $in_string;
    }
}
