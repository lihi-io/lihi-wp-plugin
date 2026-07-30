<?php

namespace Lihi\ShortUrl\Tests;

class PluginLifecycleTest extends \WP_UnitTestCase
{
    private const EPOCH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        update_option( 'lihi_auth_epoch', self::EPOCH, false );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set( null );
    }

    protected function tearDown(): void
    {
        update_option( 'lihi_auth_tokens', [
            'email'         => 'test@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        delete_option( 'lihi_auth_tokens_lock' );
        update_option( 'lihi_auth_epoch', self::EPOCH, false );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_token_store_set( null );
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set( null );
        parent::tearDown();
    }

    public function test_deactivation_hook_is_registered(): void
    {
        $hook = 'deactivate_' . plugin_basename( dirname( __DIR__ ) . '/lihi-short-url/lihi-short-url.php' );

        $this->assertNotFalse( has_action( $hook, 'Lihi\\ShortUrl\\deactivate' ) );
    }

    public function test_activation_hook_is_registered(): void
    {
        $hook = 'activate_' . plugin_basename( dirname( __DIR__ ) . '/lihi-short-url/lihi-short-url.php' );

        $this->assertNotFalse( has_action( $hook, 'Lihi\\ShortUrl\\activate' ) );
    }

    public function test_deactivation_clears_plugin_owned_site_data(): void
    {
        update_option( 'lihi_auth_tokens', [
            'email'         => 'admin@example.com',
            'uuid'          => '7c7a984b-c772-479c-957b-5a25bb2b9d18',
            'access_token'  => 'access.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        do_action( 'deactivate_' . plugin_basename( dirname( __DIR__ ) . '/lihi-short-url/lihi-short-url.php' ) );

        $this->assertFalse( get_option( 'lihi_auth_tokens' ) );
        $this->assertFalse( get_option( 'lihi_auth_tokens_lock' ) );
        $this->assertFalse( get_option( 'lihi_auth_epoch' ) );
    }

    public function test_activation_starts_disconnected_and_removes_the_fence(): void
    {
        delete_option( 'lihi_auth_epoch' );
        update_option( 'lihi_auth_tokens', [
            'email'         => 'admin@example.com',
            'uuid'          => '7c7a984b-c772-479c-957b-5a25bb2b9d18',
            'access_token'  => 'access.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );

        do_action( 'activate_' . plugin_basename( dirname( __DIR__ ) . '/lihi-short-url/lihi-short-url.php' ) );

        $this->assertFalse( get_option( 'lihi_auth_tokens' ) );
        $this->assertFalse( get_option( 'lihi_auth_tokens_lock' ) );
        $epoch = get_option( 'lihi_auth_epoch' );
        $this->assertIsString( $epoch );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $epoch );
    }
}
