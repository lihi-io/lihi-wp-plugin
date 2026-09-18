<?php
namespace Lihi\ShortUrl;

/**
 * AJAX endpoints for the Short URL column buttons.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Browser admin-ajax request failed local validation before any lihi API call. */
class Lihi_Ajax_Bad_Request_Exception extends \RuntimeException {}

function ajax_posted_value( string $field, $default = '' ) {
    $field = sanitize_key( $field );
    if ( $field === '' ) {
        return $default;
    }

    // AJAX handlers verify nonces before calling this request helper.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST[ $field ] ) ) {
        return $default;
    }

    // Raw helper; callers sanitize scalar text or JSON-decoded values.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    return wp_unslash( $_POST[ $field ] );
}

function ajax_posted_text_field( string $field, string $default = '' ): string {
    $value = ajax_posted_value( $field, $default );

    if ( ! is_scalar( $value ) ) {
        return $default;
    }

    return trim( sanitize_text_field( (string) $value ) );
}

function parse_json_array_field( string $field ): array {
    $raw = ajax_posted_value( $field, '[]' );
    if ( ! is_scalar( $raw ) ) {
        return [];
    }

    $decoded = json_decode( (string) $raw, true );
    if ( ! is_array( $decoded ) ) {
        return [];
    }

    $values = [];
    foreach ( $decoded as $value ) {
        if ( ! is_scalar( $value ) ) {
            continue;
        }

        $sanitized = trim( sanitize_text_field( (string) $value ) );
        if ( $sanitized !== '' ) {
            $values[] = $sanitized;
        }
    }

    return array_values( array_unique( $values ) );
}

function parse_utm_field(): array {
    $raw = ajax_posted_value( 'utm', '{}' );
    if ( ! is_scalar( $raw ) ) {
        return [];
    }

    $decoded = json_decode( (string) $raw, true );
    if ( ! is_array( $decoded ) ) {
        return [];
    }

    $utm = [];
    foreach ( [ 'source', 'medium', 'campaign', 'term', 'content' ] as $key ) {
        if ( ! isset( $decoded[ $key ] ) || ! is_scalar( $decoded[ $key ] ) ) {
            continue;
        }

        $value = trim( sanitize_text_field( (string) $decoded[ $key ] ) );
        if ( $value !== '' ) {
            $utm[ $key ] = $value;
        }
    }

    return $utm;
}

function parse_domain_field(): string {
    $domain = ajax_posted_text_field( 'domain' );

    if ( $domain === '' ) {
        throw new Lihi_Validation_Exception( esc_html__( 'Please choose a redirect domain.', 'lihi-short-url' ) );
    }

    return $domain;
}

function parse_passthrough_challenge_field(): string {
    $challenge = ajax_posted_text_field( 'challenge' );

    if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
        throw new Lihi_Ajax_Bad_Request_Exception( esc_html__( 'Could not verify browser session. Please try again.', 'lihi-short-url' ) );
    }

    return $challenge;
}

function parse_passthrough_target_field(): string {
    $raw_target = ajax_posted_value( 'target', null );
    if ( $raw_target === null ) {
        return '';
    }

    if ( ! is_scalar( $raw_target ) ) {
        throw new Lihi_Ajax_Bad_Request_Exception( esc_html__( 'Invalid lihi dashboard target.', 'lihi-short-url' ) );
    }

    $target = trim( (string) $raw_target );

    if ( $target === '' ) {
        return '';
    }

    if ( strlen( $target ) > 2048 || ! preg_match( '/^(?:\/(?!\/)|https?:\/\/[^\s\/?#]+)[^\s\x00-\x1F\x7F]*\z/', $target ) ) {
        throw new Lihi_Ajax_Bad_Request_Exception( esc_html__( 'Invalid lihi dashboard target.', 'lihi-short-url' ) );
    }

    return $target;
}

function sanitize_option_value( $value ): string {
    if ( ! is_scalar( $value ) ) {
        return '';
    }

    return trim( sanitize_text_field( (string) $value ) );
}

function available_url_option_domains( array $options ): array {
    $domains = [];
    $seen    = [];

    foreach ( $options['domains'] ?? [] as $domain ) {
        $value = '';
        $label = '';

        if ( is_array( $domain ) ) {
            $value = sanitize_option_value( $domain['id'] ?? '' );
            $label = sanitize_option_value( $domain['name'] ?? '' );
        } elseif ( is_scalar( $domain ) ) {
            $value = sanitize_option_value( $domain );
            $label = $value;
        } else {
            continue;
        }

        if ( $label === '' ) {
            $label = $value;
        }
        if ( $value === '' ) {
            $value = $label;
        }
        if ( $value === '' || isset( $seen[ $value ] ) ) {
            continue;
        }

        $seen[ $value ] = true;
        $domains[]      = [
            'value' => $value,
            'label' => $label,
        ];
    }

    return $domains;
}

function available_url_option_utm( array $options ): array {
    $utm = [
        'source' => [],
        'medium' => [],
    ];

    foreach ( [ 'source' => 'utm_sources', 'medium' => 'utm_mediums' ] as $key => $field ) {
        foreach ( $options[ $field ] ?? [] as $value ) {
            $value = sanitize_option_value( $value );
            if ( $value !== '' ) {
                $utm[ $key ][] = $value;
            }
        }

        $utm[ $key ] = array_values( array_unique( $utm[ $key ] ) );
    }

    return $utm;
}

function validate_lihi_item_request(): array {
    $item_id = intval( ajax_posted_text_field( 'item_id', '0' ) );

    if ( ! $item_id ) {
        wp_send_json_error( __( 'Invalid post ID or type.', 'lihi-short-url' ), 400 );
        return [ 0, '' ];
    }

    $type = get_post_type( $item_id );
    if ( ! is_string( $type ) || $type === '' ) {
        wp_send_json_error( __( 'Invalid post ID or type.', 'lihi-short-url' ), 400 );
        return [ 0, '' ];
    }

    if ( ! current_user_can( 'read_post', $item_id ) ) {
        wp_send_json_error( __( 'You do not have permission to generate a short URL for this item.', 'lihi-short-url' ), 403 );
        return [ 0, '' ];
    }

    if ( ! lihi_is_authenticated() ) {
        wp_send_json_error( __( 'lihi is not connected. Please log in under Settings → lihi Short URL.', 'lihi-short-url' ), 409 );
        return [ 0, '' ];
    }

    return [ $item_id, $type ];
}

function validate_lihi_edit_permission(): bool {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to edit lihi short URLs.', 'lihi-short-url' ), 403 );
        return false;
    }

    return true;
}

function handle_lihi_ajax_exception( \Throwable $e, array $context = [] ): void {
    if ( $e instanceof Lihi_Authentication_Busy_Exception ) {
        wp_send_json_error( __( 'Another lihi authentication request is in progress. Please wait and try again.', 'lihi-short-url' ), 409 );
        return;
    }

    if ( $e instanceof Lihi_User_Invalid_Exception ) {
        wp_send_json_error( __( 'Your lihi account is unavailable. Please contact lihi support before creating short URLs.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_Token_Invalid_Exception ) {
        wp_send_json_error( __( 'Your lihi login session could not be refreshed. Please sign in again under Settings → lihi Short URL.', 'lihi-short-url' ), 401 );
        return;
    }

    if ( $e instanceof Lihi_Auth_Exception ) {
        wp_send_json_error( __( 'lihi rejected this account session. Please open Settings → lihi Short URL and sign in again.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_Ajax_Bad_Request_Exception ) {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON payload is rendered with textContent in lihi-button.js.
        wp_send_json_error( $e->getMessage(), 400 );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return;
    }

    if ( $e instanceof Lihi_Need_Upgrade_Exception ) {
        $data = [
            'code'    => 'need_upgrade',
            'message' => __( 'Please upgrade or renew your lihi plan to create this short URL.', 'lihi-short-url' ),
        ];

        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON payload is rendered with textContent in lihi-button.js.
        wp_send_json_error( $data, 400 );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return;
    }

    if ( $e instanceof Lihi_Validation_Exception ) {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON payload is rendered with textContent in lihi-button.js.
        /* translators: %s: validation error message returned by the lihi API. */
        wp_send_json_error( sprintf( __( 'lihi API rejected the request: %s', 'lihi-short-url' ), $e->getMessage() ), 400 );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return;
    }

    if ( $e instanceof Lihi_Not_Found_Exception && isset( $context['missing_item_id'] ) ) {
        update_post_meta( (int) $context['missing_item_id'], 'lihi_already', '0' );
        wp_send_json_error( [
            'code'    => 'lihi_missing',
            'message' => __( 'Short URL has been removed. Please create it again.', 'lihi-short-url' ),
        ], 410 );
        return;
    }

    if ( $e instanceof Lihi_Rate_Limit_Exception ) {
        wp_send_json_error( __( 'Too many requests. Please wait a moment and try again.', 'lihi-short-url' ), 429 );
        return;
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $log_prefix = isset( $context['log_prefix'] ) && is_string( $context['log_prefix'] )
            ? trim( $context['log_prefix'] )
            : '';
        $log_message = $log_prefix !== ''
            ? $log_prefix . ': ' . $e->getMessage()
            : $e->getMessage();
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics gated behind WP_DEBUG.
        error_log( '[lihi] ' . $log_message );
    }

    if ( $e instanceof Lihi_Server_Exception ) {
        wp_send_json_error( __( 'The lihi service is unavailable. Please try again later.', 'lihi-short-url' ), 503 );
        return;
    }

    $message = isset( $context['fallback_message'] ) && is_string( $context['fallback_message'] )
        ? $context['fallback_message']
        : __( 'Request failed. Please try again later.', 'lihi-short-url' );
    wp_send_json_error( $message, 500 );
}

function ajax_url_options(): void {
    check_ajax_referer( 'lihi_short_url', 'nonce' );

    list( $item_id ) = validate_lihi_item_request();
    if ( ! $item_id ) {
        return;
    }

    try {
        $options = Lihi_Singletons::lihi_service()->get_url_options();
        $domains = available_url_option_domains( $options );
        $utm     = available_url_option_utm( $options );
        wp_send_json_success( [
            'domains'     => $domains,
            'utm_options' => $utm,
        ] );
    } catch ( \Throwable $e ) {
        handle_lihi_ajax_exception( $e, [
            'fallback_message' => __( 'Could not load account information.', 'lihi-short-url' ),
            'log_prefix'       => 'url options fetch failed',
        ] );
    }
}

add_action( 'wp_ajax_lihi_url_options', __NAMESPACE__ . '\\ajax_url_options' );

/**
 * AJAX handler: fetch an existing lihi short URL for a post and return it to
 * the browser for clipboard copy.
 *
 * Exported as a named function (rather than an inline closure) so tests can
 * invoke it directly without walking $wp_filter.
 */
function ajax_copy_url(): void {
    check_ajax_referer( 'lihi_short_url', 'nonce' );

    list( $item_id, $type ) = validate_lihi_item_request();
    if ( ! $item_id ) {
        return;
    }

    try {
        $url = Lihi_Singletons::lihi_service()->get_existing_short_url( $item_id, $type );
        update_post_meta( $item_id, 'lihi_already', '1' );
        wp_send_json_success( [
            'url'          => $url,
            'lihi_already' => true,
        ] );
    } catch ( \Throwable $e ) {
        handle_lihi_ajax_exception( $e, [
            'fallback_message' => __( 'Failed to copy short URL. Please try again later.', 'lihi-short-url' ),
            'missing_item_id'  => $item_id,
        ] );
    }
}

add_action( 'wp_ajax_lihi_copy_url', __NAMESPACE__ . '\\ajax_copy_url' );

/**
 * AJAX handler: create a passthrough nonce for a frontend-selected lihi-admin
 * target.
 */
function ajax_passthrough_nonce(): void {
    check_ajax_referer( 'lihi_short_url', 'nonce' );

    if ( ! validate_lihi_edit_permission() ) {
        return;
    }

    list( $item_id ) = validate_lihi_item_request();
    if ( ! $item_id ) {
        return;
    }

    try {
        $challenge = parse_passthrough_challenge_field();
        $target    = parse_passthrough_target_field();

        $nonce        = Lihi_Singletons::lihi_service()->create_passthrough_nonce( $target, $challenge );
        $redirect_url = lihi_passthrough_redirect_url();
        if ( $redirect_url === '' ) {
            throw new \RuntimeException( 'Could not resolve lihi passthrough redirect URL.' );
        }

        wp_send_json_success( [
            'nonce'        => $nonce,
            'redirect_url' => $redirect_url,
        ] );
    } catch ( \Throwable $e ) {
        handle_lihi_ajax_exception( $e, [
            'fallback_message' => __( 'Failed to open lihi dashboard. Please try again later.', 'lihi-short-url' ),
            'log_prefix'       => 'passthrough nonce failed',
        ] );
    }
}

add_action( 'wp_ajax_lihi_passthrough_nonce', __NAMESPACE__ . '\\ajax_passthrough_nonce' );

/**
 * AJAX handler: create (or fetch-and-create) a lihi short URL for a post using
 * modal options, then return it to the browser for clipboard copy.
 */
function ajax_create_url(): void {
    check_ajax_referer( 'lihi_short_url', 'nonce' );

    list( $item_id, $type ) = validate_lihi_item_request();
    if ( ! $item_id ) {
        return;
    }

    try {
        $domain = parse_domain_field();
        $url    = Lihi_Singletons::lihi_service()->get_or_create_short_url( $item_id, $type, [
            'domain' => $domain,
            'tags'   => parse_json_array_field( 'tags' ),
            'utm'    => parse_utm_field(),
        ] );
        update_post_meta( $item_id, 'lihi_already', '1' );
        wp_send_json_success( [
            'url'          => $url,
            'lihi_already' => true,
        ] );
    } catch ( \Throwable $e ) {
        handle_lihi_ajax_exception( $e, [
            'fallback_message' => __( 'Failed to generate short URL. Please try again later.', 'lihi-short-url' ),
        ] );
    }
}

add_action( 'wp_ajax_lihi_create_url', __NAMESPACE__ . '\\ajax_create_url' );
