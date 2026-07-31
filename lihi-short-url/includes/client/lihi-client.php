<?php
namespace Lihi\ShortUrl;

/**
 * Production lihi Wordpress API client.
 *
 * Auth endpoints are unprotected. Protected endpoints all flow through one
 * retry wrapper which replaces a rejected access token through a caller-owned
 * fallback and retries the same endpoint exactly once.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Lihi_Client implements Lihi_Client_Interface {

    private string $base_url;

    public function __construct( string $base_url ) {
        $this->base_url = rtrim( $base_url, '/' );
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function login(
        string $email,
        string $password,
        string $code_challenge
    ): array {
        $hostname = $this->required_tenant_host();
        $response = $this->request( 'POST', '/api/wordpress/v1/auth/login', [
            'email'          => $email,
            'hostname'       => $hostname,
            'password'       => $password,
            'code_challenge' => $code_challenge,
        ] );
        $data = $this->decode( $response['code'], $response['body'] );

        $this->throw_for_login_error( $response['code'], $data );

        return $this->response_data( $data );
    }

    public function register( string $email, string $password ): void {
        $hostname = $this->required_tenant_host();
        $response = $this->request( 'POST', '/api/wordpress/v1/auth/register', [
            'email'    => $email,
            'hostname' => $hostname,
            'password' => $password,
        ] );
        $data = $this->decode( $response['code'], $response['body'] );

        if ( $response['code'] === 400 ) {
            throw new Lihi_Validation_Exception( esc_html( $this->msg( $data ) ) );
        }
        if ( $response['code'] === 403 ) {
            if ( $this->message_is( $data, 'registration country unavailable' ) ) {
                throw new Lihi_Registration_Country_Unavailable_Exception(
                    esc_html( $this->msg( $data ) )
                );
            }
            $this->throw_forbidden_response( $data );
        }
        if ( $response['code'] === 409 ) {
            throw new Lihi_Account_Already_Exists_Exception(
                esc_html( $this->msg( $data ) )
            );
        }

        $this->throw_for_auth_request_failure( $response['code'], $data );
    }

    public function exchange_authorization_code(
        string $code,
        string $code_verifier
    ): array {
        $response = $this->request( 'POST', '/api/wordpress/v1/auth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $code_verifier,
        ] );
        $data = $this->decode( $response['code'], $response['body'] );

        if ( $response['code'] === 400 ) {
            throw new Lihi_Validation_Exception( esc_html( $this->msg( $data ) ) );
        }
        if ( $response['code'] === 403 ) {
            if ( $this->message_is( $data, 'code invalid' ) ) {
                throw new Lihi_Authorization_Code_Invalid_Exception(
                    esc_html( $this->msg( $data ) )
                );
            }
            $this->throw_forbidden_response( $data );
        }

        $this->throw_for_auth_request_failure( $response['code'], $data );

        return $this->response_data( $data );
    }

    public function refresh_access_token(
        string $uuid,
        string $refresh_token
    ): array {
        $response = $this->request( 'POST', '/api/wordpress/v1/auth/token', [
            'grant_type'    => 'refresh_token',
            'uuid'          => $uuid,
            'refresh_token' => $refresh_token,
        ] );
        $data = $this->decode( $response['code'], $response['body'] );

        if ( $response['code'] === 400 ) {
            throw new Lihi_Validation_Exception( esc_html( $this->msg( $data ) ) );
        }
        if (
            $response['code'] === 403
            && $this->message_is( $data, 'refresh token invalid' )
        ) {
            throw new Lihi_Refresh_Token_Invalid_Exception(
                esc_html( $this->msg( $data ) )
            );
        }
        if ( $response['code'] === 403 ) {
            $this->throw_forbidden_response( $data );
        }

        $this->throw_for_auth_request_failure( $response['code'], $data );

        return $this->response_data( $data );
    }

    // -------------------------------------------------------------------------
    // Protected API
    // -------------------------------------------------------------------------

    public function get_profile(
        string $access_token,
        callable $access_fallback
    ): array {
        $response = $this->authenticated_request(
            'GET',
            '/api/wordpress/v1/user/profile',
            [],
            $access_token,
            $access_fallback
        );

        $this->throw_for_unsuccessful_response(
            $response['code'],
            $response['data']
        );

        return $response['data'];
    }

    public function get_options(
        string $access_token,
        callable $access_fallback
    ): array {
        $response = $this->authenticated_request(
            'GET',
            '/api/wordpress/v1/user/domain-options',
            [],
            $access_token,
            $access_fallback
        );

        $this->throw_validation_response( $response['code'], $response['data'] );
        $this->throw_for_unsuccessful_response(
            $response['code'],
            $response['data']
        );

        return $response['data'];
    }

    public function get_group_options(
        string $access_token,
        callable $access_fallback
    ): array {
        $response = $this->authenticated_request(
            'GET',
            '/api/wordpress/v1/user/group-options',
            [],
            $access_token,
            $access_fallback
        );

        $this->throw_for_unsuccessful_response(
            $response['code'],
            $response['data']
        );

        return $response['data'];
    }

    public function switch_group(
        string $access_token,
        ?int $group_id,
        callable $access_fallback
    ): array {
        $response = $this->authenticated_request(
            'POST',
            '/api/wordpress/v1/user/switch-group',
            [ 'group_id' => $group_id ],
            $access_token,
            $access_fallback
        );

        $this->throw_validation_response( $response['code'], $response['data'] );
        $this->throw_for_unsuccessful_response(
            $response['code'],
            $response['data']
        );

        return $response['data'];
    }

    public function create_passthrough_nonce(
        string $access_token,
        string $target,
        string $challenge,
        callable $access_fallback
    ): array {
        $body = [ 'challenge' => $challenge ];
        if ( $target !== '' ) {
            $body['target'] = $target;
        }

        $response = $this->authenticated_request(
            'POST',
            '/api/wordpress/v1/passthrough/nonce',
            $body,
            $access_token,
            $access_fallback
        );

        $this->throw_validation_response( $response['code'], $response['data'] );
        $this->throw_for_unsuccessful_response(
            $response['code'],
            $response['data']
        );

        return $response['data'];
    }

    public function get_short_link(
        string $access_token,
        string $type,
        $type_id,
        callable $access_fallback
    ): array {
        $response = $this->authenticated_request(
            'GET',
            '/api/wordpress/v1/site/find',
            [
                'type'    => $type,
                'type_id' => (string) $type_id,
            ],
            $access_token,
            $access_fallback
        );

        $this->throw_validation_response( $response['code'], $response['data'] );
        $this->throw_for_unsuccessful_response(
            $response['code'],
            $response['data']
        );

        return $response['data'];
    }

    public function create_site(
        string $access_token,
        array $body,
        callable $access_fallback
    ): array {
        $response = $this->authenticated_request(
            'POST',
            '/api/wordpress/v1/site/store',
            $body,
            $access_token,
            $access_fallback
        );

        $this->throw_site_create_response( $response['code'], $response['data'] );
        $this->throw_for_unsuccessful_response(
            $response['code'],
            $response['data']
        );

        return $response['data'];
    }

    // -------------------------------------------------------------------------
    // Core
    // -------------------------------------------------------------------------

    /**
     * Execute one protected request, replacing the access token and retrying
     * only this endpoint when the first response is HTTP 401.
     *
     * @param callable(string): string $access_fallback
     *
     * @return array{code: int, data: array}
     */
    private function authenticated_request(
        string $method,
        string $path,
        array $body,
        string $access_token,
        callable $access_fallback
    ): array {
        try {
            return $this->authenticated_request_once(
                $method,
                $path,
                $body,
                $access_token
            );
        } catch ( Lihi_Token_Invalid_Exception $e ) {
            $replacement = call_user_func( $access_fallback, $access_token );
            if ( ! is_string( $replacement ) || trim( $replacement ) === '' ) {
                throw new Lihi_Token_Invalid_Exception(
                    esc_html( 'No replacement access token is available.' )
                );
            }

            return $this->authenticated_request_once(
                $method,
                $path,
                $body,
                $replacement
            );
        }
    }

    /**
     * @return array{code: int, data: array}
     */
    private function authenticated_request_once(
        string $method,
        string $path,
        array $body,
        string $access_token
    ): array {
        $response = $this->request(
            $method,
            $path,
            $body,
            $access_token
        );

        $data = $this->decode(
            $response['code'],
            $response['body']
        );

        if ( $response['code'] === 401 ) {
            throw new Lihi_Token_Invalid_Exception(
                esc_html( $this->msg( $data ) )
            );
        }
        if ( $response['code'] >= 500 && $response['code'] < 600 ) {
            throw new Lihi_Server_Exception(
                esc_html( $this->msg( $data ) )
            );
        }
        if ( $this->is_user_invalid_response( $data ) ) {
            throw new Lihi_User_Invalid_Exception(
                esc_html( $this->msg( $data ) )
            );
        }
        if ( $response['code'] === 403 ) {
            $this->throw_forbidden_response( $data );
        }
        if ( $response['code'] === 429 ) {
            throw new Lihi_Rate_Limit_Exception(
                esc_html( $this->msg( $data ) )
            );
        }

        return [
            'code' => $response['code'],
            'data' => $data,
        ];
    }

    /**
     * @return array{code: int, body: string}
     */
    private function request(
        string $method,
        string $path,
        array $data = [],
        string $access_token = ''
    ): array {
        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ( $access_token !== '' ) {
            $headers['Authorization'] = 'Bearer ' . $access_token;
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 15,
        ];

        if ( $method === 'GET' && ! empty( $data ) ) {
            $url = $this->base_url . $path . '?' . http_build_query( $data );
        } else {
            $url = $this->base_url . $path;
            if ( ! empty( $data ) ) {
                $args['body'] = wp_json_encode( $data );
            }
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new Lihi_Server_Exception(
                esc_html( $response->get_error_message() )
            );
        }

        return [
            'code' => wp_remote_retrieve_response_code( $response ),
            'body' => wp_remote_retrieve_body( $response ),
        ];
    }

    /**
     * Decode a JSON body. HTML and other non-JSON responses are never treated
     * as refreshable access-token failures.
     */
    private function decode( int $code, string $body ): array {
        if ( $body === '' ) {
            throw new Lihi_Server_Exception(
                esc_html( sprintf( 'HTTP %d: Empty response', $code ) )
            );
        }

        $decoded = json_decode( $body, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $decoded;
        }

        preg_match( '/<title>([^<]*)<\/title>/i', $body, $matches );
        $title   = isset( $matches[1] ) ? trim( $matches[1] ) : '';
        $detail  = $title !== '' ? $title : substr( $body, 0, 100 );
        $message = sprintf( 'HTTP %d: %s', $code, $detail );

        throw new Lihi_Server_Exception( esc_html( $message ) );
    }

    private function throw_for_login_error( int $code, array $data ): void {
        if ( $code === 400 ) {
            throw new Lihi_Validation_Exception( esc_html( $this->msg( $data ) ) );
        }
        if ( $code === 403 ) {
            if ( $this->is_user_invalid_response( $data ) ) {
                throw new Lihi_User_Invalid_Exception(
                    esc_html( $this->msg( $data ) )
                );
            }
            if ( $this->message_is( $data, 'account does not exist' ) ) {
                throw new Lihi_Account_Not_Found_Exception(
                    esc_html( $this->msg( $data ) )
                );
            }
            if ( $this->is_password_invalid_response( $data ) ) {
                throw new Lihi_Email_Or_Password_Invalid_Exception(
                    esc_html( $this->msg( $data ) )
                );
            }

            $this->throw_forbidden_response( $data );
        }

        $this->throw_for_auth_request_failure( $code, $data );
    }

    private function throw_for_auth_request_failure(
        int $code,
        array $data
    ): void {
        if ( $code === 429 ) {
            throw new Lihi_Rate_Limit_Exception(
                esc_html( $this->msg( $data ) )
            );
        }
        if (
            $code < 200
            || $code >= 300
            || ( $data['result'] ?? null ) !== true
        ) {
            throw new Lihi_Server_Exception( esc_html( $this->msg( $data ) ) );
        }
    }

    private function throw_forbidden_response( array $data ): void {
        if ( $this->is_user_invalid_response( $data ) ) {
            throw new Lihi_User_Invalid_Exception(
                esc_html( $this->msg( $data ) )
            );
        }

        throw new Lihi_Auth_Exception( esc_html( $this->msg( $data ) ) );
    }

    private function throw_validation_response( int $code, array $data ): void {
        if ( $code === 400 ) {
            throw new Lihi_Validation_Exception( esc_html( $this->msg( $data ) ) );
        }
    }

    private function throw_site_create_response( int $code, array $data ): void {
        if ( $code !== 400 ) {
            return;
        }

        if ( $this->message_is( $data, 'need_upgrade' ) ) {
            throw new Lihi_Need_Upgrade_Exception(
                esc_html( $this->msg( $data ) )
            );
        }

        $this->throw_validation_response( $code, $data );
    }

    private function throw_for_unsuccessful_response(
        int $code,
        array $data
    ): void {
        if (
            $code < 200
            || $code >= 300
            || ( $data['result'] ?? null ) !== true
        ) {
            throw new Lihi_Server_Exception( esc_html( $this->msg( $data ) ) );
        }
    }

    private function tenant_host(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        return is_string( $host ) ? strtolower( trim( $host ) ) : '';
    }

    private function required_tenant_host(): string {
        $hostname = $this->tenant_host();
        if ( $hostname === '' ) {
            throw new Lihi_Validation_Exception(
                esc_html( 'Could not resolve the Wordpress site hostname.' )
            );
        }

        return $hostname;
    }

    private function response_data( array $response ): array {
        $data = $response['data'] ?? [];
        return is_array( $data ) ? $data : [];
    }

    private function is_user_invalid_response( array $data ): bool {
        $message = trim( $this->msg( $data ) );

        return strcasecmp( $message, 'User Invalid' ) === 0
            || stripos( $message, 'user_not_found' ) !== false;
    }

    private function is_password_invalid_response( array $data ): bool {
        return $this->message_is( $data, 'password invalid' )
            || $this->message_is( $data, 'email or password invalid' );
    }

    private function message_is( array $data, string $expected ): bool {
        return strcasecmp( trim( $this->msg( $data ) ), $expected ) === 0;
    }

    private function msg( array $data ): string {
        $message = $data['msg'] ?? ( $data['data']['message'] ?? 'Unknown error' );
        return is_string( $message ) ? $message : wp_json_encode( $message );
    }
}
