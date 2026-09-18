<?php
namespace Lihi\ShortUrl;

/**
 * Global helper functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/lihi-singletons.php';

/**
 * Return the configured lihi API / app host.
 */
function lihi_api_host(): string {
    return 'https://app.lihi.io';
}

/**
 * Return the email address used to authenticate with the lihi API.
 */
function lihi_email(): string {
    try {
        $credentials = Lihi_Singletons::lihi_token_store()->get();
    } catch ( \Throwable $e ) {
        return '';
    }

    return false === $credentials
        ? ''
        : $credentials['email'];
}

/**
 * Return whether WordPress has a complete authenticated lihi session.
 */
function lihi_is_authenticated(): bool {
    try {
        return Lihi_Singletons::lihi_token_store()->has_credentials();
    } catch ( \Throwable $e ) {
        return false;
    }
}

/**
 * Return the current WordPress site's host.
 *
 * @throws \RuntimeException When home_url() has no usable host.
 */
function lihi_site_host(): string {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( ! is_string( $host ) || $host === '' ) {
        throw new \RuntimeException( esc_html__( 'Could not resolve WordPress site host.', 'lihi-short-url' ) );
    }

    return $host;
}

/**
 * Return the browser-facing passthrough redirect URL.
 */
function lihi_passthrough_redirect_url(): string {
    $api_host = lihi_api_host();
    if ( $api_host === '' ) {
        return '';
    }

    return $api_host . '/api/wordpress/v1/passthrough/redirect';
}

/**
 * Return the public lihi entry URL used when no WordPress JWT is available.
 */
function lihi_home_url(): string {
    return 'https://lihi.io';
}

/**
 * Return the browser-facing lihi password reset URL.
 */
function lihi_password_reset_url(): string {
    $api_host = lihi_api_host();
    if ( $api_host === '' ) {
        return '';
    }

    return $api_host . '/admin/password/reset';
}

/**
 * Resolve the permalink / file URL for a given item.
 *
 * @throws \RuntimeException When the item does not exist or has no URL.
 */
function lihi_resolve_url( int $item_id, string $type ): string {
    $url = $type === 'attachment'
        ? wp_get_attachment_url( $item_id )
        : get_permalink( $item_id );

    if ( ! is_string( $url ) || $url === '' ) {
        throw new \RuntimeException(
            sprintf(
                /* translators: 1: item type, 2: item ID */
                esc_html__( 'Could not resolve URL for %1$s %2$d.', 'lihi-short-url' ),
                esc_html( $type ),
                absint( $item_id )
            )
        );
    }

    return $url;
}
