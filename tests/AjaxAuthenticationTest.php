<?php

namespace Lihi\ShortUrl\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lihi\ShortUrl\Lihi_Account_Already_Exists_Exception;
use Lihi\ShortUrl\Lihi_Account_Not_Found_Exception;
use Lihi\ShortUrl\Lihi_Authentication_Busy_Exception;
use Lihi\ShortUrl\Lihi_Email_Or_Password_Invalid_Exception;
use Lihi\ShortUrl\Lihi_Service;
use Mockery;
use PHPUnit\Framework\TestCase;

class AjaxAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_POST = [];

        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'sanitize_key' )->alias( function ( $value ) {
            return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $value ) );
        } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
            return trim( strip_tags( (string) $value ) );
        } );
        Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
        Functions\when( 'sanitize_email' )->alias( function ( $email ) {
            return preg_replace( '/[\\s\\x00-\\x1F\\x7F]/', '', (string) $email );
        } );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
        } );
        Functions\when( 'esc_url_raw' )->alias( function ( $value ) {
            return $value;
        } );
        Functions\when( 'Lihi\\ShortUrl\\lihi_password_reset_url' )->justReturn( 'https://app.lihi.com/admin/password/reset' );
    }

    protected function tearDown(): void
    {
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set( null );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );
        $_POST = [];
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return \Mockery\MockInterface&Lihi_Service
     */
    private function mockService(): Lihi_Service
    {
        $service = Mockery::mock( Lihi_Service::class );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set( $service );

        return $service;
    }

    private function expectJsonError( &$payload, ?int &$status_code ): void
    {
        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->andReturnUsing( function ( $data, $status = null ) use ( &$payload, &$status_code ) {
                $payload     = $data;
                $status_code = $status;
            } );
    }

    private function expectJsonSuccess( &$payload ): void
    {
        Functions\expect( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$payload ) {
                $payload = $data;
            } );
    }

    /** @test */
    public function login_requires_manage_options(): void
    {
        $_POST = [
            'email'    => 'alice@example.com',
            'password' => 'secret-password',
        ];
        Functions\when( 'current_user_can' )->justReturn( false );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertSame( 403, $status );
        $this->assertStringContainsString( 'permission', $payload );
    }

    /** @test */
    public function login_rejects_invalid_email_before_authentication(): void
    {
        $_POST = [
            'email'    => 'not-an-email',
            'password' => 'secret-password',
        ];
        $this->mockService()->shouldNotReceive( 'login' );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertSame( 400, $status );
        $this->assertStringContainsString( 'valid email', $payload );
    }

    /** @test */
    public function login_requires_password(): void
    {
        $_POST['email'] = 'alice@example.com';
        $this->mockService()->shouldNotReceive( 'login' );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertSame( 400, $status );
        $this->assertStringContainsString( 'password', $payload );
    }

    /** @test */
    public function login_preserves_password_bytes_and_never_returns_tokens(): void
    {
        $password = "  p@ss\\\\word\\t ";
        $_POST     = [
            'email'    => 'Alice@example.com',
            'password' => addslashes( $password ),
        ];

        $this->mockService()
            ->shouldReceive( 'login' )
            ->once()
            ->with( 'Alice@example.com', $password )
            ->andReturn( [
                'email'         => 'Alice@example.com',
                'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
                'access_token'  => 'access-secret',
                'refresh_token' => 'refresh-secret',
            ] );

        $payload = null;
        $this->expectJsonSuccess( $payload );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertTrue( $payload['authenticated'] );
        $encoded = json_encode( $payload );
        $this->assertStringNotContainsString( 'access-secret', $encoded );
        $this->assertStringNotContainsString( 'refresh-secret', $encoded );
        $this->assertStringNotContainsString( $password, $encoded );
    }

    /** @test */
    public function login_maps_missing_account(): void
    {
        $_POST = [
            'email'    => 'missing@example.com',
            'password' => 'secret-password',
        ];
        $this->mockService()
            ->shouldReceive( 'login' )
            ->once()
            ->andThrow( new Lihi_Account_Not_Found_Exception( 'account does not exist' ) );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertSame( 403, $status );
        $this->assertSame( 'account_not_found', $payload['code'] );
    }

    /** @test */
    public function login_maps_invalid_password_to_reset_action(): void
    {
        $_POST = [
            'email'    => 'alice@example.com',
            'password' => 'wrong-password',
        ];
        $this->mockService()
            ->shouldReceive( 'login' )
            ->once()
            ->andThrow( new Lihi_Email_Or_Password_Invalid_Exception( 'password invalid' ) );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertSame( 403, $status );
        $this->assertSame( 'email_or_password_invalid', $payload['code'] );
        $this->assertSame( 'https://app.lihi.com/admin/password/reset', $payload['password_reset_url'] );
    }

    /** @test */
    public function login_distinguishes_a_busy_authentication_lease(): void
    {
        $_POST = [
            'email'    => 'alice@example.com',
            'password' => 'secret-password',
        ];
        $this->mockService()
            ->shouldReceive( 'login' )
            ->once()
            ->andThrow( new Lihi_Authentication_Busy_Exception( 'lock held' ) );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertSame( 409, $status );
        $this->assertStringContainsString(
            'authentication request is in progress',
            $payload
        );
        $this->assertStringNotContainsString( 'lock held', $payload );
    }

    /** @test */
    public function login_hides_unexpected_throwable_details_and_password(): void
    {
        $password = 'do-not-leak-this-password';
        $_POST = [
            'email'    => 'alice@example.com',
            'password' => $password,
        ];
        $this->mockService()
            ->shouldReceive( 'login' )
            ->once()
            ->andThrow( new \TypeError( 'unexpected failure: ' . $password ) );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_login();

        $this->assertSame( 503, $status );
        $this->assertStringNotContainsString( $password, (string) $payload );
        $this->assertStringNotContainsString( 'unexpected failure', (string) $payload );
    }

    /** @test */
    public function register_requires_six_character_password_and_consent(): void
    {
        $_POST = [
            'email'                   => 'alice@example.com',
            'password'                => '12345',
            'create_account_consent' => '0',
        ];
        $this->mockService()->shouldNotReceive( 'register' );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_register();

        $this->assertSame( 400, $status );
        $this->assertStringContainsString( 'at least 6', $payload );
    }

    /** @test */
    public function register_counts_unicode_code_points_like_the_upstream_validator(): void
    {
        $_POST = [
            'email'                   => 'alice@example.com',
            'password'                => '密碼測試嗎',
            'create_account_consent' => '1',
        ];
        $this->mockService()->shouldNotReceive( 'register' );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_register();

        $this->assertSame( 400, $status );
        $this->assertStringContainsString( 'at least 6', $payload );
    }

    /** @test */
    public function register_requires_account_creation_consent(): void
    {
        $_POST = [
            'email'    => 'alice@example.com',
            'password' => 'secret-password',
        ];
        $this->mockService()->shouldNotReceive( 'register' );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_register();

        $this->assertSame( 400, $status );
        $this->assertStringContainsString( 'confirm', $payload );
    }

    /** @test */
    public function register_sends_raw_password_but_does_not_connect_account(): void
    {
        $password = "six+ chars\\\\exact";
        $_POST     = [
            'email'                   => 'alice@example.com',
            'password'                => addslashes( $password ),
            'create_account_consent' => '1',
        ];
        $this->mockService()
            ->shouldReceive( 'register' )
            ->once()
            ->with( 'alice@example.com', $password );

        $payload = null;
        $this->expectJsonSuccess( $payload );

        \Lihi\ShortUrl\ajax_lihi_register();

        $this->assertTrue( $payload['verification_sent'] );
        $this->assertSame( 'alice@example.com', $payload['email'] );
        $this->assertStringNotContainsString( $password, json_encode( $payload ) );
    }

    /** @test */
    public function register_maps_existing_account_to_conflict(): void
    {
        $_POST = [
            'email'                   => 'alice@example.com',
            'password'                => 'secret-password',
            'create_account_consent' => '1',
        ];
        $this->mockService()
            ->shouldReceive( 'register' )
            ->once()
            ->andThrow( new Lihi_Account_Already_Exists_Exception( 'account already exists' ) );

        $payload = null;
        $status  = null;
        $this->expectJsonError( $payload, $status );

        \Lihi\ShortUrl\ajax_lihi_register();

        $this->assertSame( 409, $status );
        $this->assertSame( 'account_already_exists', $payload['code'] );
    }

    /** @test */
    public function logout_clears_the_atomic_local_credential_bundle(): void
    {
        $this->mockService()->shouldReceive( 'logout' )->once();

        $payload = null;
        $this->expectJsonSuccess( $payload );

        \Lihi\ShortUrl\ajax_lihi_logout();

        $this->assertStringContainsString( 'Logged out', $payload['message'] );
    }

    /** @test */
    public function dashboard_uses_public_home_when_not_authenticated(): void
    {
        Functions\when( 'Lihi\\ShortUrl\\lihi_is_authenticated' )->justReturn( false );
        Functions\when( 'Lihi\\ShortUrl\\lihi_home_url' )->justReturn( 'https://lihi.io' );

        $payload = null;
        $this->expectJsonSuccess( $payload );

        \Lihi\ShortUrl\ajax_dashboard_passthrough();

        $this->assertFalse( $payload['passthrough'] );
        $this->assertSame( 'https://lihi.io', $payload['home_url'] );
    }

    /** @test */
    public function dashboard_uses_service_fallback_for_authenticated_session(): void
    {
        $_POST['challenge'] = str_repeat( 'A', 43 );
        Functions\when( 'Lihi\\ShortUrl\\lihi_is_authenticated' )->justReturn( true );
        Functions\when( 'Lihi\\ShortUrl\\lihi_passthrough_redirect_url' )
            ->justReturn( 'https://app.lihi.com/api/wordpress/v1/passthrough/redirect' );

        $this->mockService()
            ->shouldReceive( 'create_passthrough_nonce' )
            ->once()
            ->with( '', str_repeat( 'A', 43 ) )
            ->andReturn( 'nonce-token' );

        $payload = null;
        $this->expectJsonSuccess( $payload );

        \Lihi\ShortUrl\ajax_dashboard_passthrough();

        $this->assertTrue( $payload['passthrough'] );
        $this->assertSame( 'nonce-token', $payload['nonce'] );
    }
}
