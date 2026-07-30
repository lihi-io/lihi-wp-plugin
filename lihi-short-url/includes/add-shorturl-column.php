<?php
namespace Lihi\ShortUrl;

/**
 * Adds a "lihi Short URL" column to all public post type list tables.
 *
 * Dynamically registers column hooks for every public post type so that
 * custom post types are supported without extra configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function render_lihi_button_container( int $post_id, string $post_type ): string {
    $already = get_post_meta( $post_id, 'lihi_already', true ) === '1';

    return '<div class="lihi-button-container" data-lihi-container data-id="' . esc_attr( $post_id ) . '" data-type="' . esc_attr( $post_type ) . '" data-lihi-already="' . esc_attr( $already ? '1' : '0' ) . '"></div>';
}

function lihi_button_asset_version( string $asset ): string {
    $path     = plugin_dir_path( __FILE__ ) . '../assets/' . ltrim( $asset, '/' );
    $modified = file_exists( $path ) ? filemtime( $path ) : false;

    return $modified ? '1.0.5-' . $modified : '1.0.5';
}

// UI hooks only register when a complete access / refresh credential bundle is
// present. AJAX handlers remain unconditional so stale pages receive JSON.
if ( lihi_is_authenticated() ) {

// Enqueue lihi-button.js on list screens, media library, and post edit
// (so the button works inside the media modal opened from the editor).
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    $allowed = [ 'edit.php', 'upload.php', 'post.php', 'post-new.php' ];
    if ( ! in_array( $hook, $allowed, true ) ) {
        return;
    }

    wp_enqueue_style(
        'lihi-button',
        plugin_dir_url( __FILE__ ) . '../assets/lihi-button.css',
        [],
        lihi_button_asset_version( 'lihi-button.css' )
    );

    wp_enqueue_script(
        'lihi-button-api',
        plugin_dir_url( __FILE__ ) . '../assets/lihi-button-api.js',
        [],
        lihi_button_asset_version( 'lihi-button-api.js' ),
        true
    );

    wp_enqueue_script(
        'lihi-button-modal',
        plugin_dir_url( __FILE__ ) . '../assets/lihi-button-modal.js',
        [ 'lihi-button-api' ],
        lihi_button_asset_version( 'lihi-button-modal.js' ),
        true
    );

    wp_enqueue_script(
        'lihi-button',
        plugin_dir_url( __FILE__ ) . '../assets/lihi-button.js',
        [ 'lihi-button-api', 'lihi-button-modal' ],
        lihi_button_asset_version( 'lihi-button.js' ),
        true
    );

    wp_localize_script( 'lihi-button-api', 'lihiButton', [
        'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
        'nonce'                 => wp_create_nonce( 'lihi_short_url' ),
        'copyAction'            => 'lihi_copy_url',
        'createAction'          => 'lihi_create_url',
        'optionsAction'         => 'lihi_url_options',
        'passthroughAction'     => 'lihi_passthrough_nonce',
        'canEditShortUrl'       => current_user_can( 'manage_options' ) ? '1' : '0',
        'passthroughRedirectUrl' => lihi_passthrough_redirect_url(),
        'siteHost'              => lihi_site_host(),
        'labelOriginal'         => __( 'Create', 'lihi-short-url' ),
        'labelReady'            => __( 'Copy', 'lihi-short-url' ),
        'labelEdit'             => __( 'Edit', 'lihi-short-url' ),
        'labelCopied'           => __( 'Copied!', 'lihi-short-url' ),
        'requestFailed'         => __( 'Request failed. Please try again later.', 'lihi-short-url' ),
        'copyFallback'          => __( 'Clipboard access was blocked. Copy this short URL:', 'lihi-short-url' ),
        'resetDelay'            => 300,
        'labelDelay'            => 1200,
        'modal'                 => [
            'title'              => __( 'Create lihi Short URL', 'lihi-short-url' ),
            'domain'             => __( 'Domain', 'lihi-short-url' ),
            'customDomain'       => __( 'Custom domain?', 'lihi-short-url' ),
            'noDomains'          => __( 'No redirect domains are available for this lihi account.', 'lihi-short-url' ),
            'tags'               => __( 'Tags', 'lihi-short-url' ),
            'tagsAdd'            => __( 'Add', 'lihi-short-url' ),
            'tagRecommendations' => __( 'Recommended tags', 'lihi-short-url' ),
            'addRecommendedTag'  => __( 'Add recommended tag', 'lihi-short-url' ),
            'removeTag'          => __( 'Remove tag', 'lihi-short-url' ),
            'manageOptions'      => __( 'Manage options?', 'lihi-short-url' ),
            'utmSource'          => __( 'UTM source', 'lihi-short-url' ),
            'utmMedium'          => __( 'UTM medium', 'lihi-short-url' ),
            'utmCampaign'        => __( 'UTM campaign', 'lihi-short-url' ),
            'utmTerm'            => __( 'UTM term', 'lihi-short-url' ),
            'utmContent'         => __( 'UTM content', 'lihi-short-url' ),
            'cancel'            => __( 'Cancel', 'lihi-short-url' ),
            'submit'            => __( 'Create & Copy', 'lihi-short-url' ),
            'loading'           => __( 'Loading...', 'lihi-short-url' ),
            'selectPlaceholder' => __( 'Please select', 'lihi-short-url' ),
        ],
        'notice'                => [
            'title'   => __( 'lihi Short URL', 'lihi-short-url' ),
            'confirm' => __( 'OK', 'lihi-short-url' ),
            'cancel'  => __( 'Cancel', 'lihi-short-url' ),
        ],
        'edit'                  => [
            'confirmMessage'    => __( 'Go to the lihi dashboard to edit this short URL?', 'lihi-short-url' ),
            'proofUnavailable' => __( 'Your browser does not support secure lihi edit verification.', 'lihi-short-url' ),
            'invalidProof'      => __( 'Could not verify browser session. Please refresh the page and try again.', 'lihi-short-url' ),
        ],
        'domainDashboard'       => [
            'confirmMessage'   => __( 'Go to the lihi dashboard to manage custom domains?', 'lihi-short-url' ),
            'proofUnavailable' => __( 'Your browser does not support secure lihi domain login.', 'lihi-short-url' ),
            'invalidProof'     => __( 'Could not verify browser session. Please refresh the page and try again.', 'lihi-short-url' ),
            'target'           => '/myDomain',
            'externalUrl'      => 'https://lihidomain.com',
        ],
        'utmDashboard'          => [
            'confirmMessage'   => __( 'Go to the lihi dashboard to manage UTM options?', 'lihi-short-url' ),
            'proofUnavailable' => __( 'Your browser does not support secure lihi dashboard login.', 'lihi-short-url' ),
            'invalidProof'     => __( 'Could not verify browser session. Please refresh the page and try again.', 'lihi-short-url' ),
            'target'           => '/profile#utm-setting',
        ],
    ] );
} );

// Register column header and a frontend button container for every public post type.
// Media Library list mode (upload.php?mode=list) uses a different filter
// pair (manage_media_columns / manage_media_custom_column), handled separately.
add_action( 'admin_init', function () {
    $render_container = function ( $post_id, $post_type ) {
        echo wp_kses(
            render_lihi_button_container( (int) $post_id, (string) $post_type ),
            [
                'div' => [
                    'class'              => true,
                    'data-lihi-container' => true,
                    'data-id'            => true,
                    'data-type'          => true,
                    'data-lihi-already'  => true,
                ],
            ]
        );
    };

    foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
        if ( $post_type === 'attachment' ) {
            continue;
        }

        add_filter( "manage_{$post_type}_posts_columns", function ( $columns ) {
            $columns['lihi'] = __( 'lihi Short URL', 'lihi-short-url' );
            return $columns;
        } );

        add_action( "manage_{$post_type}_posts_custom_column", function ( $column, $post_id ) use ( $post_type, $render_container ) {
            if ( $column === 'lihi' ) {
                $render_container( $post_id, $post_type );
            }
        }, 10, 2 );
    }

    // Media Library list mode.
    add_filter( 'manage_media_columns', function ( $columns ) {
        $columns['lihi'] = __( 'lihi Short URL', 'lihi-short-url' );
        return $columns;
    } );

    add_action( 'manage_media_custom_column', function ( $column, $post_id ) use ( $render_container ) {
        if ( $column === 'lihi' ) {
            $render_container( $post_id, 'attachment' );
        }
    }, 10, 2 );
} );

// Add a frontend button container to the attachment detail panel in the media grid view.
add_filter( 'attachment_fields_to_edit', function ( $form_fields, $post ) {
    $form_fields['lihi'] = [
        'label' => __( 'lihi Short URL', 'lihi-short-url' ),
        'input' => 'html',
        'html'  => render_lihi_button_container( (int) $post->ID, (string) $post->post_type ),
    ];
    return $form_fields;
}, 10, 2 );

} // end lihi_is_authenticated() guard
