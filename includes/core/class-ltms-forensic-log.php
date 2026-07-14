<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Forensic_Log {

    /**
     * FL-1 FIX: Genesis hash for the first entry in the chain.
     * Each entry's hash includes the previous entry's hash, making any
     * modification or deletion detectable via verify_chain().
     */
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * FL-1 FIX: computes a deterministic SHA-256 over the immutable fields of
     * a forensic log entry. Used both at insert time (log()) and at verify
     * time (verify_chain()). The hash covers: prev_hash, action, user_id, ip,
     * created_at, user_agent, request_uri, and the caller's context (with keys
     * sorted for determinism). It does NOT include entry_hash itself (circular).
     *
     * @return string 64-char hex SHA-256.
     */
    private static function compute_entry_hash(
        string $prev_hash,
        string $action,
        int    $user_id,
        string $ip,
        string $created_at,
        string $user_agent,
        string $request_uri,
        array  $context
    ): string {
        // Sort context keys so the JSON is deterministic regardless of insertion order.
        ksort( $context );
        $payload = wp_json_encode( [
            'prev_hash'   => $prev_hash,
            'action'      => $action,
            'user_id'     => $user_id,
            'ip'          => $ip,
            'created_at'  => $created_at,
            'user_agent'  => $user_agent,
            'request_uri' => $request_uri,
            'context'     => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return hash( 'sha256', $payload );
    }

    /**
     * FOR-BUG-1 FIX: Proxy-aware IP resolution.
     * Checks X-Forwarded-For when REMOTE_ADDR is a trusted proxy (loopback).
     *
     * @return string Client IP (real IP behind proxy, or REMOTE_ADDR).
     */
    private static function get_client_ip(): string {
        $remote = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );

        // If behind a trusted proxy (loopback or Docker), consult X-Forwarded-For
        $trusted_proxies = [ '127.0.0.1', '::1', '172.16.0.0/12', '10.0.0.0/8' ];
        $is_trusted_proxy = false;
        foreach ( $trusted_proxies as $proxy ) {
            if ( strpos( $proxy, '/' ) !== false ) {
                // CIDR check (simplified — only for IPv4)
                if ( filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                    list( $subnet, $mask ) = explode( '/', $proxy );
                    if ( ( ip2long( $remote ) & ~( ( 1 << ( 32 - $mask ) ) - 1 ) ) === ip2long( $subnet ) ) {
                        $is_trusted_proxy = true;
                        break;
                    }
                }
            } elseif ( $remote === $proxy ) {
                $is_trusted_proxy = true;
                break;
            }
        }

        if ( $is_trusted_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
            if ( filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
                return $forwarded;
            }
        }

        return $remote;
    }

    /**
     * FOR-BUG-2 FIX: Now captures user_agent, request_uri, and context.
     * FOR-BUG-1 FIX: Uses proxy-aware get_client_ip().
     *
     * FL-1 FIX: each entry is now linked to the previous one via a SHA-256
     * hash chain. The chain is stored inside the `context` JSON column under
     * `__prev_hash` and `__entry_hash` keys (graceful degradation if the
     * `context` column does not exist). This makes any subsequent tampering
     * (modification of action/ip/user_id, or deletion of an entry) detectable
     * via verify_chain(). Without the hash chain, an attacker with DB write
     * access could silently DELETE forensic log rows to cover their tracks.
     */
    public static function log( string $action, int $user_id = 0, string $ip = '', array $context = [] ): void {
        global $wpdb;
        if ( empty( $ip ) ) {
            $ip = self::get_client_ip();
        }

        $table = $wpdb->prefix . 'lt_forensic_log';

        // Check if columns exist (graceful degradation if migration not run).
        // Cache the column list in a static to avoid a DESCRIBE on every log() call.
        static $columns = null;
        if ( null === $columns ) {
            $columns = $wpdb->get_col( "DESCRIBE `{$table}`" ) ?: [];
        }

        // v2.9.137 BACKEND-AUDIT P1-1: sanitize $_SERVER values before storing in DB.
        // Before, raw user_agent and request_uri were stored — XSS risk if displayed
        // in admin without esc_html. Now sanitized with substr to prevent overflow.
        $user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '';
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? substr( sanitize_text_field( $_SERVER['REQUEST_URI'] ), 0, 2048 ) : '';
        $created_at  = current_time( 'mysql' );

        // H-6 FIX: wrap the SELECT (last hash) + INSERT in a transaction with
        // SELECT ... FOR UPDATE so concurrent log() calls serialize on the
        // chain tip. Without this, two concurrent calls could both read the
        // same prev_hash, both insert, and produce two entries that link to
        // the SAME previous entry — verify_chain() would then flag the second
        // one as broken_chain_linkage, defeating the tamper-evidence goal of
        // the hash chain. FOR UPDATE acquires an exclusive lock on the last
        // row (or a gap lock on an empty table under InnoDB REPEATABLE READ),
        // forcing the second transaction to block until the first commits.
        $wpdb->query( 'START TRANSACTION' );

        // FL-1 FIX: fetch previous entry's hash to build the chain.
        $prev_hash = self::GENESIS_HASH;
        if ( in_array( 'entry_hash', $columns, true ) ) {
            // H-6 FIX: FOR UPDATE locks the chain tip so concurrent log()
            // calls serialize.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $last = $wpdb->get_var( "SELECT entry_hash FROM `{$table}` ORDER BY id DESC LIMIT 1 FOR UPDATE" );
            if ( $last ) {
                $prev_hash = $last;
            }
        } elseif ( in_array( 'context', $columns, true ) ) {
            // Fallback: read prev_hash from the context JSON of the last row.
            // H-6 FIX: FOR UPDATE here too — the last row is the chain tip.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $last_ctx = $wpdb->get_var( "SELECT context FROM `{$table}` ORDER BY id DESC LIMIT 1 FOR UPDATE" );
            if ( $last_ctx ) {
                $decoded = json_decode( $last_ctx, true );
                if ( is_array( $decoded ) && ! empty( $decoded['__entry_hash'] ) ) {
                    $prev_hash = $decoded['__entry_hash'];
                }
            }
        }

        // Compute this entry's hash over the caller's context (without the internal hash keys).
        $caller_context = $context;
        $entry_hash = self::compute_entry_hash(
            $prev_hash, $action, $user_id, $ip, $created_at, $user_agent, $request_uri, $caller_context
        );

        // Embed hash-chain metadata in the context JSON so it survives even
        // without a dedicated entry_hash column.
        $context['__prev_hash']  = $prev_hash;
        $context['__entry_hash'] = $entry_hash;
        $context_json = wp_json_encode( $context );

        $data = [
            'action'     => $action,
            'user_id'    => $user_id,
            'ip'         => $ip,
            'created_at' => $created_at,
        ];

        if ( in_array( 'user_agent', $columns, true ) ) {
            $data['user_agent'] = $user_agent;
        }
        if ( in_array( 'request_uri', $columns, true ) ) {
            $data['request_uri'] = $request_uri;
        }
        if ( in_array( 'context', $columns, true ) ) {
            $data['context'] = $context_json;
        }
        // FL-1 FIX: store entry_hash in a dedicated column if it exists.
        if ( in_array( 'entry_hash', $columns, true ) ) {
            $data['entry_hash'] = $entry_hash;
        }

        // H-6 FIX: commit the transaction (releasing the FOR UPDATE lock on
        // the previous chain tip) on success, or roll back on failure so the
        // lock is released and no partial/garbage row is left behind. Note
        // $wpdb->insert returns false on error or the number of rows affected
        // (1) on success — we treat anything that is not strictly false as
        // success (a 0-rows-affected result would still indicate the query
        // ran without error, which is the transaction-consistent outcome).
        $result = $wpdb->insert( $table, $data );
        if ( $result === false ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }
        $wpdb->query( 'COMMIT' );
    }

    /**
     * FL-1 FIX: walks the forensic log chain and detects tampering.
     *
     * Returns an array with:
     *   - total: number of entries scanned.
     *   - broken: list of entries with broken chain linkage or hash mismatch.
     *   - first_id: the id of the first entry (useful to detect deletion of early rows).
     *
     * Detection coverage:
     *   1. Hash mismatch → a field was modified after insert (action, ip, user_id, etc.).
     *   2. Broken chain linkage → an entry was deleted from the middle of the table.
     *   3. Missing first entry → the chain no longer starts from the genesis row
     *      (detected by checking if the first scanned row's prev_hash === GENESIS).
     *
     * @param int $limit  Max entries to scan (default 5000).
     * @return array{total: int, broken: array, first_id: int|null}
     */
    public static function verify_chain( int $limit = 5000 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_forensic_log';

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, action, user_id, ip, created_at, user_agent, request_uri, context, entry_hash FROM `{$table}` ORDER BY id ASC LIMIT %d", $limit ),
            ARRAY_A
        ) ?: [];

        $broken   = [];
        $prev_hash = self::GENESIS_HASH;
        $first_id = null;

        foreach ( $rows as $row ) {
            if ( null === $first_id ) {
                $first_id = (int) $row['id'];
            }

            // Extract stored hash data: prefer dedicated column, fall back to context JSON.
            $stored_entry = $row['entry_hash'] ?? '';
            $stored_prev  = self::GENESIS_HASH;
            $caller_ctx   = [];
            if ( ! empty( $row['context'] ) ) {
                $decoded = json_decode( $row['context'], true );
                if ( is_array( $decoded ) ) {
                    if ( ! empty( $decoded['__entry_hash'] ) ) {
                        $stored_entry = $stored_entry ?: $decoded['__entry_hash'];
                    }
                    if ( ! empty( $decoded['__prev_hash'] ) ) {
                        $stored_prev = $decoded['__prev_hash'];
                    }
                    // Strip internal keys to recover the caller's original context.
                    $caller_ctx = $decoded;
                    unset( $caller_ctx['__prev_hash'], $caller_ctx['__entry_hash'] );
                }
            }

            // Check 1: chain linkage — prev_hash must equal the previous entry's entry_hash.
            if ( $stored_prev !== $prev_hash ) {
                $broken[] = [
                    'id'           => (int) $row['id'],
                    'issue'        => 'broken_chain_linkage',
                    'expected_prev'=> $prev_hash,
                    'actual_prev'  => $stored_prev,
                ];
            }

            // Check 2: hash re-computation — detects field modification.
            $computed = self::compute_entry_hash(
                $stored_prev,
                $row['action'],
                (int) $row['user_id'],
                $row['ip'],
                $row['created_at'],
                $row['user_agent'] ?? '',
                $row['request_uri'] ?? '',
                $caller_ctx
            );
            if ( $stored_entry && ! hash_equals( $stored_entry, $computed ) ) {
                $broken[] = [
                    'id'        => (int) $row['id'],
                    'issue'     => 'hash_mismatch',
                    'stored'    => $stored_entry,
                    'computed'  => $computed,
                ];
            }

            $prev_hash = $stored_entry ?: $computed;
        }

        // Check 3: if the first row's prev_hash is not GENESIS, earlier rows were deleted.
        if ( $rows && ! empty( $rows[0]['context'] ) ) {
            $first_decoded = json_decode( $rows[0]['context'], true );
            if ( is_array( $first_decoded ) && isset( $first_decoded['__prev_hash'] )
                && $first_decoded['__prev_hash'] !== self::GENESIS_HASH ) {
                $broken[] = [
                    'id'    => (int) $rows[0]['id'],
                    'issue' => 'missing_earlier_entries',
                    'note'  => 'First visible entry does not link to genesis — earlier rows may have been deleted.',
                ];
            }
        }

        return [
            'total'    => count( $rows ),
            'broken'   => $broken,
            'first_id' => $first_id,
        ];
    }

    public static function get_recent( int $limit = 10 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lt_forensic_log ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        ) ?: [];
    }
}
