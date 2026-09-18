<?php

namespace Lihi\ShortUrl\Tests;

class AdminNoticeTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option( 'lihi_auth_tokens' );
        remove_all_actions( 'admin_notices' );
    }

    protected function tearDown(): void
    {
        remove_all_actions( 'admin_notices' );
        update_option( 'lihi_auth_tokens', [
            'email'         => 'test@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        parent::tearDown();
    }

    /** Re-execute bootstrap.php against the option state set up in the test. */
    private function loadBootstrap(): void
    {
        include dirname( __DIR__ ) . '/lihi-short-url/bootstrap.php';
    }

    public function test_no_global_admin_notice_when_disconnected(): void
    {
        $this->loadBootstrap();
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        ob_start();
        do_action( 'admin_notices' );
        $output = ob_get_clean();

        $this->assertFalse( has_action( 'admin_notices' ) );
        $this->assertSame( '', $output );
    }

    public function test_no_global_admin_notice_when_connected(): void
    {
        update_option( 'lihi_auth_tokens', [
            'email'         => 'admin@example.com',
            'uuid'          => '7c7a984b-c772-479c-957b-5a25bb2b9d18',
            'access_token'  => 'access.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ], false );
        $this->loadBootstrap();

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        ob_start();
        do_action( 'admin_notices' );
        $output = ob_get_clean();

        $this->assertFalse( has_action( 'admin_notices' ) );
        $this->assertSame( '', $output );
    }

}
