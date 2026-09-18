<?php
namespace Lihi\ShortUrl;

/**
 * lihi Wordpress API client contract.
 *
 * The login flow uses PKCE:
 *   1. login() exchanges credentials plus a challenge for a short-lived code.
 *   2. exchange_authorization_code() exchanges that code plus the verifier for
 *      a server-issued client UUID, access token, and refresh token.
 *
 * Protected endpoint methods receive the current access token and a fallback
 * callback. The callback receives the rejected access token and must return a
 * replacement access token. Each client method retries its own endpoint at
 * most once.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Lihi_Client_Interface {

    // -------------------------------------------------------------------------
    // Auth (no bearer token)
    // -------------------------------------------------------------------------

    /**
     * Exchange account credentials, the current Wordpress hostname, and a
     * PKCE challenge for an authorization code.
     *
     * POST /api/wordpress/v1/auth/login
     *
     * @return array{code?: string}
     */
    public function login(
        string $email,
        string $password,
        string $code_challenge
    ): array;

    /**
     * Register a new lihi account for the current Wordpress host.
     *
     * POST /api/wordpress/v1/auth/register
     */
    public function register( string $email, string $password ): void;

    /**
     * Exchange a PKCE authorization code for persistent credentials.
     *
     * POST /api/wordpress/v1/auth/token
     *
     * @return array{
     *   uuid?: string,
     *   token?: string,
     *   refresh_token?: string,
     * }
     */
    public function exchange_authorization_code(
        string $code,
        string $code_verifier
    ): array;

    /**
     * Rotate the access and refresh tokens for a server-issued client UUID.
     *
     * POST /api/wordpress/v1/auth/token
     *
     * @return array{
     *   uuid?: string,
     *   token?: string,
     *   refresh_token?: string,
     * }
     */
    public function refresh_access_token(
        string $uuid,
        string $refresh_token
    ): array;

    // -------------------------------------------------------------------------
    // Session termination (bearer token, no fallback)
    // -------------------------------------------------------------------------

    /**
     * Revoke the current Wordpress client session.
     *
     * This bearer-authenticated request is intentionally attempted only once:
     * Logout must continue with local cleanup when the remote session is
     * already invalid or lihi is unavailable.
     *
     * POST /api/wordpress/v1/auth/logout
     */
    public function logout( string $access_token ): void;

    // -------------------------------------------------------------------------
    // Protected API
    // -------------------------------------------------------------------------

    /**
     * @param callable(string): string $access_fallback
     *
     * @return array{
     *   result: bool,
     *   data: array{user_role: ?string, group_name: ?string},
     * }
     */
    public function get_profile(
        string $access_token,
        callable $access_fallback
    ): array;

    /**
     * @param callable(string): string $access_fallback
     *
     * @return array{
     *   result: bool,
     *   data: array{
     *     domains: list<array{id: mixed, name: string}>,
     *     utm_sources: list<string>,
     *     utm_mediums: list<string>,
     *   },
     * }
     */
    public function get_options(
        string $access_token,
        callable $access_fallback
    ): array;

    /**
     * @param callable(string): string $access_fallback
     *
     * @return array{
     *   result: bool,
     *   data: array{
     *     groups: list<array{id: ?int, name: ?string}>,
     *     group_id: ?int,
     *   },
     * }
     */
    public function get_group_options(
        string $access_token,
        callable $access_fallback
    ): array;

    /**
     * @param callable(string): string $access_fallback
     *
     * @return array{
     *   result: bool,
     *   data: array{group_id: ?int},
     * }
     */
    public function switch_group(
        string $access_token,
        ?int $group_id,
        callable $access_fallback
    ): array;

    /**
     * @param callable(string): string $access_fallback
     *
     * @return array{
     *   result: bool,
     *   msg: string,
     *   data: array{nonce: string},
     * }
     */
    public function create_passthrough_nonce(
        string $access_token,
        string $target,
        string $challenge,
        callable $access_fallback
    ): array;

    /**
     * @param int|string               $type_id
     * @param callable(string): string $access_fallback
     *
     * @return array{
     *   result: bool,
     *   msg?: string,
     *   data: array{site: string},
     * }
     */
    public function get_short_link(
        string $access_token,
        string $type,
        $type_id,
        callable $access_fallback
    ): array;

    /**
     * @param array{
     *   domain: string,
     *   urls: list<string>,
     *   type: string,
     *   type_id?: string|int,
     *   tags?: string,
     * } $body
     * @param callable(string): string $access_fallback
     *
     * @return array{
     *   result: bool,
     *   data: array{
     *     id: int,
     *     domain_name: string,
     *     short_url: string,
     *     site_urls: list<array{id: int, url: string}>,
     *     wordpress_link: array{type: string, type_id: string},
     *   },
     * }
     */
    public function create_site(
        string $access_token,
        array $body,
        callable $access_fallback
    ): array;
}
