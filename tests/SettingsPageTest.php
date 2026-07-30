<?php

namespace Lihi\ShortUrl\Tests;

use Lihi\ShortUrl\Lihi_Service;
use Mockery;

class SettingsPageTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option( 'lihi_auth_tokens' );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );

        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        set_current_screen( 'settings_page_lihi-settings' );
    }

    protected function tearDown(): void
    {
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set( null );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );
        update_option( 'lihi_auth_tokens', [
            'email'         => 'test@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        $GLOBALS['current_screen'] = null;
        Mockery::close();
        parent::tearDown();
    }

    private function renderPage(): string
    {
        ob_start();
        \Lihi\ShortUrl\render_settings_page();

        return (string) ob_get_clean();
    }

    public function test_disconnected_page_renders_separate_login_and_register_forms(): void
    {
        $html = $this->renderPage();

        $this->assertStringContainsString( '<form id="lihi-login-form"', $html );
        $this->assertStringContainsString( '<form id="lihi-register-form"', $html );
        $this->assertSame( 2, substr_count( $html, 'method="post"' ) );
        $this->assertSame( 2, substr_count( $html, 'admin-ajax.php' ) );
        $this->assertStringContainsString( 'name="action" value="lihi_login"', $html );
        $this->assertStringContainsString( 'name="action" value="lihi_register"', $html );
        $this->assertSame( 2, substr_count( $html, 'name="nonce"' ) );
        $this->assertStringContainsString( 'id="lihi_login_email"', $html );
        $this->assertStringContainsString( 'id="lihi_login_password"', $html );
        $this->assertStringContainsString( 'autocomplete="username"', $html );
        $this->assertStringContainsString( 'autocomplete="current-password"', $html );
        $this->assertStringContainsString( 'id="lihi_register_email"', $html );
        $this->assertStringContainsString( 'id="lihi_register_password"', $html );
        $this->assertStringContainsString( 'autocomplete="email"', $html );
        $this->assertStringContainsString( 'autocomplete="new-password"', $html );
        $this->assertStringContainsString( 'minlength="6"', $html );
        $this->assertSame( 1, substr_count( $html, 'id="lihi_register_consent"' ) );
        $this->assertStringContainsString( 'id="lihi_register_consent" name="create_account_consent" value="1" required aria-required="true"', $html );
        $this->assertStringContainsString( 'id="lihi-login-status"', $html );
        $this->assertStringContainsString( 'id="lihi-register-status"', $html );
    }

    public function test_disconnected_page_never_prefills_credentials(): void
    {
        $html = $this->renderPage();

        $this->assertSame( 2, substr_count( $html, 'type="email"' ) );
        $this->assertSame( 2, substr_count( $html, 'value="" class="regular-text"' ) );
        $this->assertStringContainsString( 'name="email" class="regular-text"', $html );
        $this->assertStringNotContainsString( 'name="email" value=', $html );
        $this->assertStringNotContainsString( 'secret-password', $html );
        $this->assertSame( 2, substr_count( $html, 'type="password"' ) );
    }

    public function test_connected_page_hides_auth_forms_and_renders_profile(): void
    {
        add_option( 'lihi_auth_tokens', [
            'email'         => 'alice@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], '', 'no' );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );

        $service = Mockery::mock( Lihi_Service::class );
        $service->shouldReceive( 'get_profile' )
            ->once()
            ->andReturn( [
                'user_role' => 'Starter',
                'end_date'  => '2026-12-31',
            ] );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set( $service );

        $html = $this->renderPage();

        $this->assertStringNotContainsString( 'id="lihi-login-form"', $html );
        $this->assertStringNotContainsString( 'id="lihi-register-form"', $html );
        $this->assertStringContainsString( 'id="lihi-logout"', $html );
        $this->assertStringContainsString( 'alice@example.com', $html );
        $this->assertStringContainsString( 'Starter', $html );
        $this->assertStringContainsString( '2026-12-31', $html );
    }
}
