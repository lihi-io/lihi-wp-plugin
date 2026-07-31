<?php
namespace Lihi\ShortUrl;

/**
 * Persistent storage and locking for one coherent lihi identity/credential
 * tuple.
 *
 * The account email, server-issued client UUID, access token, and refresh
 * token are stored together. This keeps the visible account identity aligned
 * with the credentials while the upstream refresh grant rotates both tokens
 * on every successful use. The option is non-autoloaded and site-scoped. The
 * refresh lock is also option-backed so it coordinates concurrent PHP
 * requests without requiring a persistent object cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Lihi_Token_Store {

    // One protected request may spend up to 15 seconds in HTTP. Waiters allow
    // a small completion/persistence margin before reporting contention.
    public const AUTH_LOCK_WAIT_US = 18_000_000;

    private const OPTION_KEY        = 'lihi_auth_tokens';
    private const LOCK_KEY          = 'lihi_auth_tokens_lock';
    private const EPOCH_KEY         = 'lihi_auth_epoch';
    // Owners renew between remote legs. Lifecycle fencing prevents another
    // renewal, so its sub-30-second drain can safely outlive this lease.
    private const LOCK_TTL          = 20;
    private const LIFECYCLE_WAIT_US = 22_000_000;

    private string $lock_value = '';
    private string $request_epoch;
    private bool $credentials_cached = false;

    /**
     * @var array{
     *   email: string,
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }|false
     */
    private $credentials_cache = false;

    public function __construct() {
        // Capture the activation generation once for this PHP request. A
        // request loaded before a fast deactivate/reactivate cycle can never
        // write under the replacement generation.
        $this->request_epoch = $this->read_epoch_direct() ?? '';
    }

    /**
     * @return array{
     *   email: string,
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }|false
     */
    public function get() {
        if ( $this->credentials_cached ) {
            return $this->credentials_cache;
        }

        return $this->get_fresh();
    }

    /**
     * Read credentials directly from the database and replace the
     * request-lifetime memoized value.
     *
     * Service concurrency paths use this method after waiting for or
     * acquiring the auth lease so they can observe another request's write.
     *
     * @return array{
     *   email: string,
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }|false
     */
    public function get_fresh() {
        if ( ! $this->request_epoch_is_current() ) {
            $this->credentials_cache  = false;
            $this->credentials_cached = true;
            return $this->credentials_cache;
        }

        $stored = $this->read_option_direct( self::OPTION_KEY );
        if ( is_string( $stored ) ) {
            $stored = maybe_unserialize( $stored );
        }

        $this->credentials_cache  = $this->normalize( $stored );
        $this->credentials_cached = true;

        return $this->credentials_cache;
    }

    /**
     * Persist one complete credential tuple and verify the database read-back.
     *
     * @throws Lihi_Validation_Exception When credentials are incomplete.
     * @throws Lihi_Server_Exception When the tuple cannot be persisted.
     */
    public function set(
        string $email,
        string $uuid,
        string $access_token,
        string $refresh_token
    ): void {
        $tokens = $this->normalize( [
            'email'         => $email,
            'uuid'          => $uuid,
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
        ] );

        if ( false === $tokens ) {
            throw new Lihi_Validation_Exception(
                esc_html( 'Invalid lihi authentication credentials.' )
            );
        }

        $this->ensure_enabled();
        $this->ensure_lock_owner();

        if ( ! $this->persist_tuple_if_guarded( $tokens ) ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to persist lihi authentication credentials.' )
            );
        }
    }

    /**
     * @throws Lihi_Server_Exception When credentials cannot be deleted or
     *                               their deletion cannot be verified.
     */
    public function delete(): void {
        $this->delete_option_by_name( self::OPTION_KEY );

        if ( null !== $this->read_option_direct( self::OPTION_KEY ) ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to clear lihi authentication credentials.' )
            );
        }
    }

    /**
     * Delete credentials only while they still contain the rejected access
     * token. Callers coordinate writes through this store's option lock.
     */
    public function delete_if_access_token( string $access_token ): bool {
        $this->ensure_lock_owner();

        $raw    = $this->read_option_direct( self::OPTION_KEY );
        $tokens = is_string( $raw )
            ? $this->normalize( maybe_unserialize( $raw ) )
            : false;

        if (
            false === $tokens
            || ! hash_equals( $tokens['access_token'], $access_token )
        ) {
            return false;
        }

        $this->delete_option_if_exact( self::OPTION_KEY, $raw );

        $remaining = $this->get_fresh();
        return false === $remaining
            || ! hash_equals( $remaining['access_token'], $access_token );
    }

    /**
     * Delete credentials only while they still belong to the expected
     * server-issued login session.
     *
     * Refresh may have written a replacement access token before a persistence
     * verification failure is detected, so access-token comparison alone is
     * insufficient for cleanup. A new Login always receives a different UUID.
     */
    public function delete_if_uuid( string $uuid ): bool {
        $this->ensure_lock_owner();

        $uuid   = trim( $uuid );
        $raw    = $this->read_option_direct( self::OPTION_KEY );
        $tokens = is_string( $raw )
            ? $this->normalize( maybe_unserialize( $raw ) )
            : false;

        if (
            false === $tokens
            || $uuid === ''
            || ! hash_equals( $tokens['uuid'], $uuid )
        ) {
            return false;
        }

        $this->delete_option_if_exact( self::OPTION_KEY, $raw );

        $remaining = $this->get_fresh();
        return false === $remaining
            || ! hash_equals( $remaining['uuid'], $uuid );
    }

    public function has_credentials(): bool {
        return false !== $this->get();
    }

    /**
     * Disable authentication by removing the active generation. Reads and
     * writes from every already-loaded request then fail closed.
     */
    public function disable(): void {
        $epoch = $this->read_epoch_direct();
        if ( null !== $epoch ) {
            $this->delete_option_if_exact(
                self::EPOCH_KEY,
                $epoch
            );
        }

        if ( null !== $this->read_epoch_direct() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to disable lihi authentication.' )
            );
        }
    }

    public function enable(): void {
        $epoch = bin2hex( random_bytes( 32 ) );
        if ( ! $this->insert_option_if_absent( self::EPOCH_KEY, $epoch ) ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to enable lihi authentication.' )
            );
        }

        $persisted = $this->read_epoch_direct();
        if ( ! is_string( $persisted ) || ! hash_equals( $epoch, $persisted ) ) {
            $this->delete_option_if_exact( self::EPOCH_KEY, $epoch );
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to enable lihi authentication.' )
            );
        }

        $this->request_epoch = $epoch;
    }

    public function ensure_enabled(): void {
        if ( ! $this->request_epoch_is_current() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'lihi authentication is disabled.' )
            );
        }
    }

    /**
     * Disable, purge, and optionally establish a fresh activation generation
     * while holding the same auth mutex for the complete state transition.
     */
    public function transition_activation(
        bool $enable_after_purge,
        int $max_wait_us = self::LIFECYCLE_WAIT_US
    ): void {
        // Fence current requests before waiting for an existing Login /
        // Refresh owner. Repeat under the lock to linearize against another
        // lifecycle transition.
        $this->disable();
        $this->acquire_lock_with_wait( $max_wait_us );

        try {
            $this->disable();
            $this->delete();

            if ( $enable_after_purge ) {
                $this->enable();
            } elseif ( null !== $this->read_epoch_direct() ) {
                throw new Lihi_Server_Exception(
                    esc_html( 'Unable to disable lihi authentication.' )
                );
            }
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Acquire the refresh lock. A stale owner can be replaced after LOCK_TTL.
     */
    public function acquire_lock(): bool {
        if ( $this->lock_value !== '' ) {
            if (
                $this->read_lock_direct()
                === $this->lock_value
            ) {
                return true;
            }

            $this->lock_value = '';
        }

        $value = time() . ':' . bin2hex( random_bytes( 16 ) );
        if ( $this->insert_option_if_absent( self::LOCK_KEY, $value ) ) {
            $this->lock_value = $value;
            return true;
        }

        $stored = $this->read_lock_direct();
        if ( null === $stored ) {
            return false;
        }

        if ( ! $this->lock_is_active( $stored ) ) {
            if (
                $this->delete_lock_if_value( $stored )
                && $this->insert_option_if_absent( self::LOCK_KEY, $value )
            ) {
                $this->lock_value = $value;
                return true;
            }
        }

        return false;
    }

    /**
     * Extend the current owner's lease with a new byte-exact lock value.
     *
     * @throws Lihi_Server_Exception When the database update fails.
     */
    public function renew_lock(): bool {
        if ( $this->lock_value === '' ) {
            return false;
        }

        global $wpdb;

        if (
            ! isset( $wpdb, $wpdb->options )
            || ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'query' )
        ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to renew the lihi authentication lock.' )
            );
        }

        $previous = $this->lock_value;
        $renewed  = time() . ':' . bin2hex( random_bytes( 16 ) );
        $query    = $wpdb->prepare(
            "UPDATE {$wpdb->options}
            SET option_value = %s
            WHERE option_name = %s
                AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
            $renewed,
            self::LOCK_KEY,
            $previous
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Atomic lease-owner CAS must bypass WordPress option caches; the query is prepared above and its cache entry is invalidated below.
        $updated  = $wpdb->query( $query );

        if ( false === $updated || $this->database_has_error() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to renew the lihi authentication lock.' )
            );
        }

        if ( 1 !== $updated ) {
            $this->lock_value = '';
            return false;
        }

        $this->lock_value = $renewed;
        $this->invalidate_option_cache( self::LOCK_KEY );

        $persisted = $this->read_lock_direct();
        if ( ! is_string( $persisted ) || ! hash_equals( $renewed, $persisted ) ) {
            // The UPDATE succeeded, so this request owns `$renewed` unless a
            // later writer already replaced it. Compare-delete that exact
            // value before forgetting ownership; this prevents a failed
            // read-back from leaving an otherwise live lease orphaned until
            // its TTL expires.
            $this->delete_lock_if_value( $renewed );
            $this->lock_value = '';
            return false;
        }

        return true;
    }

    public function release_lock(): void {
        if ( $this->lock_value === '' ) {
            return;
        }

        $owned            = $this->lock_value;
        $this->lock_value = '';
        $this->delete_lock_if_value( $owned );
    }

    /**
     * Wait for another request to replace the rejected access token.
     *
     * @return array{
     *   email: string,
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }|false
     */
    public function wait_for_access_token_change(
        string $rejected_access_token,
        int $max_wait_us = self::AUTH_LOCK_WAIT_US
    ) {
        $sleep_us = 100_000;
        $waited   = 0;

        while ( $waited < $max_wait_us ) {
            usleep( $sleep_us );
            $waited += $sleep_us;

            $tokens = $this->get_fresh();
            if (
                false !== $tokens
                && ! hash_equals(
                    $tokens['access_token'],
                    $rejected_access_token
                )
            ) {
                return $tokens;
            }

            $lock = $this->read_lock_direct();
            if ( null === $lock || ! $this->lock_is_active( $lock ) ) {
                return false;
            }
        }

        return false;
    }

    /**
     * Safely clear all credentials after waiting for an in-flight rotation.
     *
     * @throws Lihi_Server_Exception When the lock remains unavailable.
     */
    public function flush(
        int $max_wait_us = self::AUTH_LOCK_WAIT_US
    ): void {
        $this->acquire_lock_with_wait( $max_wait_us );

        try {
            $this->delete();
        } finally {
            $this->release_lock();
        }
    }

    private function acquire_lock_with_wait( int $max_wait_us ): void {
        $sleep_us = 100_000;
        $waited   = 0;
        $locked   = $this->acquire_lock();

        while ( ! $locked && $waited < $max_wait_us ) {
            usleep( $sleep_us );
            $waited += $sleep_us;
            $locked = $this->acquire_lock();
        }

        if ( ! $locked ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to clear lihi authentication credentials.' )
            );
        }
    }

    private function ensure_lock_owner(): void {
        if ( ! $this->owns_lock() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'lihi authentication lock ownership was lost.' )
            );
        }
    }

    private function owns_lock(): bool {
        $current = $this->read_lock_direct();

        return $this->lock_value !== ''
            && is_string( $current )
            && hash_equals( $this->lock_value, $current );
    }

    /**
     * @param mixed $value
     *
     * @return array{
     *   email: string,
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }|false
     */
    private function normalize( $value ) {
        if (
            ! is_array( $value )
            || ! isset(
                $value['email'],
                $value['uuid'],
                $value['access_token'],
                $value['refresh_token']
            )
            || ! is_string( $value['email'] )
            || ! is_string( $value['uuid'] )
            || ! is_string( $value['access_token'] )
            || ! is_string( $value['refresh_token'] )
        ) {
            return false;
        }

        $email         = strtolower( trim( $value['email'] ) );
        $uuid          = trim( $value['uuid'] );
        $access_token  = $value['access_token'];
        $refresh_token = $value['refresh_token'];

        if (
            $email === ''
            || strlen( $email ) > 254
            || false === filter_var( $email, FILTER_VALIDATE_EMAIL )
            || preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{15,127}$/D', $uuid ) !== 1
            || trim( $access_token ) === ''
            || trim( $refresh_token ) === ''
        ) {
            return false;
        }

        return [
            'email'         => $email,
            'uuid'          => $uuid,
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
        ];
    }

    private function request_epoch_is_current(): bool {
        $current = $this->read_epoch_direct();

        return is_string( $current )
            && preg_match( '/^[a-f0-9]{64}$/', $this->request_epoch ) === 1
            && hash_equals( $this->request_epoch, $current );
    }

    private function read_epoch_direct(): ?string {
        $value = $this->read_option_direct( self::EPOCH_KEY );

        return is_string( $value ) ? $value : null;
    }

    private function read_lock_direct(): ?string {
        $value = $this->read_option_direct( self::LOCK_KEY );

        return is_string( $value ) ? $value : null;
    }

    private function lock_is_active( string $stored ): bool {
        $matches = [];
        if (
            preg_match(
                '/^([1-9][0-9]{0,10}):[a-f0-9]{32}$/',
                $stored,
                $matches
            ) !== 1
        ) {
            return false;
        }

        $locked_at = (int) $matches[1];
        $now       = time();

        return $locked_at <= $now + self::LOCK_TTL
            && $locked_at + self::LOCK_TTL >= $now;
    }

    /**
     * Read security-sensitive state without WordPress's request-local option
     * cache. Another PHP request may rotate credentials or lock ownership
     * after this request has cached the previous value.
     *
     * @throws Lihi_Server_Exception When database access is unavailable or
     *                               the query fails.
     */
    private function read_option_direct( string $option_name ): ?string {
        global $wpdb;

        if (
            ! isset( $wpdb, $wpdb->options )
            || ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'get_var' )
        ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to read lihi authentication state.' )
            );
        }

        $query = $wpdb->prepare(
            "SELECT CONCAT('x', option_value)
            FROM {$wpdb->options}
            WHERE option_name = %s
            LIMIT 1",
            $option_name
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Auth concurrency state requires a fresh options-table read; the query is prepared above and request memoization is handled by the store.
        $marked = $wpdb->get_var( $query );

        if ( $this->database_has_error() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to read lihi authentication state.' )
            );
        }

        return is_string( $marked ) && strncmp( $marked, 'x', 1 ) === 0
            ? substr( $marked, 1 )
            : null;
    }

    /**
     * Atomically insert one non-autoloaded option without WordPress's
     * add_option() upsert behavior.
     *
     * @throws Lihi_Server_Exception When database access is unavailable or
     *                               the insert query fails.
     */
    private function insert_option_if_absent(
        string $option_name,
        string $option_value
    ): bool {
        global $wpdb;

        if (
            ! isset( $wpdb, $wpdb->options )
            || ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'query' )
        ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        $query = $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $option_name,
            $option_value,
            'no'
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Insert-only mutex/epoch creation cannot use add_option() upsert semantics; the query is prepared above and caches are invalidated on success.
        $inserted = $wpdb->query( $query );

        if ( false === $inserted || $this->database_has_error() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        if ( 1 !== $inserted ) {
            return false;
        }

        $this->invalidate_option_cache( $option_name );

        return true;
    }

    /**
     * Persist one tuple only if this request still owns both auth guards.
     *
     * The guard check and upsert are one SQL statement. This avoids a stale
     * lease owner overwriting credentials after another request has replaced
     * the lock, without opening or committing a transaction owned by another
     * WordPress plugin.
     *
     * @param array<string, string> $tokens
     *
     * @throws Lihi_Server_Exception When database access is unavailable or
     *                               a guarded write/direct-read query fails.
     */
    private function persist_tuple_if_guarded( array $tokens ): bool {
        global $wpdb;

        if (
            $this->lock_value === ''
            || ! isset( $wpdb, $wpdb->options )
            || ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'query' )
        ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to persist lihi authentication credentials.' )
            );
        }

        $serialized = maybe_serialize( $tokens );
        $query      = $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
            SELECT %s, %s, %s
            FROM {$wpdb->options} AS auth_lock
            INNER JOIN {$wpdb->options} AS auth_epoch
                ON auth_epoch.option_name = %s
                AND CAST(auth_epoch.option_value AS BINARY) = CAST(%s AS BINARY)
            WHERE auth_lock.option_name = %s
                AND CAST(auth_lock.option_value AS BINARY) = CAST(%s AS BINARY)
            LIMIT 1
            ON DUPLICATE KEY UPDATE
                option_value = VALUES(option_value),
                autoload = VALUES(autoload)",
            self::OPTION_KEY,
            $serialized,
            'no',
            self::EPOCH_KEY,
            $this->request_epoch,
            self::LOCK_KEY,
            $this->lock_value
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Epoch-and-owner guarded persistence must be one atomic SQL statement; the query is prepared above and caches are invalidated below.
        $written = $wpdb->query( $query );

        $this->invalidate_option_cache( self::OPTION_KEY );

        if ( false === $written || $this->database_has_error() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to persist lihi authentication credentials.' )
            );
        }

        $persisted = $this->read_option_direct( self::OPTION_KEY );

        return is_string( $persisted )
            && hash_equals( $serialized, $persisted )
            && $this->request_epoch_is_current()
            && $this->owns_lock();
    }

    /**
     * @throws Lihi_Server_Exception When database access is unavailable or
     *                               the exact-delete query fails.
     */
    private function delete_option_if_exact(
        string $option_name,
        string $serialized_value
    ): bool {
        global $wpdb;

        if (
            ! isset( $wpdb, $wpdb->options )
            || ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'query' )
        ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        $query = $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name = %s
                AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
            $option_name,
            $serialized_value
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Raw-value compare-and-delete prevents removing a replacement row; the query is prepared above and caches are invalidated on success.
        $deleted = $wpdb->query( $query );

        if ( false === $deleted || $this->database_has_error() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        if ( 1 === $deleted ) {
            $this->invalidate_option_cache( $option_name );
        }

        return 1 === $deleted;
    }

    /**
     * Delete an option directly and invalidate both positive and negative
     * option-cache entries. The caller verifies the database read-back.
     *
     * @throws Lihi_Server_Exception When database access is unavailable or
     *                               the delete query fails.
     */
    private function delete_option_by_name( string $option_name ): int {
        global $wpdb;

        if (
            ! isset( $wpdb, $wpdb->options )
            || ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'query' )
        ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        $query = $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name = %s",
            $option_name
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Lifecycle credential deletion requires direct query/read-back verification; the query is prepared above and caches are invalidated below.
        $deleted = $wpdb->query( $query );

        if ( false === $deleted || $this->database_has_error() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        $this->invalidate_option_cache( $option_name );

        return (int) $deleted;
    }

    private function invalidate_option_cache( string $option_name ): void {
        $this->credentials_cached = false;
        $this->credentials_cache  = false;
        wp_cache_delete( $option_name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    /**
     * Compare-and-delete one lock owner directly in the options table.
     *
     * delete_option() performs a read followed by an unconditional delete and
     * could remove a replacement owner. Matching both option name and value in
     * one SQL statement provides the ownership fence required for stale-lock
     * takeover and release.
     *
     * @throws Lihi_Server_Exception When database access is unavailable or
     *                               the exact-delete query fails.
     */
    private function delete_lock_if_value( string $expected ): bool {
        global $wpdb;

        if (
            ! isset( $wpdb, $wpdb->options )
            || ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'query' )
        ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        $query = $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name = %s
                AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
            self::LOCK_KEY,
            $expected
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Lease compare-and-delete must match one byte-exact owner atomically; the query is prepared above and caches are invalidated on success.
        $deleted = $wpdb->query( $query );

        if ( false === $deleted || $this->database_has_error() ) {
            throw new Lihi_Server_Exception(
                esc_html( 'Unable to update lihi authentication state.' )
            );
        }

        if ( 1 === $deleted ) {
            $this->invalidate_option_cache( self::LOCK_KEY );
        }

        return 1 === $deleted;
    }

    private function database_has_error(): bool {
        global $wpdb;

        return isset( $wpdb->last_error )
            && is_string( $wpdb->last_error )
            && $wpdb->last_error !== '';
    }
}
