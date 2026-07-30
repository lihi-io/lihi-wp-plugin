<?php
namespace Lihi\ShortUrl;

/**
 * Plugin Name: lihi Short URL
 * Description: Adds lihi Short URL controls to create, copy, and edit short URLs for posts, pages, media, and public post types in wp-admin.
 * Version: 1.0.5
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Author: lihi
 * Author URI: https://lihi.io
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lihi-short-url
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Remove all plugin-owned site data when the plugin is deactivated.
 */
function auth_lifecycle_store(): Lihi_Token_Store {
    require_once __DIR__ . '/includes/client/lihi-exceptions.php';
    require_once __DIR__ . '/includes/store/lihi-token-store.php';

    return new Lihi_Token_Store();
}

/**
 * Best-effort fallback for a failed lifecycle transition.
 *
 * Cleanup failures must not escape a WordPress hook, but the activation epoch
 * is removed first so any credential row that could not be deleted remains
 * unusable.
 */
function fail_closed_auth_cleanup( Lihi_Token_Store $tokens ): void {
    try {
        $tokens->disable();
    } catch ( \Throwable $e ) {
        // Continue to the credential purge without exposing lifecycle errors.
    }

    try {
        $tokens->delete();
    } catch ( \Throwable $e ) {
        // The disabled epoch keeps an undeleted tuple unusable.
    }
}

function deactivate(): void {
    $tokens = null;

    try {
        $tokens = auth_lifecycle_store();

        // Removing the activation generation makes every already-loaded Login
        // / Refresh request fail closed. The purge and final disabled state
        // are linearized under the shared auth lock.
        $tokens->transition_activation( false );
    } catch ( \Throwable $e ) {
        // Never delete a lock owned by another request. Authentication remains
        // disabled, and TokenStore::set() rejects an unguarded late write.
        if ( $tokens instanceof Lihi_Token_Store ) {
            fail_closed_auth_cleanup( $tokens );
        }
    }
}

/**
 * Start every activation disconnected, after draining an auth operation that
 * may still be exiting from a concurrent deactivation request.
 */
function activate(): void {
    $tokens = null;

    try {
        $tokens = auth_lifecycle_store();
        $tokens->transition_activation( true );
    } catch ( \Throwable $e ) {
        // Leave the disabled fence in place if the auth lock cannot be safely
        // acquired. The settings page will fail closed instead of restoring a
        // possibly in-flight session.
        if ( $tokens instanceof Lihi_Token_Store ) {
            fail_closed_auth_cleanup( $tokens );
        }
    }
}

register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

// All plugin functionality is admin-only; bail early on front-end requests.
if ( ! is_admin() ) {
    return;
}

require_once plugin_dir_path( __FILE__ ) . 'bootstrap.php';
