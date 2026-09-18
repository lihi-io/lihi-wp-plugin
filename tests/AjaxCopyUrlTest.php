<?php

namespace Lihi\ShortUrl\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lihi\ShortUrl\Lihi_Service;
use Mockery;
use PHPUnit\Framework\TestCase;

class AjaxCopyUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_POST = [];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('post');
        Functions\when('Lihi\\ShortUrl\\lihi_is_authenticated')->justReturn(true);
    }

    protected function tearDown(): void
    {
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set(null);
        $_POST = [];
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return \Mockery\MockInterface&Lihi_Service
     */
    private function mockService(): Lihi_Service
    {
        return Mockery::mock(Lihi_Service::class);
    }

    /**
     * @param mixed $message
     */
    private function expectJsonError(&$message, ?int &$statusCode): void
    {
        Functions\expect('wp_send_json_error')
            ->once()
            ->andReturnUsing(function ($msg, $status = null) use (&$message, &$statusCode) {
                $message    = $msg;
                $statusCode = $status;
            });
    }

    // -------------------------------------------------------------------------
    // wp_ajax_lihi_url_options / copy / create
    // -------------------------------------------------------------------------

    /** @test */
    public function url_options_returns_domains_and_utm_options_for_modal(): void
    {
        $_POST['item_id'] = '42';

        $service = $this->mockService();
        $service->shouldReceive('get_url_options')
            ->once()
            ->andReturn([
                'domains'     => [
                    ['id' => 12, 'name' => 'go.example.com'],
                    ['id' => 'go2.example.com', 'name' => 'go2.example.com'],
                    'legacy.example.com',
                ],
                'utm_sources' => ['facebook', ' newsletter ', ''],
                'utm_mediums' => ['social', 'email', 'social'],
            ]);
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_url_options();

        $this->assertSame([
            ['value' => '12', 'label' => 'go.example.com'],
            ['value' => 'go2.example.com', 'label' => 'go2.example.com'],
            ['value' => 'legacy.example.com', 'label' => 'legacy.example.com'],
        ], $sent['domains']);
        $this->assertSame([
            'source' => ['facebook', 'newsletter'],
            'medium' => ['social', 'email'],
        ], $sent['utm_options']);
    }

    /** @test */
    public function returns_error_when_lihi_is_not_connected(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('Lihi\\ShortUrl\\lihi_is_authenticated')->justReturn(false);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_copy_url();

        $this->assertStringContainsString('lihi is not connected', $errorMsg);
        $this->assertSame(409, $statusCode);
    }

    /** @test */
    public function returns_error_when_item_id_is_zero(): void
    {
        $_POST['item_id'] = '0';
        $_POST['type']    = 'post';

        Functions\when('check_ajax_referer')->justReturn(true);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_copy_url();

        $this->assertSame('Invalid post ID or type.', $errorMsg);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function returns_error_when_post_type_cannot_be_resolved(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('get_post_type')->justReturn(false);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_copy_url();

        $this->assertSame('Invalid post ID or type.', $errorMsg);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function returns_error_when_user_cannot_read_item(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';

        $service = $this->mockService();
        $service->shouldNotReceive('get_or_create_short_url');
        $service->shouldNotReceive('get_existing_short_url');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $checkedCap    = null;
        $checkedPostId = null;
        Functions\when('current_user_can')->alias(function ($capability, $postId = null) use (&$checkedCap, &$checkedPostId) {
            $checkedCap    = $capability;
            $checkedPostId = $postId;
            return false;
        });

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_copy_url();

        $this->assertSame('read_post', $checkedCap);
        $this->assertSame(42, $checkedPostId);
        $this->assertStringContainsString('permission', $errorMsg);
        $this->assertSame(403, $statusCode);
    }

    /** @test */
    public function returns_success_with_url_on_valid_request(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';

        $service = $this->mockService();
        $service->shouldReceive('get_existing_short_url')
            ->with(42, 'post')
            ->once()
            ->andReturn('abc-slug');
        $service->shouldNotReceive('get_or_create_short_url');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, 'lihi_already', '1');

        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_copy_url();

        $this->assertSame('abc-slug', $sent['url']);
        $this->assertTrue($sent['lihi_already']);
    }

    /** @test */
    public function derives_type_from_item_id_instead_of_trusting_client_payload(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'forged';
        $_POST['domain']  = 'go.example.com';

        Functions\when('get_post_type')->justReturn('page');

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->with(42, 'page', ['domain' => 'go.example.com', 'tags' => [], 'utm' => []])
            ->once()
            ->andReturn('page-slug');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\expect('update_post_meta')
            ->once()
            ->with(42, 'lihi_already', '1');

        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertSame('page-slug', $sent['url']);
        $this->assertTrue($sent['lihi_already']);
    }

    /** @test */
    public function passes_modal_options_to_service_on_create_request(): void
    {
        $_POST['item_id'] = '42';
        $_POST['domain']  = 'go.example.com';
        $_POST['tags']    = '[" launch ","post",""]';
        $_POST['utm']     = '{"source":"newsletter","medium":"email","ignored":"x"}';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->with(42, 'post', [
                'domain' => 'go.example.com',
                'tags'   => ['launch', 'post'],
                'utm'    => ['source' => 'newsletter', 'medium' => 'email'],
            ])
            ->once()
            ->andReturn('option-slug');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\expect('update_post_meta')
            ->once()
            ->with(42, 'lihi_already', '1');
        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertSame('option-slug', $sent['url']);
        $this->assertTrue($sent['lihi_already']);
    }

    /** @test */
    public function create_passes_selected_domain_to_saas_without_extra_profile_validation(): void
    {
        $_POST['item_id'] = '42';
        $_POST['domain']  = 'other.example.com';

        $service = $this->mockService();
        $service->shouldNotReceive('get_profile');
        $service->shouldReceive('get_or_create_short_url')
            ->with(42, 'post', ['domain' => 'other.example.com', 'tags' => [], 'utm' => []])
            ->once()
            ->andReturn('other-slug');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\expect('update_post_meta')
            ->once()
            ->with(42, 'lihi_already', '1');

        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertSame('other-slug', $sent['url']);
        $this->assertTrue($sent['lihi_already']);
    }

    /** @test */
    public function create_requires_a_selected_domain(): void
    {
        $_POST['item_id'] = '42';

        $service = $this->mockService();
        $service->shouldNotReceive('get_profile');
        $service->shouldNotReceive('get_or_create_short_url');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('Please choose a redirect domain', $errorMsg);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function copy_returns_existing_short_url_without_creating(): void
    {
        $_POST['item_id'] = '42';

        $service = $this->mockService();
        $service->shouldReceive('get_existing_short_url')
            ->with(42, 'post')
            ->once()
            ->andReturn('existing-slug');
        $service->shouldNotReceive('get_or_create_short_url');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\expect('update_post_meta')
            ->once()
            ->with(42, 'lihi_already', '1');

        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_copy_url();

        $this->assertSame('existing-slug', $sent['url']);
        $this->assertTrue($sent['lihi_already']);
    }

    /** @test */
    public function copy_marks_item_unready_when_existing_short_url_is_missing(): void
    {
        $_POST['item_id'] = '42';

        $service = $this->mockService();
        $service->shouldReceive('get_existing_short_url')
            ->with(42, 'post')
            ->once()
            ->andThrow(new \Lihi\ShortUrl\Lihi_Not_Found_Exception('missing'));
        $service->shouldNotReceive('get_or_create_short_url');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\expect('update_post_meta')
            ->once()
            ->with(42, 'lihi_already', '0');

        $captured   = null;
        $statusCode = null;
        Functions\expect('wp_send_json_error')
            ->once()
            ->andReturnUsing(function ($data, $status = null) use (&$captured, &$statusCode) {
                $captured   = $data;
                $statusCode = $status;
            });

        \Lihi\ShortUrl\ajax_copy_url();

        $this->assertSame('lihi_missing', $captured['code']);
        $this->assertStringContainsString('removed', $captured['message']);
        $this->assertSame(410, $statusCode);
    }

    /** @test */
    public function passthrough_nonce_returns_nonce_for_frontend_selected_target(): void
    {
        $_POST['item_id'] = '42';
        $_POST['challenge'] = str_repeat('A', 43);
        $_POST['target'] = '/myDomain';

        $service = $this->mockService();
        $service->shouldReceive('create_passthrough_nonce')
            ->with('/myDomain', str_repeat('A', 43))
            ->once()
            ->andReturn('nonce-token');
        $service->shouldNotReceive('get_existing_short_url');
        $service->shouldNotReceive('get_or_create_short_url');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\when('Lihi\ShortUrl\lihi_passthrough_redirect_url')->justReturn('https://app.lihi.io/api/wordpress/v1/passthrough/redirect');

        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_passthrough_nonce();

        $this->assertSame('nonce-token', $sent['nonce']);
        $this->assertSame('https://app.lihi.io/api/wordpress/v1/passthrough/redirect', $sent['redirect_url']);
        $this->assertArrayNotHasKey('target', $sent);
    }

    /** @test */
    public function passthrough_nonce_accepts_utm_management_target(): void
    {
        $_POST['item_id'] = '42';
        $_POST['challenge'] = str_repeat('A', 43);
        $_POST['target'] = '/utm';

        $service = $this->mockService();
        $service->shouldReceive('create_passthrough_nonce')
            ->with('/utm', str_repeat('A', 43))
            ->once()
            ->andReturn('nonce-token');
        $service->shouldNotReceive('get_existing_short_url');
        $service->shouldNotReceive('get_or_create_short_url');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\when('Lihi\ShortUrl\lihi_passthrough_redirect_url')->justReturn('https://app.lihi.io/api/wordpress/v1/passthrough/redirect');

        $sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$sent) {
                $sent = $data;
            });

        \Lihi\ShortUrl\ajax_passthrough_nonce();

        $this->assertSame('nonce-token', $sent['nonce']);
        $this->assertSame('https://app.lihi.io/api/wordpress/v1/passthrough/redirect', $sent['redirect_url']);
        $this->assertArrayNotHasKey('target', $sent);
    }

    /** @test */
    public function passthrough_nonce_requires_browser_challenge(): void
    {
        $_POST['item_id'] = '42';
        $_POST['target'] = '/myDomain';

        $service = $this->mockService();
        $service->shouldNotReceive('create_passthrough_nonce');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_passthrough_nonce();

        $this->assertStringContainsString('verify browser session', $errorMsg);
        $this->assertStringNotContainsString('lihi API rejected', $errorMsg);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function passthrough_nonce_rejects_invalid_frontend_target(): void
    {
        $_POST['item_id'] = '42';
        $_POST['challenge'] = str_repeat('A', 43);
        $_POST['target'] = '?tag=https%3A%2F%2Fexample.com%2Fdemo';

        $service = $this->mockService();
        $service->shouldNotReceive('create_passthrough_nonce');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_passthrough_nonce();

        $this->assertStringContainsString('Invalid lihi dashboard target', $errorMsg);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function passthrough_nonce_rejects_array_frontend_target(): void
    {
        $_POST['item_id'] = '42';
        $_POST['challenge'] = str_repeat('A', 43);
        $_POST['target'] = ['https://lihi.io/existing'];

        $service = $this->mockService();
        $service->shouldNotReceive('create_passthrough_nonce');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_passthrough_nonce();

        $this->assertStringContainsString('Invalid lihi dashboard target', $errorMsg);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function passthrough_nonce_requires_manage_options(): void
    {
        $_POST['item_id'] = '42';
        $_POST['challenge'] = str_repeat('A', 43);
        $_POST['target'] = '/myDomain';

        $service = $this->mockService();
        $service->shouldNotReceive('create_passthrough_nonce');
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $checkedCaps = [];
        Functions\when('current_user_can')->alias(function ($capability, $postId = null) use (&$checkedCaps) {
            $checkedCaps[] = [$capability, $postId];
            return $capability !== 'manage_options';
        });

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_passthrough_nonce();

        $this->assertContains(['manage_options', null], $checkedCaps);
        $this->assertNotContains(['read_post', 42], $checkedCaps);
        $this->assertStringContainsString('permission', $errorMsg);
        $this->assertSame(403, $statusCode);
    }

    /** @test */
    public function returns_error_when_service_throws(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \RuntimeException('API error'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\when('check_ajax_referer')->justReturn(true);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertSame('Failed to generate short URL. Please try again later.', $errorMsg);
        $this->assertSame(500, $statusCode);
    }

    /** @test */
    public function returns_friendly_message_on_auth_exception(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Auth_Exception('API Key error'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        Functions\when('check_ajax_referer')->justReturn(true);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('sign in again', $errorMsg);
        $this->assertSame(403, $statusCode);
    }

    /** @test */
    public function returns_friendly_message_on_user_invalid_exception(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_User_Invalid_Exception('User Invalid'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('account is unavailable', $errorMsg);
        $this->assertSame(403, $statusCode);
    }

    /** @test */
    public function returns_login_expired_message_on_token_invalid_exception(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Token_Invalid_Exception('HTTP 500: Token expired ,please login again'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('could not be refreshed', $errorMsg);
        $this->assertSame(401, $statusCode);
    }

    /** @test */
    public function returns_user_invalid_message_on_user_not_found_response_exception(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_User_Invalid_Exception('HTTP 404: user_not_found ,please login again'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('account is unavailable', $errorMsg);
        $this->assertSame(403, $statusCode);
    }

    /** @test */
    public function returns_bad_request_when_service_throws_validation_exception(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Validation_Exception('bad request'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('lihi API rejected the request', $errorMsg);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function returns_plugin_owned_need_upgrade_error(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Need_Upgrade_Exception(
                'need_upgrade'
            ));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorData  = null;
        $statusCode = null;
        $this->expectJsonError($errorData, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertCount(2, $errorData);
        $this->assertSame('need_upgrade', $errorData['code']);
        $this->assertSame(
            'Please upgrade or renew your lihi plan to create this short URL.',
            $errorData['message']
        );
        $this->assertArrayNotHasKey('type', $errorData);
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function site_create_fail_uses_the_validation_error_response(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Validation_Exception(
                'site_create_fail'
            ));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString(
            'lihi API rejected the request: site_create_fail',
            $errorMsg
        );
        $this->assertSame(400, $statusCode);
    }

    /** @test */
    public function returns_rate_limit_status_without_leaking_upstream_details(): void
    {
        $_POST['item_id'] = '42';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Rate_Limit_Exception('internal quota detail'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('Too many requests', $errorMsg);
        $this->assertStringNotContainsString('internal quota detail', $errorMsg);
        $this->assertSame(429, $statusCode);
    }

    /** @test */
    public function returns_conflict_when_the_authentication_lease_is_busy(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Authentication_Busy_Exception('lock held'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString(
            'authentication request is in progress',
            $errorMsg
        );
        $this->assertStringNotContainsString('lock held', $errorMsg);
        $this->assertSame(409, $statusCode);
    }

    /** @test */
    public function returns_service_unavailable_when_lihi_service_throws_server_exception(): void
    {
        $_POST['item_id'] = '42';
        $_POST['type']    = 'post';
        $_POST['domain']  = 'go.example.com';

        $service = $this->mockService();
        $service->shouldReceive('get_or_create_short_url')
            ->andThrow(new \Lihi\ShortUrl\Lihi_Server_Exception('upstream unavailable'));
        \Lihi\ShortUrl\Lihi_Singletons::lihi_service_set($service);

        $errorMsg   = null;
        $statusCode = null;
        $this->expectJsonError($errorMsg, $statusCode);

        \Lihi\ShortUrl\ajax_create_url();

        $this->assertStringContainsString('lihi service is unavailable', $errorMsg);
        $this->assertSame(503, $statusCode);
    }
}
