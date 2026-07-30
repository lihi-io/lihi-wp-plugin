<?php

namespace Lihi\ShortUrl\Tests;

class HelperTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option( 'lihi_auth_tokens' );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_client_set( null );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );
    }

    protected function tearDown(): void
    {
        update_option( 'lihi_auth_tokens', [
            'email'         => 'test@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_client_set( null );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );
        parent::tearDown();
    }

    public function test_lihi_email_returns_email_from_atomic_credentials(): void
    {
        update_option( 'lihi_auth_tokens', [
            'email'         => 'hello@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );

        $this->assertSame( 'hello@example.com', \Lihi\ShortUrl\lihi_email() );
    }

    public function test_lihi_email_returns_empty_string_without_complete_credentials(): void
    {
        $this->assertSame( '', \Lihi\ShortUrl\lihi_email() );

        update_option( 'lihi_auth_tokens', [
            'email'        => 'hello@example.com',
            'access_token' => 'header.payload.signature',
        ], false );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );

        $this->assertSame( '', \Lihi\ShortUrl\lihi_email() );
    }

    public function test_lihi_is_authenticated_requires_one_complete_credential_bundle(): void
    {
        $this->assertFalse( \Lihi\ShortUrl\lihi_is_authenticated() );

        update_option( 'lihi_auth_tokens', [
            'email'         => 'hello@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );

        $this->assertTrue( \Lihi\ShortUrl\lihi_is_authenticated() );

        update_option( 'lihi_auth_tokens', [
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );

        $this->assertFalse( \Lihi\ShortUrl\lihi_is_authenticated() );
    }

    public function test_lihi_email_fails_closed_when_the_store_throws(): void
    {
        $store = new class extends \Lihi\ShortUrl\Lihi_Token_Store {
            public function __construct()
            {
            }

            public function get()
            {
                throw new \Lihi\ShortUrl\Lihi_Server_Exception(
                    'database read failed'
                );
            }
        };
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( $store );

        $this->assertSame( '', \Lihi\ShortUrl\lihi_email() );
    }

    public function test_lihi_is_authenticated_fails_closed_when_the_store_throws(): void
    {
        $store = new class extends \Lihi\ShortUrl\Lihi_Token_Store {
            public function __construct()
            {
            }

            public function has_credentials(): bool
            {
                throw new \Lihi\ShortUrl\Lihi_Server_Exception(
                    'database read failed'
                );
            }
        };
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( $store );

        $this->assertFalse( \Lihi\ShortUrl\lihi_is_authenticated() );
    }

    public function test_lihi_client_receives_base_url_from_helper(): void
    {
        $client = \Lihi\ShortUrl\Lihi_Singletons::lihi_client();
        $ref    = new \ReflectionClass( $client );

        $base_url = $ref->getProperty( 'base_url' );
        $base_url->setAccessible( true );

        $this->assertSame( 'https://app.lihi.com', $base_url->getValue( $client ) );
        $this->assertFalse( $ref->hasProperty( 'uuid' ) );
    }

    public function test_browser_facing_urls_use_the_production_app_host(): void
    {
        $this->assertSame(
            'https://app.lihi.com',
            \Lihi\ShortUrl\lihi_api_host()
        );
        $this->assertSame(
            'https://app.lihi.com/api/wordpress/v1/passthrough/redirect',
            \Lihi\ShortUrl\lihi_passthrough_redirect_url()
        );
        $this->assertSame(
            'https://app.lihi.com/admin/password/reset',
            \Lihi\ShortUrl\lihi_password_reset_url()
        );
    }

    public function test_lihi_singletons_exposes_static_client_factory(): void
    {
        $client = \Lihi\ShortUrl\Lihi_Singletons::lihi_client();

        $this->assertInstanceOf( \Lihi\ShortUrl\Lihi_Client_Interface::class, $client );
        $this->assertSame( $client, \Lihi\ShortUrl\Lihi_Singletons::lihi_client() );
    }

    public function test_client_service_and_store_global_wrappers_do_not_exist(): void
    {
        $this->assertFalse( function_exists( 'Lihi\\ShortUrl\\lihi_client' ) );
        $this->assertFalse( function_exists( 'Lihi\\ShortUrl\\lihi_service' ) );
        $this->assertFalse( function_exists( 'Lihi\\ShortUrl\\lihi_token_store' ) );
    }
}
