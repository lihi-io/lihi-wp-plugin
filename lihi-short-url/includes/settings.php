<?php
namespace Lihi\ShortUrl;

/**
 * Plugin settings page and account authentication AJAX handlers.
 *
 * Login and registration are intentionally separate. Registration only sends
 * a verification email; a verified account must still log in before the
 * plugin stores a server-issued UUID plus access / refresh token pair.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', function () {
    $hook = add_options_page(
        __( 'lihi Short URL Settings', 'lihi-short-url' ),
        __( 'lihi Short URL', 'lihi-short-url' ),
        'manage_options',
        'lihi-settings',
        __NAMESPACE__ . '\\render_settings_page'
    );

    add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) use ( $hook ): void {
        if ( $hook_suffix !== $hook ) {
            return;
        }

        enqueue_settings_assets();
    } );
} );

function enqueue_settings_assets(): void {
    $settings_css = plugin_dir_path( __FILE__ ) . '../assets/lihi-settings.css';
    $settings_js  = plugin_dir_path( __FILE__ ) . '../assets/lihi-settings.js';
    $css_version  = file_exists( $settings_css ) ? '1.0.5-' . filemtime( $settings_css ) : '1.0.5';
    $js_version   = file_exists( $settings_js ) ? '1.0.5-' . filemtime( $settings_js ) : '1.0.5';

    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style(
        'lihi-settings-style',
        plugin_dir_url( __FILE__ ) . '../assets/lihi-settings.css',
        [],
        $css_version
    );

    wp_enqueue_script(
        'lihi-settings',
        plugin_dir_url( __FILE__ ) . '../assets/lihi-settings.js',
        [],
        $js_version,
        true
    );

    wp_localize_script( 'lihi-settings', 'lihiSettings', [
        'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
        'requestFailed'          => __( 'Request failed. Please try again later.', 'lihi-short-url' ),
        'showPassword'           => __( 'Show password', 'lihi-short-url' ),
        'hidePassword'           => __( 'Hide password', 'lihi-short-url' ),
        'forgotPassword'         => __( 'Forgot password?', 'lihi-short-url' ),
        'passwordResetUrl'       => lihi_password_reset_url(),
        'login'                  => [
            'action'           => 'lihi_login',
            'nonce'            => wp_create_nonce( 'lihi_login' ),
            'emailRequired'    => __( 'Please enter a valid email address.', 'lihi-short-url' ),
            'passwordRequired' => __( 'Please enter the lihi account password.', 'lihi-short-url' ),
        ],
        'register'               => [
            'action'           => 'lihi_register',
            'nonce'            => wp_create_nonce( 'lihi_register' ),
            'emailRequired'    => __( 'Please enter a valid email address.', 'lihi-short-url' ),
            'passwordRequired' => __( 'Please enter a password with at least 6 characters.', 'lihi-short-url' ),
            'consentRequired'  => __( 'Please confirm that lihi may use this email and password to create an account.', 'lihi-short-url' ),
        ],
        'logout'                 => [
            'action' => 'lihi_logout',
            'nonce'  => wp_create_nonce( 'lihi_logout' ),
        ],
        'workGroup'               => [
            'optionsAction'   => 'lihi_group_options',
            'optionsNonce'    => wp_create_nonce( 'lihi_group_options' ),
            'switchAction'    => 'lihi_switch_group',
            'switchNonce'     => wp_create_nonce( 'lihi_switch_group' ),
            'loading'         => __( 'Loading work groups…', 'lihi-short-url' ),
            'loadFailed'      => __( 'Could not load work groups.', 'lihi-short-url' ),
            'noOptions'       => __( 'No work groups are available.', 'lihi-short-url' ),
            'personalLabel'   => __( 'My Work Group', 'lihi-short-url' ),
            'unnamedLabel'    => __( 'Unnamed Work Group', 'lihi-short-url' ),
            'switched'        => __( 'Work group switched.', 'lihi-short-url' ),
        ],
        'dashboardAction'        => 'lihi_dashboard_passthrough',
        'dashboardNonce'         => wp_create_nonce( 'lihi_dashboard_passthrough' ),
        'homeUrl'                => lihi_home_url(),
        'passthroughRedirectUrl' => lihi_passthrough_redirect_url(),
        'dashboard'              => [
            'proofUnavailable' => __( 'Your browser does not support secure lihi dashboard login.', 'lihi-short-url' ),
        ],
    ] );
}

function settings_auth_tab(): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection does not change state.
    $tab = isset( $_GET['lihi_auth_tab'] ) ? sanitize_key( wp_unslash( $_GET['lihi_auth_tab'] ) ) : '';

    return $tab === 'register' ? 'register' : 'login';
}

function settings_auth_tab_url( string $tab ): string {
    return add_query_arg(
        [
            'page'          => 'lihi-settings',
            'lihi_auth_tab' => $tab === 'register' ? 'register' : 'login',
        ],
        admin_url( 'options-general.php' )
    );
}

function render_settings_page(): void {
    $connected     = lihi_is_authenticated();
    $email         = $connected ? lihi_email() : '';
    $profile       = null;
    $profile_error = '';
    $auth_tab      = settings_auth_tab();

    if ( $connected ) {
        try {
            $profile = Lihi_Singletons::lihi_service()->get_profile();
        } catch ( Lihi_User_Invalid_Exception $e ) {
            $connected     = lihi_is_authenticated();
            $profile_error = __( 'Your lihi account is unavailable. Please contact lihi support before creating short URLs.', 'lihi-short-url' );
        } catch ( Lihi_Token_Invalid_Exception $e ) {
            $connected     = lihi_is_authenticated();
            $profile_error = __( 'Your lihi login session has expired. Please sign in again.', 'lihi-short-url' );
        } catch ( Lihi_Auth_Exception $e ) {
            $profile_error = __( 'lihi rejected this account session. Please log out and sign in again.', 'lihi-short-url' );
        } catch ( Lihi_Server_Exception $e ) {
            log_settings_exception( 'profile fetch failed', $e );
            $profile_error = __( 'The lihi service is temporarily unavailable. Please try again later.', 'lihi-short-url' );
        } catch ( \Throwable $e ) {
            log_settings_exception( 'profile fetch failed', $e );
            $profile_error = __( 'Could not load account information.', 'lihi-short-url' );
        }
    }

    ?>
    <div class="wrap lihi-settings-page">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <section class="lihi-services-panel" aria-labelledby="lihi-services-heading">
            <div class="lihi-services-panel__intro">
                <div class="lihi-services-panel__lead">
                    <h2 id="lihi-services-heading">
                        <span class="lihi-services-panel__heading-line"><?php esc_html_e( 'Create short URLs in WordPress.', 'lihi-short-url' ); ?></span>
                        <span class="lihi-services-panel__heading-line"><?php esc_html_e( 'Manage the rest in lihi.', 'lihi-short-url' ); ?></span>
                    </h2>
                    <p class="lihi-services-panel__copy">
                        <span class="lihi-services-panel__copy-line"><?php esc_html_e( 'This plugin provides a simple workflow for creating and using short URLs.', 'lihi-short-url' ); ?></span>
                        <span class="lihi-services-panel__copy-line"><?php esc_html_e( 'For short URL list management, link editing, personal domains, SMS, and growth tools, go to the lihi dashboard.', 'lihi-short-url' ); ?></span>
                    </p>
                    <div class="lihi-services-route" aria-hidden="true">
                        <span><?php esc_html_e( 'WordPress', 'lihi-short-url' ); ?></span>
                        <span class="lihi-services-route__line"></span>
                        <span><?php esc_html_e( 'lihi dashboard', 'lihi-short-url' ); ?></span>
                    </div>
                </div>

                <div class="lihi-account-panel">
                    <div class="lihi-account-panel__header">
                        <div class="lihi-account-panel__header-main">
                            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                            <div>
                                <h3>
                                    <?php
                                    echo esc_html(
                                        $connected
                                            ? __( 'Connected lihi account', 'lihi-short-url' )
                                            : __( 'Connect a lihi account', 'lihi-short-url' )
                                    );
                                    ?>
                                </h3>
                                <p>
                                    <?php
                                    echo esc_html(
                                        $connected
                                            ? __( 'This WordPress site is connected to this lihi account.', 'lihi-short-url' )
                                            : __( 'Sign in to an existing account or register a new one.', 'lihi-short-url' )
                                    );
                                    ?>
                                </p>
                            </div>
                        </div>
                        <?php if ( $connected ) : ?>
                            <button type="button" id="lihi-logout" class="button lihi-account-panel__logout">
                                <?php esc_html_e( 'Log out', 'lihi-short-url' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ( $connected ) : ?>
                        <div id="lihi-account-section" class="lihi-account-panel__profile">
                            <dl>
                                <div>
                                    <dt><?php esc_html_e( 'Email', 'lihi-short-url' ); ?></dt>
                                    <dd><?php echo esc_html( $email ); ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e( 'Subscribe Plan', 'lihi-short-url' ); ?></dt>
                                    <dd><?php echo esc_html( is_array( $profile ) ? ( $profile['user_role'] ?? __( '(none)', 'lihi-short-url' ) ) : __( 'Unavailable', 'lihi-short-url' ) ); ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e( 'Work Group', 'lihi-short-url' ); ?></dt>
                                    <dd class="lihi-account-panel__work-group">
                                        <span id="lihi-current-work-group"><?php echo esc_html( is_array( $profile ) ? ( $profile['group_name'] ?? __( 'My Work Group', 'lihi-short-url' ) ) : __( 'Unavailable', 'lihi-short-url' ) ); ?></span>
                                        <button type="button" id="lihi-switch-work-group" class="button button-secondary button-small"<?php if ( ! is_array( $profile ) ) : ?> disabled<?php endif; ?>>
                                            <?php esc_html_e( 'Switch', 'lihi-short-url' ); ?>
                                        </button>
                                    </dd>
                                </div>
                            </dl>
                            <div id="lihi-account-status" role="status" aria-live="polite">
                                <?php if ( $profile_error !== '' ) : ?>
                                    <div class="notice notice-error inline lihi-account-panel__form-notice"><p><?php echo esc_html( $profile_error ); ?></p></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div id="lihi-work-group-modal" class="lihi-work-group-modal" hidden>
                            <div class="lihi-work-group-modal__backdrop" data-lihi-work-group-close aria-hidden="true"></div>
                            <div class="lihi-work-group-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lihi-work-group-modal-title" aria-describedby="lihi-work-group-modal-description" tabindex="-1">
                                <div class="lihi-work-group-modal__header">
                                    <div>
                                        <h2 id="lihi-work-group-modal-title"><?php esc_html_e( 'Switch work group', 'lihi-short-url' ); ?></h2>
                                        <p id="lihi-work-group-modal-description"><?php esc_html_e( 'Choose which lihi work group this WordPress connection should use.', 'lihi-short-url' ); ?></p>
                                    </div>
                                    <button type="button" class="lihi-work-group-modal__close" data-lihi-work-group-close aria-label="<?php esc_attr_e( 'Close work group dialog', 'lihi-short-url' ); ?>">
                                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                    </button>
                                </div>
                                <form id="lihi-work-group-form">
                                    <label for="lihi-work-group-select"><?php esc_html_e( 'Work Group', 'lihi-short-url' ); ?></label>
                                    <select id="lihi-work-group-select" name="group_id" disabled></select>
                                    <div id="lihi-work-group-status" role="status" aria-live="polite"></div>
                                    <div class="lihi-work-group-modal__actions">
                                        <button type="button" class="button" data-lihi-work-group-close><?php esc_html_e( 'Cancel', 'lihi-short-url' ); ?></button>
                                        <button type="submit" id="lihi-work-group-submit" class="button button-primary" disabled><?php esc_html_e( 'Switch work group', 'lihi-short-url' ); ?></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else : ?>
                        <?php if ( $profile_error !== '' ) : ?>
                            <div class="notice notice-warning inline lihi-account-panel__session-notice"><p><?php echo esc_html( $profile_error ); ?></p></div>
                        <?php endif; ?>

                        <div class="lihi-account-panel__auth-forms">
                            <nav class="lihi-account-tabs" role="tablist" aria-label="<?php esc_attr_e( 'lihi account access', 'lihi-short-url' ); ?>">
                                <a id="lihi-login-tab" class="<?php echo esc_attr( 'lihi-account-tabs__tab' . ( $auth_tab === 'login' ? ' is-active' : '' ) ); ?>" href="<?php echo esc_url( settings_auth_tab_url( 'login' ) ); ?>" role="tab" aria-selected="<?php echo esc_attr( $auth_tab === 'login' ? 'true' : 'false' ); ?>" aria-controls="lihi-login-panel" tabindex="<?php echo esc_attr( $auth_tab === 'login' ? '0' : '-1' ); ?>" data-lihi-auth-tab="login"><?php esc_html_e( 'Log in', 'lihi-short-url' ); ?></a>
                                <a id="lihi-register-tab" class="<?php echo esc_attr( 'lihi-account-tabs__tab' . ( $auth_tab === 'register' ? ' is-active' : '' ) ); ?>" href="<?php echo esc_url( settings_auth_tab_url( 'register' ) ); ?>" role="tab" aria-selected="<?php echo esc_attr( $auth_tab === 'register' ? 'true' : 'false' ); ?>" aria-controls="lihi-register-panel" tabindex="<?php echo esc_attr( $auth_tab === 'register' ? '0' : '-1' ); ?>" data-lihi-auth-tab="register"><?php esc_html_e( 'Register', 'lihi-short-url' ); ?></a>
                            </nav>
                            <div id="lihi-login-panel" class="lihi-account-panel__auth-panel" role="tabpanel" aria-labelledby="lihi-login-tab"<?php if ( $auth_tab !== 'login' ) : ?> hidden<?php endif; ?>>
                                <form id="lihi-login-form" class="lihi-account-panel__auth-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" novalidate>
                                <input type="hidden" name="action" value="lihi_login" />
                                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'lihi_login' ) ); ?>" />
                                <div>
                                    <h4><?php esc_html_e( 'Log in', 'lihi-short-url' ); ?></h4>
                                    <p class="lihi-account-panel__description"><?php esc_html_e( 'Use your existing lihi account to connect this site.', 'lihi-short-url' ); ?></p>
                                </div>
                                <div class="lihi-account-panel__field">
                                    <label for="lihi_login_email"><?php esc_html_e( 'Email', 'lihi-short-url' ); ?></label>
                                    <input type="email" id="lihi_login_email" name="email" class="regular-text" autocomplete="username" required />
                                </div>
                                <div class="lihi-account-panel__field">
                                    <label for="lihi_login_password"><?php esc_html_e( 'Password', 'lihi-short-url' ); ?></label>
                                    <div class="lihi-account-panel__password-control">
                                        <input type="password" id="lihi_login_password" name="password" value="" class="regular-text" autocomplete="current-password" required />
                                        <button type="button" class="lihi-account-panel__password-toggle" data-lihi-password-toggle aria-controls="lihi_login_password" aria-pressed="false" aria-label="<?php esc_attr_e( 'Show password', 'lihi-short-url' ); ?>" title="<?php esc_attr_e( 'Show password', 'lihi-short-url' ); ?>">
                                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="lihi-account-panel__form-actions">
                                    <a href="<?php echo esc_url( lihi_password_reset_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Forgot password?', 'lihi-short-url' ); ?></a>
                                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Log in', 'lihi-short-url' ); ?></button>
                                </div>
                                <div id="lihi-login-status" class="lihi-account-panel__messages" role="status" aria-live="polite"></div>
                                </form>
                            </div>

                            <div id="lihi-register-panel" class="lihi-account-panel__auth-panel" role="tabpanel" aria-labelledby="lihi-register-tab"<?php if ( $auth_tab !== 'register' ) : ?> hidden<?php endif; ?>>
                                <form id="lihi-register-form" class="lihi-account-panel__auth-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" novalidate>
                                <input type="hidden" name="action" value="lihi_register" />
                                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'lihi_register' ) ); ?>" />
                                <div>
                                    <h4><?php esc_html_e( 'Register', 'lihi-short-url' ); ?></h4>
                                    <p class="lihi-account-panel__description"><?php esc_html_e( 'Create a lihi account, then verify it from your email before logging in.', 'lihi-short-url' ); ?></p>
                                </div>
                                <div class="lihi-account-panel__field">
                                    <label for="lihi_register_email"><?php esc_html_e( 'Email', 'lihi-short-url' ); ?></label>
                                    <input type="email" id="lihi_register_email" name="email" class="regular-text" autocomplete="email" required />
                                </div>
                                <div class="lihi-account-panel__field">
                                    <label for="lihi_register_password"><?php esc_html_e( 'Password', 'lihi-short-url' ); ?></label>
                                    <div class="lihi-account-panel__password-control">
                                        <input type="password" id="lihi_register_password" name="password" value="" class="regular-text" autocomplete="new-password" minlength="6" required />
                                        <button type="button" class="lihi-account-panel__password-toggle" data-lihi-password-toggle aria-controls="lihi_register_password" aria-pressed="false" aria-label="<?php esc_attr_e( 'Show password', 'lihi-short-url' ); ?>" title="<?php esc_attr_e( 'Show password', 'lihi-short-url' ); ?>">
                                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="lihi-account-panel__checkbox">
                                    <input type="checkbox" id="lihi_register_consent" name="create_account_consent" value="1" required aria-required="true" />
                                    <label class="lihi-account-panel__checkbox-label" for="lihi_register_consent"><?php esc_html_e( 'I agree that lihi may use this email and password to create an account.', 'lihi-short-url' ); ?></label>
                                </div>
                                <div class="lihi-account-panel__form-actions lihi-account-panel__form-actions--end">
                                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Register', 'lihi-short-url' ); ?></button>
                                </div>
                                <div id="lihi-register-status" class="lihi-account-panel__messages" role="status" aria-live="polite"></div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lihi-services-panel__workspace">
                <div class="lihi-services-panel__workspace-header">
                    <p class="lihi-services-panel__workspace-heading"><?php esc_html_e( 'lihi dashboard', 'lihi-short-url' ); ?></p>
                    <div class="lihi-services-panel__actions">
                        <button type="button" id="lihi-open-dashboard" class="button button-primary lihi-dashboard-button">
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                            <?php esc_html_e( 'Go to lihi dashboard', 'lihi-short-url' ); ?>
                        </button>
                        <div id="lihi-dashboard-status" class="lihi-services-panel__status" role="status" aria-live="polite"></div>
                    </div>
                </div>
                <div class="lihi-services-grid" aria-label="<?php esc_attr_e( 'lihi dashboard services', 'lihi-short-url' ); ?>">
                    <article class="lihi-service-card lihi-service-card--links">
                        <span class="dashicons dashicons-admin-links lihi-service-card__icon" aria-hidden="true"></span>
                        <h3><?php esc_html_e( 'Short URLs', 'lihi-short-url' ); ?></h3>
                        <p><?php esc_html_e( 'Manage destinations, traffic splitting, A/B tests, QR codes, tags, and click analytics for campaign links.', 'lihi-short-url' ); ?></p>
                    </article>
                    <article class="lihi-service-card lihi-service-card--domains">
                        <span class="dashicons dashicons-admin-site-alt3 lihi-service-card__icon" aria-hidden="true"></span>
                        <h3><?php esc_html_e( 'Domains', 'lihi-short-url' ); ?></h3>
                        <p><?php esc_html_e( 'Manage personal domains, DNS settings, and short-link domains connected to traffic splitting and performance tracking.', 'lihi-short-url' ); ?></p>
                    </article>
                    <article class="lihi-service-card lihi-service-card--sms">
                        <span class="dashicons dashicons-email-alt2 lihi-service-card__icon" aria-hidden="true"></span>
                        <h3><?php esc_html_e( 'SMS', 'lihi-short-url' ); ?></h3>
                        <p><?php esc_html_e( 'Send marketing SMS with NCC whitelist support, short-link tracking, delivery workflows, and SMS API access.', 'lihi-short-url' ); ?></p>
                    </article>
                    <article class="lihi-service-card lihi-service-card--tools">
                        <span class="dashicons dashicons-chart-line lihi-service-card__icon" aria-hidden="true"></span>
                        <h3><?php esc_html_e( 'Growth tools', 'lihi-short-url' ); ?></h3>
                        <p><?php esc_html_e( 'Use UTM builders, QR codes, bulk imports, and API keys for larger workflows.', 'lihi-short-url' ); ?></p>
                    </article>
                </div>
            </div>

            <div class="lihi-services-panel__legal">
                <a href="<?php echo esc_url( 'https://knowledge.lihi.io/terms/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms of Use', 'lihi-short-url' ); ?></a>
                <span aria-hidden="true">/</span>
                <a href="<?php echo esc_url( 'https://knowledge.lihi.io/privacy-policy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'lihi-short-url' ); ?></a>
            </div>
        </section>
    </div>
    <?php
}

function posted_checkbox_is_checked( string $field ): bool {
    $value = settings_posted_value( $field, null );
    if ( ! is_scalar( $value ) ) {
        return false;
    }

    return sanitize_text_field( (string) $value ) === '1';
}

function settings_posted_value( string $field, $default = '' ) {
    $field = sanitize_key( $field );
    if ( $field === '' ) {
        return $default;
    }

    // Settings AJAX handlers verify nonces before reading request fields.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST[ $field ] ) ) {
        return $default;
    }

    // Raw helper; callers sanitize text, while passwords stay unchanged.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    return wp_unslash( $_POST[ $field ] );
}

function settings_posted_text_field( string $field, string $default = '' ): string {
    $value = settings_posted_value( $field, $default );

    if ( ! is_scalar( $value ) ) {
        return $default;
    }

    return trim( sanitize_text_field( (string) $value ) );
}

function settings_posted_password( string $field = 'password' ): string {
    $value = settings_posted_value( $field, '' );

    return is_scalar( $value ) ? (string) $value : '';
}

function settings_password_character_count( string $password ): int {
    $characters = preg_replace( '/./us', 'x', $password );

    return is_string( $characters ) ? strlen( $characters ) : 0;
}

function settings_posted_email( string $field = 'email' ): string {
    $raw   = settings_posted_text_field( $field );
    $email = sanitize_email( $raw );

    return $email !== '' && is_email( $email ) ? $email : '';
}

function log_settings_exception(
    string $context,
    \Throwable $e,
    bool $include_message = true
): void {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $detail = $include_message
            ? ': ' . $e->getMessage()
            : ' (' . get_class( $e ) . ')';
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics gated behind WP_DEBUG.
        error_log( '[lihi] ' . $context . $detail );
    }
}

function send_login_exception( \Throwable $e ): void {
    if ( $e instanceof Lihi_Authentication_Busy_Exception ) {
        wp_send_json_error( __( 'Another lihi authentication request is in progress. Please wait and try again.', 'lihi-short-url' ), 409 );
        return;
    }

    if ( $e instanceof Lihi_Account_Not_Found_Exception ) {
        wp_send_json_error( [
            'code'    => 'account_not_found',
            'message' => __( 'This lihi account does not exist. Register it first, then verify your email.', 'lihi-short-url' ),
        ], 403 );
        return;
    }

    if ( $e instanceof Lihi_Email_Or_Password_Invalid_Exception ) {
        wp_send_json_error( [
            'code'               => 'email_or_password_invalid',
            'message'            => __( 'Email or password invalid. Please check and try again.', 'lihi-short-url' ),
            'password_reset_url' => esc_url_raw( lihi_password_reset_url() ),
        ], 403 );
        return;
    }

    if ( $e instanceof Lihi_User_Invalid_Exception ) {
        wp_send_json_error( __( 'Your lihi account is unavailable. Please contact lihi support before creating short URLs.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_Authorization_Code_Invalid_Exception ) {
        wp_send_json_error( __( 'Could not complete the secure login exchange. Please try again.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_Validation_Exception ) {
        wp_send_json_error( __( 'lihi rejected the login details. Please check them and try again.', 'lihi-short-url' ), 400 );
        return;
    }

    if ( $e instanceof Lihi_Rate_Limit_Exception ) {
        wp_send_json_error( __( 'Too many login attempts. Please wait a moment and try again.', 'lihi-short-url' ), 429 );
        return;
    }

    if ( $e instanceof Lihi_Auth_Exception ) {
        wp_send_json_error( __( 'lihi rejected this login. Please check the account and try again.', 'lihi-short-url' ), 403 );
        return;
    }

    // Credential-handling paths never log exception text because unexpected
    // errors may include argument values.
    log_settings_exception( 'login failed', $e, false );
    wp_send_json_error( __( 'The lihi service is unavailable. Please try again later.', 'lihi-short-url' ), 503 );
}

function ajax_lihi_login(): void {
    check_ajax_referer( 'lihi_login', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to connect this account.', 'lihi-short-url' ), 403 );
        return;
    }

    $email = settings_posted_email();
    if ( $email === '' ) {
        wp_send_json_error( __( 'Please enter a valid email address.', 'lihi-short-url' ), 400 );
        return;
    }

    $password = settings_posted_password();
    if ( trim( $password ) === '' ) {
        wp_send_json_error( __( 'Please enter the lihi account password.', 'lihi-short-url' ), 400 );
        return;
    }

    try {
        Lihi_Singletons::lihi_service()->login( $email, $password );
    } catch ( \Throwable $e ) {
        send_login_exception( $e );
        return;
    }

    wp_send_json_success( [
        'authenticated' => true,
        'message'       => __( '✓ Logged in to lihi.', 'lihi-short-url' ),
    ] );
}

add_action( 'wp_ajax_lihi_login', __NAMESPACE__ . '\\ajax_lihi_login' );

function send_register_exception( \Throwable $e ): void {
    if ( $e instanceof Lihi_Account_Already_Exists_Exception ) {
        wp_send_json_error( [
            'code'    => 'account_already_exists',
            'message' => __( 'This lihi account already exists. Use the login form instead.', 'lihi-short-url' ),
        ], 409 );
        return;
    }

    if ( $e instanceof Lihi_Registration_Country_Unavailable_Exception ) {
        wp_send_json_error( __( 'lihi registration is not available in your country or region.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_User_Invalid_Exception ) {
        wp_send_json_error( __( 'Your lihi account is unavailable. Please contact lihi support.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_Validation_Exception ) {
        wp_send_json_error( __( 'lihi rejected the registration details. Check the email and password, then try again.', 'lihi-short-url' ), 400 );
        return;
    }

    if ( $e instanceof Lihi_Rate_Limit_Exception ) {
        wp_send_json_error( __( 'Too many registration attempts. Please wait a moment and try again.', 'lihi-short-url' ), 429 );
        return;
    }

    if ( $e instanceof Lihi_Auth_Exception ) {
        wp_send_json_error( __( 'lihi rejected this registration. Please check the account and try again.', 'lihi-short-url' ), 403 );
        return;
    }

    // Credential-handling paths never log exception text because unexpected
    // errors may include argument values.
    log_settings_exception( 'registration failed', $e, false );
    wp_send_json_error( __( 'The lihi service is unavailable. Please try again later.', 'lihi-short-url' ), 503 );
}

function ajax_lihi_register(): void {
    check_ajax_referer( 'lihi_register', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to register this account.', 'lihi-short-url' ), 403 );
        return;
    }

    $email = settings_posted_email();
    if ( $email === '' ) {
        wp_send_json_error( __( 'Please enter a valid email address.', 'lihi-short-url' ), 400 );
        return;
    }

    $password = settings_posted_password();
    if ( settings_password_character_count( $password ) < 6 ) {
        wp_send_json_error( __( 'Please enter a password with at least 6 characters.', 'lihi-short-url' ), 400 );
        return;
    }

    if ( ! posted_checkbox_is_checked( 'create_account_consent' ) ) {
        wp_send_json_error( __( 'Please confirm that lihi may use this email and password to create an account.', 'lihi-short-url' ), 400 );
        return;
    }

    try {
        Lihi_Singletons::lihi_service()->register( $email, $password );
    } catch ( \Throwable $e ) {
        send_register_exception( $e );
        return;
    }

    wp_send_json_success( [
        'verification_sent' => true,
        'email'             => $email,
        'message'           => __( 'Verification email sent. Open it to verify the account, then return here and log in.', 'lihi-short-url' ),
    ] );
}

add_action( 'wp_ajax_lihi_register', __NAMESPACE__ . '\\ajax_lihi_register' );

function ajax_lihi_logout(): void {
    check_ajax_referer( 'lihi_logout', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to disconnect this account.', 'lihi-short-url' ), 403 );
        return;
    }

    try {
        Lihi_Singletons::lihi_service()->logout();
    } catch ( \Throwable $e ) {
        log_settings_exception( 'logout failed', $e );
        wp_send_json_error( __( 'Could not clear the local lihi session. Please try again.', 'lihi-short-url' ), 500 );
        return;
    }

    wp_send_json_success( [
        'message' => __( 'Logged out. Local lihi credentials were removed from WordPress.', 'lihi-short-url' ),
    ] );
}

add_action( 'wp_ajax_lihi_logout', __NAMESPACE__ . '\\ajax_lihi_logout' );

/**
 * Normalize one optional work-group ID.
 *
 * @param mixed $value
 */
function normalize_work_group_id( $value, bool &$valid ): ?int {
    if ( null === $value || '' === $value ) {
        $valid = true;
        return null;
    }

    if (
        ! is_int( $value )
        && ! ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/', $value ) )
    ) {
        $valid = false;
        return null;
    }

    $normalized = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [ 'options' => [ 'min_range' => 1 ] ]
    );
    $valid      = false !== $normalized;

    return $valid ? (int) $normalized : null;
}

/**
 * Validate the group-options response before returning it to the browser.
 *
 * @return array{
 *   groups: list<array{id: ?int, name: ?string}>,
 *   group_id: ?int,
 * }
 */
function normalize_work_group_options( array $options ): array {
    if (
        ! isset( $options['groups'] )
        || ! is_array( $options['groups'] )
        || ! array_key_exists( 'group_id', $options )
    ) {
        throw new Lihi_Server_Exception(
            esc_html__( 'Invalid work-group options returned from lihi API.', 'lihi-short-url' )
        );
    }

    $groups = [];
    foreach ( $options['groups'] as $group ) {
        if (
            ! is_array( $group )
            || ! array_key_exists( 'id', $group )
            || ! array_key_exists( 'name', $group )
        ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid work-group option returned from lihi API.', 'lihi-short-url' )
            );
        }

        $valid_id = false;
        $group_id = normalize_work_group_id( $group['id'], $valid_id );
        if ( ! $valid_id ) {
            throw new Lihi_Server_Exception(
                esc_html__( 'Invalid work-group ID returned from lihi API.', 'lihi-short-url' )
            );
        }

        $group_name = null;
        if ( null !== $group['name'] ) {
            if ( ! is_scalar( $group['name'] ) ) {
                throw new Lihi_Server_Exception(
                    esc_html__( 'Invalid work-group name returned from lihi API.', 'lihi-short-url' )
                );
            }
            $group_name = sanitize_text_field( (string) $group['name'] );
        }

        $groups[] = [
            'id'   => $group_id,
            'name' => $group_name,
        ];
    }

    $valid_current_id = false;
    $current_group_id = normalize_work_group_id(
        $options['group_id'],
        $valid_current_id
    );
    if ( ! $valid_current_id || [] === $groups ) {
        throw new Lihi_Server_Exception(
            esc_html__( 'Invalid current work group returned from lihi API.', 'lihi-short-url' )
        );
    }

    $current_group_exists = false;
    foreach ( $groups as $group ) {
        if ( $group['id'] === $current_group_id ) {
            $current_group_exists = true;
            break;
        }
    }
    if ( ! $current_group_exists ) {
        throw new Lihi_Server_Exception(
            esc_html__( 'Current work group is missing from lihi API options.', 'lihi-short-url' )
        );
    }

    return [
        'groups'   => $groups,
        'group_id' => $current_group_id,
    ];
}

function send_work_group_exception( \Throwable $e, string $context ): void {
    if ( $e instanceof Lihi_Authentication_Busy_Exception ) {
        wp_send_json_error( __( 'Another lihi authentication request is in progress. Please wait and try again.', 'lihi-short-url' ), 409 );
        return;
    }
    if ( $e instanceof Lihi_Token_Invalid_Exception ) {
        wp_send_json_error( __( 'Your lihi login session has expired. Please sign in again.', 'lihi-short-url' ), 401 );
        return;
    }
    if ( $e instanceof Lihi_User_Invalid_Exception || $e instanceof Lihi_Auth_Exception ) {
        wp_send_json_error( __( 'lihi rejected this account session. Please sign in again.', 'lihi-short-url' ), 403 );
        return;
    }
    if ( $e instanceof Lihi_Validation_Exception ) {
        wp_send_json_error( __( 'That work group is not available to this lihi account.', 'lihi-short-url' ), 400 );
        return;
    }
    if ( $e instanceof Lihi_Rate_Limit_Exception ) {
        wp_send_json_error( __( 'Too many requests. Please wait a moment and try again.', 'lihi-short-url' ), 429 );
        return;
    }

    log_settings_exception( $context, $e );
    if ( $e instanceof Lihi_Server_Exception ) {
        wp_send_json_error( __( 'The lihi service is unavailable. Please try again later.', 'lihi-short-url' ), 503 );
        return;
    }

    wp_send_json_error( __( 'Could not update the work group. Please try again later.', 'lihi-short-url' ), 500 );
}

function ajax_lihi_group_options(): void {
    check_ajax_referer( 'lihi_group_options', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to view lihi work groups.', 'lihi-short-url' ), 403 );
        return;
    }
    if ( ! lihi_is_authenticated() ) {
        wp_send_json_error( __( 'Please log in to lihi before choosing a work group.', 'lihi-short-url' ), 409 );
        return;
    }

    try {
        $options = normalize_work_group_options(
            Lihi_Singletons::lihi_service()->get_work_group_options()
        );
        wp_send_json_success( $options );
    } catch ( \Throwable $e ) {
        send_work_group_exception( $e, 'work-group options failed' );
    }
}

add_action( 'wp_ajax_lihi_group_options', __NAMESPACE__ . '\\ajax_lihi_group_options' );

function ajax_lihi_switch_group(): void {
    check_ajax_referer( 'lihi_switch_group', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to switch lihi work groups.', 'lihi-short-url' ), 403 );
        return;
    }
    if ( ! lihi_is_authenticated() ) {
        wp_send_json_error( __( 'Please log in to lihi before switching work groups.', 'lihi-short-url' ), 409 );
        return;
    }

    // The nonce above protects this request-field read.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST['group_id'] ) ) {
        wp_send_json_error( __( 'Please choose a valid work group.', 'lihi-short-url' ), 400 );
        return;
    }

    $raw_group_id = settings_posted_value( 'group_id', null );
    $valid_id     = false;
    $group_id     = normalize_work_group_id( $raw_group_id, $valid_id );
    if ( ! $valid_id ) {
        wp_send_json_error( __( 'Please choose a valid work group.', 'lihi-short-url' ), 400 );
        return;
    }

    try {
        $switched_group_id = Lihi_Singletons::lihi_service()
            ->switch_work_group( $group_id );
        wp_send_json_success( [
            'group_id' => $switched_group_id,
            'message'  => __( 'Work group switched.', 'lihi-short-url' ),
        ] );
    } catch ( \Throwable $e ) {
        send_work_group_exception( $e, 'work-group switch failed' );
    }
}

add_action( 'wp_ajax_lihi_switch_group', __NAMESPACE__ . '\\ajax_lihi_switch_group' );

function parse_dashboard_challenge_field(): string {
    $challenge = settings_posted_text_field( 'challenge' );

    return preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge )
        ? $challenge
        : '';
}

function send_dashboard_passthrough_exception( \Throwable $e ): void {
    if ( $e instanceof Lihi_Authentication_Busy_Exception ) {
        wp_send_json_error( __( 'Another lihi authentication request is in progress. Please wait and try again.', 'lihi-short-url' ), 409 );
        return;
    }

    if ( $e instanceof Lihi_User_Invalid_Exception ) {
        wp_send_json_error( __( 'Your lihi account is unavailable. Please contact lihi support before creating short URLs.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_Token_Invalid_Exception ) {
        wp_send_json_error( __( 'Your lihi login session has expired. Please sign in again.', 'lihi-short-url' ), 401 );
        return;
    }

    if ( $e instanceof Lihi_Auth_Exception ) {
        wp_send_json_error( __( 'lihi rejected this account session. Please sign in again.', 'lihi-short-url' ), 403 );
        return;
    }

    if ( $e instanceof Lihi_Validation_Exception ) {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON payload is rendered with textContent in lihi-settings.js.
        /* translators: %s: validation error message returned by the lihi API. */
        wp_send_json_error( sprintf( __( 'lihi API rejected the request: %s', 'lihi-short-url' ), $e->getMessage() ), 400 );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return;
    }

    if ( $e instanceof Lihi_Rate_Limit_Exception ) {
        wp_send_json_error( __( 'Too many requests. Please wait a moment and try again.', 'lihi-short-url' ), 429 );
        return;
    }

    log_settings_exception( 'dashboard passthrough failed', $e );

    if ( $e instanceof Lihi_Server_Exception ) {
        wp_send_json_error( __( 'The lihi service is unavailable. Please try again later.', 'lihi-short-url' ), 503 );
        return;
    }

    wp_send_json_error( __( 'Failed to open lihi dashboard. Please try again later.', 'lihi-short-url' ), 500 );
}

function ajax_dashboard_passthrough(): void {
    check_ajax_referer( 'lihi_dashboard_passthrough', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to open the lihi dashboard.', 'lihi-short-url' ), 403 );
        return;
    }

    try {
        if ( ! lihi_is_authenticated() ) {
            $home_url = lihi_home_url();
            if ( $home_url === '' ) {
                throw new \RuntimeException( 'Could not resolve lihi home URL.' );
            }

            wp_send_json_success( [
                'passthrough' => false,
                'home_url'    => $home_url,
            ] );
            return;
        }

        $challenge = parse_dashboard_challenge_field();
        if ( $challenge === '' ) {
            wp_send_json_error( __( 'Could not verify browser session. Please refresh the page and try again.', 'lihi-short-url' ), 400 );
            return;
        }

        $nonce        = Lihi_Singletons::lihi_service()->create_passthrough_nonce( '', $challenge );
        $redirect_url = lihi_passthrough_redirect_url();
        if ( $redirect_url === '' ) {
            throw new \RuntimeException( 'Could not resolve lihi passthrough redirect URL.' );
        }

        wp_send_json_success( [
            'passthrough'  => true,
            'nonce'        => $nonce,
            'redirect_url' => $redirect_url,
        ] );
    } catch ( \Throwable $e ) {
        send_dashboard_passthrough_exception( $e );
    }
}

add_action( 'wp_ajax_lihi_dashboard_passthrough', __NAMESPACE__ . '\\ajax_dashboard_passthrough' );
