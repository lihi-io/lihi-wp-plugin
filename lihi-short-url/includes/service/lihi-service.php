<?php
namespace Lihi\ShortUrl;

/**
 * lihi service layer.
 *
 * Authentication uses a server-side PKCE flow and stores one coherent
 * UUID/access/refresh tuple. Protected API calls share a request-lifetime
 * access context. When the client invokes the fallback, the context is
 * updated by reference so later endpoints in the same workflow use the newly
 * rotated access token.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Lihi_Service {

    private Lihi_Client_Interface $client;
    private Lihi_Token_Store $tokens;

    public function __construct(
        Lihi_Client_Interface $client,
        Lihi_Token_Store $tokens
    ) {
        $this->client = $client;
        $this->tokens = $tokens;
    }

    /**
     * Authenticate an existing account through PKCE and persist the returned
     * credentials atomically.
     *
     * @return array{
     *   email: string,
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }
     */
    public function login( string $email, string $password ): array {
        $this->tokens->ensure_enabled();
        $this->acquire_login_lock();
        $failure = null;

        try {
            $this->tokens->ensure_enabled();
            $verifier  = $this->create_pkce_verifier();
            $challenge = $this->create_pkce_challenge( $verifier );
            $login     = $this->client->login(
                $email,
                $password,
                $challenge
            );
            $this->tokens->ensure_enabled();
            $code      = $login['code'] ?? '';

            if (
                ! is_string( $code )
                || preg_match( '/^[A-Za-z0-9_-]{43}$/', $code ) !== 1
            ) {
                throw new Lihi_Server_Exception(
                    esc_html__( 'Invalid authorization code returned from lihi API.', 'lihi-short-url' )
                );
            }

            $this->renew_auth_lock_or_throw();
            $response    = $this->client->exchange_authorization_code(
                $code,
                $verifier
            );
            $this->tokens->ensure_enabled();
            $this->renew_auth_lock_or_throw();
            $credentials = $this->normalize_credentials_response(
                $response,
                '',
                $email
            );

            $this->tokens->set(
                $credentials['email'],
                $credentials['uuid'],
                $credentials['access_token'],
                $credentials['refresh_token']
            );

            return $credentials;
        } catch ( \Throwable $e ) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $this->tokens->release_lock();
            } catch ( \Throwable $release_error ) {
                if ( null === $failure ) {
                    throw $release_error;
                }
            }
        }
    }

    /**
     * Register a new account. Registration does not create a local session;
     * the user must verify the email and then log in.
     */
    public function register( string $email, string $password ): void {
        $this->tokens->ensure_enabled();
        $this->client->register( $email, $password );
    }

    public function logout(): void {
        $this->acquire_login_lock();
        $failure = null;

        try {
            $credentials = false;

            try {
                $credentials = $this->tokens->get_fresh();
            } catch ( \Throwable $e ) {
                // A failed read must not prevent the direct local purge below.
            }

            if ( false !== $credentials ) {
                try {
                    $this->client->logout( $credentials['access_token'] );
                } catch ( \Throwable $e ) {
                    // Remote Logout is best-effort. The local session remains
                    // authoritative and must always be cleared.
                }
            }

            $this->tokens->delete();
        } catch ( \Throwable $e ) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $this->tokens->release_lock();
            } catch ( \Throwable $release_error ) {
                if ( null === $failure ) {
                    throw $release_error;
                }
            }
        }
    }

    /**
     * @return array{user_role: ?string, group_name: ?string}
     */
    public function get_profile(): array {
        $result = $this->with_access_token(
            function ( $context, callable $fallback ): array {
                return $this->client->get_profile(
                    $context->access_token,
                    $fallback
                );
            }
        );

        return $this->normalize_profile_response( $result );
    }

    /**
     * @return array{
     *   domains?: list<mixed>,
     *   utm_sources?: list<string>,
     *   utm_mediums?: list<string>,
     * }
     */
    public function get_url_options(): array {
        $result = $this->with_access_token(
            function ( $context, callable $fallback ): array {
                return $this->client->get_options(
                    $context->access_token,
                    $fallback
                );
            }
        );

        return $result['data'] ?? [];
    }

    /**
     * @return array{
     *   groups?: list<array{id: ?int, name: ?string}>,
     *   group_id?: ?int,
     * }
     */
    public function get_work_group_options(): array {
        $result = $this->with_access_token(
            function ( $context, callable $fallback ): array {
                return $this->client->get_group_options(
                    $context->access_token,
                    $fallback
                );
            }
        );

        return $result['data'] ?? [];
    }

    public function switch_work_group( ?int $group_id ): ?int {
        $result = $this->with_access_token(
            function ( $context, callable $fallback ) use ( $group_id ): array {
                return $this->client->switch_group(
                    $context->access_token,
                    $group_id,
                    $fallback
                );
            }
        );
        $data = $result['data'] ?? null;
        if ( ! is_array( $data ) || ! array_key_exists( 'group_id', $data ) ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid work group returned from lihi API.', 'lihi-short-url' )
            );
        }
        $switched_group_id = $data['group_id'];

        if ( null === $group_id && null === $switched_group_id ) {
            return null;
        }
        if (
            ! is_int( $switched_group_id )
            && ! (
                is_string( $switched_group_id )
                && preg_match( '/^[1-9][0-9]*$/', $switched_group_id )
            )
        ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid work group returned from lihi API.', 'lihi-short-url' )
            );
        }

        $switched_group_id = (int) $switched_group_id;
        if ( $switched_group_id !== $group_id ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'lihi returned a different work group than requested.', 'lihi-short-url' )
            );
        }

        return $switched_group_id;
    }

    public function get_or_create_short_url(
        int $item_id,
        string $type,
        array $options = []
    ): string {
        return $this->with_access_token(
            function ( $context, callable $fallback ) use (
                $item_id,
                $type,
                $options
            ): string {
                return $this->fetch_or_create(
                    $context,
                    $fallback,
                    $item_id,
                    $type,
                    $options
                );
            }
        );
    }

    public function get_existing_short_url(
        int $item_id,
        string $type
    ): string {
        return $this->with_access_token(
            function ( $context, callable $fallback ) use (
                $item_id,
                $type
            ): string {
                return $this->fetch_existing(
                    $context,
                    $fallback,
                    $item_id,
                    $type
                );
            }
        );
    }

    public function create_passthrough_nonce(
        string $target,
        string $challenge
    ): string {
        return $this->with_access_token(
            function ( $context, callable $fallback ) use (
                $target,
                $challenge
            ): string {
                return $this->fetch_passthrough_nonce(
                    $context,
                    $fallback,
                    $target,
                    $challenge
                );
            }
        );
    }

    /**
     * Run a protected workflow with one mutable access-token context.
     *
     * @param callable(object, callable): mixed $operation
     *
     * @return mixed
     */
    private function with_access_token( callable $operation ) {
        $this->tokens->ensure_enabled();
        $credentials = $this->tokens->get_fresh();
        if ( false === $credentials ) {
            throw new Lihi_Token_Invalid_Exception(
                esc_html__( 'Please log in to lihi before using this feature.', 'lihi-short-url' )
            );
        }

        $context = (object) [
            'access_token' => $credentials['access_token'],
            'uuid'         => $credentials['uuid'],
        ];

        $fallback = function ( string $rejected_access_token ) use ( $context ): string {
            $replacement           = $this->refresh_access_token(
                $rejected_access_token,
                $context->uuid
            );
            $context->access_token = $replacement;

            return $replacement;
        };

        try {
            return call_user_func(
                $operation,
                $context,
                $fallback
            );
        } catch ( Lihi_User_Invalid_Exception $e ) {
            try {
                $this->clear_credentials_if_access_token(
                    $context->access_token
                );
            } catch ( \Throwable $cleanup_error ) {
                // Preserve the upstream account error if cleanup also fails.
            }
            throw $e;
        } catch ( Lihi_Token_Invalid_Exception $e ) {
            try {
                $this->clear_credentials_if_access_token(
                    $context->access_token
                );
            } catch ( \Throwable $cleanup_error ) {
                // The re-login error must not be hidden by cleanup failure.
            }
            throw $e;
        }
    }

    /**
     * Rotate credentials after an access-token rejection.
     *
     * Only one request may call the upstream refresh grant. Other requests
     * wait for that request to persist a different access token and reuse it.
     */
    private function refresh_access_token(
        string $rejected_access_token,
        string $expected_uuid
    ): string {
        $this->tokens->ensure_enabled();
        $current = $this->tokens->get_fresh();
        if ( false === $current ) {
            throw new Lihi_Token_Invalid_Exception(
                esc_html__( 'Your lihi login session has expired.', 'lihi-short-url' )
            );
        }

        $this->assert_same_session( $current, $expected_uuid );

        if (
            ! hash_equals(
                $current['access_token'],
                $rejected_access_token
            )
        ) {
            return $current['access_token'];
        }

        $locked = $this->tokens->acquire_lock();
        if ( ! $locked ) {
            $changed = $this->tokens->wait_for_access_token_change(
                $rejected_access_token
            );
            if ( false !== $changed ) {
                $this->assert_same_session( $changed, $expected_uuid );
                return $changed['access_token'];
            }

            $locked = $this->tokens->acquire_lock();
        }

        if ( ! $locked ) {
            throw new Lihi_Authentication_Busy_Exception(
                esc_html__( 'lihi authentication is busy. Please try again.', 'lihi-short-url' )
            );
        }

        $failure = null;

        try {
            $this->tokens->ensure_enabled();
            $current = $this->tokens->get_fresh();
            if ( false === $current ) {
                throw new Lihi_Token_Invalid_Exception(
                    esc_html__( 'Your lihi login session has expired.', 'lihi-short-url' )
                );
            }

            $this->assert_same_session( $current, $expected_uuid );

            if (
                ! hash_equals(
                    $current['access_token'],
                    $rejected_access_token
                )
            ) {
                return $current['access_token'];
            }

            try {
                $response = $this->client->refresh_access_token(
                    $current['uuid'],
                    $current['refresh_token']
                );
                $this->tokens->ensure_enabled();
                $this->renew_auth_lock_or_throw();
                $replacement = $this->normalize_credentials_response(
                    $response,
                    $current['uuid'],
                    $current['email']
                );
                $this->tokens->set(
                    $replacement['email'],
                    $replacement['uuid'],
                    $replacement['access_token'],
                    $replacement['refresh_token']
                );
            } catch ( \Throwable $e ) {
                // Any failed rotation requires a fresh Login. Delete only the
                // tuple that initiated this refresh so a newer concurrent
                // Login can never be removed by an older request.
                try {
                    $this->tokens->delete_if_uuid(
                        $current['uuid']
                    );
                } catch ( \Throwable $cleanup_error ) {
                    // Preserve the original refresh failure. The caller must
                    // still receive the re-login message if cleanup itself
                    // loses the lock or encounters a database error.
                }

                throw new Lihi_Token_Invalid_Exception(
                    esc_html__( 'Could not refresh the lihi login session. Please sign in again.', 'lihi-short-url' ),
                    0,
                    $e
                );
            }

            return $replacement['access_token'];
        } catch ( \Throwable $e ) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $this->tokens->release_lock();
            } catch ( \Throwable $release_error ) {
                if ( null === $failure ) {
                    throw $release_error;
                }
            }
        }
    }

    /**
     * Do not replay an in-flight operation under a newly connected account.
     *
     * A concurrent refresh retains the same server-issued UUID, while a new
     * Login creates a new UUID. The UUID therefore fences one request to the
     * account session that originally started it.
     *
     * @param array{uuid: string} $credentials
     */
    private function assert_same_session(
        array $credentials,
        string $expected_uuid
    ): void {
        if (
            isset( $credentials['uuid'] )
            && is_string( $credentials['uuid'] )
            && hash_equals( $expected_uuid, $credentials['uuid'] )
        ) {
            return;
        }

        throw new Lihi_Token_Invalid_Exception(
            esc_html__( 'The lihi account connection changed. Please try the request again.', 'lihi-short-url' )
        );
    }

    /**
     * Clear a terminally rejected credential tuple without deleting a newer
     * tuple written by another request.
     */
    private function clear_credentials_if_access_token(
        string $rejected_access_token
    ): void {
        $current = $this->tokens->get_fresh();
        if (
            false === $current
            || ! hash_equals(
                $current['access_token'],
                $rejected_access_token
            )
        ) {
            return;
        }

        $locked = $this->tokens->acquire_lock();
        if ( ! $locked ) {
            $changed = $this->tokens->wait_for_access_token_change(
                $rejected_access_token
            );
            if ( false !== $changed ) {
                return;
            }

            $locked = $this->tokens->acquire_lock();
        }

        if ( ! $locked ) {
            // Preserving a possibly stale tuple is safer than deleting a newer
            // rotation without owning the lock.
            return;
        }

        try {
            $this->tokens->delete_if_access_token(
                $rejected_access_token
            );
        } finally {
            $this->tokens->release_lock();
        }
    }

    private function acquire_login_lock(): void {
        $sleep_us = 100_000;
        $waited   = 0;

        while ( true ) {
            if ( $this->tokens->acquire_lock() ) {
                return;
            }

            if ( $waited >= Lihi_Token_Store::AUTH_LOCK_WAIT_US ) {
                break;
            }

            $delay = min(
                $sleep_us,
                Lihi_Token_Store::AUTH_LOCK_WAIT_US - $waited
            );
            usleep( $delay );
            $waited += $delay;
        }

        throw new Lihi_Authentication_Busy_Exception(
            esc_html__( 'lihi authentication is busy. Please try again.', 'lihi-short-url' )
        );
    }

    private function renew_auth_lock_or_throw(): void {
        if ( $this->tokens->renew_lock() ) {
            return;
        }

        throw new Lihi_Authentication_Busy_Exception(
            esc_html__( 'lihi authentication is busy. Please try again.', 'lihi-short-url' )
        );
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array{user_role: ?string, group_name: ?string}
     */
    private function normalize_profile_response( array $response ): array {
        $data = $response['data'] ?? null;
        if ( ! is_array( $data ) ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid profile returned from lihi API.', 'lihi-short-url' )
            );
        }

        $user_role  = $data['user_role'] ?? null;
        $group_name = $data['group_name'] ?? null;
        if (
            ( null !== $user_role && ! is_string( $user_role ) )
            || ( null !== $group_name && ! is_string( $group_name ) )
        ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid profile returned from lihi API.', 'lihi-short-url' )
            );
        }

        return [
            'user_role'  => $user_role,
            'group_name' => $group_name,
        ];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array{
     *   email: string,
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }
     */
    private function normalize_credentials_response(
        array $response,
        string $expected_uuid = '',
        string $email = ''
    ): array {
        $email         = strtolower( trim( $email ) );
        $uuid          = $response['uuid'] ?? '';
        $access_token  = $response['token'] ?? '';
        $refresh_token = $response['refresh_token'] ?? '';

        if (
            $email === ''
            || ! is_string( $uuid )
            || ! is_string( $access_token )
            || ! is_string( $refresh_token )
        ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid credentials returned from lihi API.', 'lihi-short-url' )
            );
        }

        $uuid = trim( $uuid );
        if (
            preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{15,127}$/D', $uuid ) !== 1
            || trim( $access_token ) === ''
            || trim( $refresh_token ) === ''
            || (
                $expected_uuid !== ''
                && ! hash_equals(
                    $expected_uuid,
                    $uuid
                )
            )
        ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid credentials returned from lihi API.', 'lihi-short-url' )
            );
        }

        return [
            'email'         => $email,
            'uuid'          => $uuid,
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
        ];
    }

    private function create_pkce_verifier(): string {
        return $this->base64url_encode( random_bytes( 32 ) );
    }

    private function create_pkce_challenge( string $verifier ): string {
        return $this->base64url_encode(
            hash( 'sha256', $verifier, true )
        );
    }

    private function base64url_encode( string $value ): string {
        return rtrim(
            strtr( base64_encode( $value ), '+/', '-_' ),
            '='
        );
    }

    private function fetch_or_create(
        object $context,
        callable $fallback,
        int $item_id,
        string $type,
        array $options
    ): string {
        $host     = lihi_site_host();
        $api_type = $type . ':' . $host;
        $result   = $this->client->get_short_link(
            $context->access_token,
            $api_type,
            $item_id,
            $fallback
        );
        $site     = $result['data']['site'] ?? '';
        $existing = is_string( $site ) ? trim( $site ) : '';

        if ( $existing !== '' ) {
            return $existing;
        }

        $domain = isset( $options['domain'] ) && is_string( $options['domain'] )
            ? $options['domain']
            : '';
        $tags = $this->build_tags( $options['tags'] ?? [] );
        $utm  = $this->normalize_utm( $options['utm'] ?? [] );
        $url  = $this->apply_utm_to_url(
            lihi_resolve_url( $item_id, $type ),
            $utm
        );

        $created = $this->client->create_site(
            $context->access_token,
            [
                'urls'    => [ $url ],
                'type'    => $api_type,
                'type_id' => (string) $item_id,
                'domain'  => $domain,
                'tags'    => implode( ',', $tags ),
            ],
            $fallback
        );
        $short_url = $created['data']['short_url'] ?? '';

        if ( ! is_string( $short_url ) || trim( $short_url ) === '' ) {
            throw new \RuntimeException(
                esc_html__( 'No short_url returned from lihi API.', 'lihi-short-url' )
            );
        }

        return $short_url;
    }

    private function fetch_existing(
        object $context,
        callable $fallback,
        int $item_id,
        string $type
    ): string {
        $host     = lihi_site_host();
        $api_type = $type . ':' . $host;
        $result   = $this->client->get_short_link(
            $context->access_token,
            $api_type,
            $item_id,
            $fallback
        );
        $site     = $result['data']['site'] ?? '';
        $existing = is_string( $site ) ? trim( $site ) : '';

        if ( $existing === '' ) {
            throw new Lihi_Not_Found_Exception(
                esc_html__( 'Short URL has been removed. Please create it again.', 'lihi-short-url' )
            );
        }

        return $existing;
    }

    private function fetch_passthrough_nonce(
        object $context,
        callable $fallback,
        string $target,
        string $challenge
    ): string {
        $result = $this->client->create_passthrough_nonce(
            $context->access_token,
            $target,
            $challenge,
            $fallback
        );
        $nonce  = $result['data']['nonce'] ?? '';

        if ( ! is_string( $nonce ) || trim( $nonce ) === '' ) {
            throw new \RuntimeException(
                esc_html__( 'No passthrough nonce returned from lihi API.', 'lihi-short-url' )
            );
        }

        return $nonce;
    }

    private function build_tags( array $custom_tags ): array {
        $tags = [];

        foreach ( $custom_tags as $tag ) {
            if ( ! is_scalar( $tag ) ) {
                continue;
            }

            $tag = trim( (string) $tag );
            if ( $tag !== '' ) {
                $tags[] = $tag;
            }
        }

        return array_values( array_unique( $tags ) );
    }

    private function normalize_utm( $utm ): array {
        if ( ! is_array( $utm ) ) {
            return [];
        }

        $normalized = [];
        foreach (
            [ 'source', 'medium', 'campaign', 'term', 'content' ]
            as $key
        ) {
            if (
                ! isset( $utm[ $key ] )
                || ! is_scalar( $utm[ $key ] )
            ) {
                continue;
            }

            $value = trim( (string) $utm[ $key ] );
            if ( $value !== '' ) {
                $normalized[ $key ] = $value;
            }
        }

        return $normalized;
    }

    private function apply_utm_to_url(
        string $url,
        array $utm
    ): string {
        if ( $utm === [] ) {
            return $url;
        }

        $params = [];
        foreach ( $utm as $key => $value ) {
            $params[ 'utm_' . $key ] = $value;
        }

        return add_query_arg( $params, $url );
    }
}
