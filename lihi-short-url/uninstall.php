<?php
/**
 * Uninstall handler for the lihi Short URL plugin.
 *
 * Runs once when the user deletes the plugin from the WordPress admin and
 * removes every option / transient the plugin writes, so no data is left
 * behind in the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/client/lihi-exceptions.php';
require_once __DIR__ . '/includes/store/lihi-token-store.php';

$lihi_tokens = null;

try {
    $lihi_tokens = new \Lihi\ShortUrl\Lihi_Token_Store();
    $lihi_tokens->transition_activation( false );
} catch ( \Throwable $e ) {
    // Never remove a lock owned by an in-flight Login / Refresh. Keep the
    // missing activation generation when it cannot drain, but remove secrets.
    if ( $lihi_tokens instanceof \Lihi\ShortUrl\Lihi_Token_Store ) {
        try {
            $lihi_tokens->disable();
        } catch ( \Throwable $disable_error ) {
            // Continue to the purge; lifecycle errors are not exposed.
        }

        try {
            $lihi_tokens->delete();
        } catch ( \Throwable $delete_error ) {
            // A missing activation epoch keeps an undeleted tuple unusable.
        }
    }
}
