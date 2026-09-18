<?php

namespace Lihi\ShortUrl\Tests;

class PluginHooksTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles']  = null;

        if (!function_exists('set_current_screen')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        set_current_screen('dashboard');

        // do_action('admin_init') triggers WP update checks that make outbound
        // HTTP calls and hit a known reference-arg bug under patchwork. Strip
        // the network-touching callbacks so tests only exercise our hooks.
        remove_action('admin_init', 'wp_version_check');
        remove_action('admin_init', 'wp_update_plugins');
        remove_action('admin_init', 'wp_update_themes');
        remove_action('admin_init', 'wp_schedule_update_checks');
        remove_action('admin_init', '_maybe_update_core');
        remove_action('admin_init', '_maybe_update_plugins');
        remove_action('admin_init', '_maybe_update_themes');
    }

    protected function tearDown(): void
    {
        $GLOBALS['current_screen'] = null;
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // AJAX actions registered
    // -------------------------------------------------------------------------

    public function test_wp_ajax_lihi_copy_url_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_copy_url'));
    }

    public function test_wp_ajax_lihi_create_url_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_create_url'));
    }

    public function test_wp_ajax_lihi_login_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_login'));
    }

    public function test_wp_ajax_lihi_register_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_register'));
    }

    public function test_wp_ajax_lihi_logout_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_logout'));
    }

    public function test_wp_ajax_lihi_group_options_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_group_options'));
    }

    public function test_wp_ajax_lihi_switch_group_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_switch_group'));
    }

    public function test_removed_update_email_ajax_handler_is_not_registered(): void
    {
        $this->assertFalse(has_action('wp_ajax_lihi_update_email'));
    }

    public function test_wp_ajax_lihi_url_options_handler_is_registered(): void
    {
        $this->assertNotFalse(has_action('wp_ajax_lihi_url_options'));
    }

    // -------------------------------------------------------------------------
    // Script enqueue via admin_enqueue_scripts
    // -------------------------------------------------------------------------

    public function test_lihi_admin_script_enqueued_on_edit_screen(): void
    {
        do_action('admin_enqueue_scripts', 'edit.php');
        $this->assertTrue(wp_script_is('lihi-button', 'enqueued'));
    }

    public function test_lihi_admin_script_helpers_are_enqueued_before_main_script(): void
    {
        do_action('admin_enqueue_scripts', 'edit.php');

        $this->assertTrue(wp_script_is('lihi-button-api', 'enqueued'));
        $this->assertTrue(wp_script_is('lihi-button-modal', 'enqueued'));

        $scripts = wp_scripts();
        $this->assertSame(['lihi-button-api'], $scripts->registered['lihi-button-modal']->deps);
        $this->assertSame(['lihi-button-api', 'lihi-button-modal'], $scripts->registered['lihi-button']->deps);
    }

    public function test_lihi_admin_script_enqueued_on_upload_screen(): void
    {
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertTrue(wp_script_is('lihi-button', 'enqueued'));
    }

    public function test_lihi_admin_script_enqueued_on_post_edit_screen(): void
    {
        do_action('admin_enqueue_scripts', 'post.php');
        $this->assertTrue(wp_script_is('lihi-button', 'enqueued'));
    }

    public function test_lihi_admin_script_enqueued_on_new_post_screen(): void
    {
        do_action('admin_enqueue_scripts', 'post-new.php');
        $this->assertTrue(wp_script_is('lihi-button', 'enqueued'));
    }

    public function test_lihi_admin_script_not_enqueued_on_unrelated_screens(): void
    {
        do_action('admin_enqueue_scripts', 'index.php');
        $this->assertFalse(wp_script_is('lihi-button', 'enqueued'));
    }

    // -------------------------------------------------------------------------
    // Post-type column registration (add-shorturl-column.php admin_init)
    // -------------------------------------------------------------------------

    private function runAdminInit(): void
    {
        // admin_init has callbacks that try to send headers; buffer to prevent
        // fatal errors from output already emitted by the test runner bootstrap.
        ob_start();
        @do_action('admin_init');
        ob_end_clean();
    }

    public function test_post_list_has_short_url_column(): void
    {
        $this->runAdminInit();
        $columns = apply_filters('manage_post_posts_columns', []);
        $this->assertArrayHasKey('lihi', $columns);
    }

    public function test_post_column_renders_lihi_button_container(): void
    {
        $this->runAdminInit();
        $post_id = self::factory()->post->create();

        ob_start();
        do_action('manage_post_posts_custom_column', 'lihi', $post_id);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-lihi-container', $html);
        $this->assertStringContainsString('class="lihi-button-container"', $html);
        $this->assertStringNotContainsString('hidden', $html);
        $this->assertStringNotContainsString('type="button"', $html);
        $this->assertStringContainsString('data-id="' . $post_id . '"', $html);
        $this->assertStringContainsString('data-type="post"', $html);
        $this->assertStringContainsString('data-lihi-already="0"', $html);
    }

    public function test_post_column_marks_button_when_lihi_already_meta_is_set(): void
    {
        $this->runAdminInit();
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'lihi_already', '1');

        ob_start();
        do_action('manage_post_posts_custom_column', 'lihi', $post_id);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-lihi-container', $html);
        $this->assertStringContainsString('data-lihi-already="1"', $html);
    }

    public function test_media_library_has_short_url_column(): void
    {
        $this->runAdminInit();
        $columns = apply_filters('manage_media_columns', []);
        $this->assertArrayHasKey('lihi', $columns);
    }

    public function test_media_column_renders_attachment_container(): void
    {
        $this->runAdminInit();
        $att_id = self::factory()->attachment->create_object('photo.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ]);

        ob_start();
        do_action('manage_media_custom_column', 'lihi', $att_id);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-lihi-container', $html);
        $this->assertStringContainsString('data-type="attachment"', $html);
        $this->assertStringNotContainsString('type="button"', $html);
    }

    public function test_attachment_edit_panel_has_lihi_field(): void
    {
        $att_id = self::factory()->attachment->create_object('photo.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ]);
        $post   = get_post($att_id);
        $fields = apply_filters('attachment_fields_to_edit', [], $post);

        $this->assertArrayHasKey('lihi', $fields);
        $this->assertStringContainsString('data-lihi-container', $fields['lihi']['html']);
        $this->assertStringNotContainsString('type="button"', $fields['lihi']['html']);
    }

    public function test_attachment_edit_panel_marks_button_when_lihi_already_meta_is_set(): void
    {
        $att_id = self::factory()->attachment->create_object('photo.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ]);
        update_post_meta($att_id, 'lihi_already', '1');

        $post   = get_post($att_id);
        $fields = apply_filters('attachment_fields_to_edit', [], $post);

        $this->assertArrayHasKey('lihi', $fields);
        $this->assertStringContainsString('data-lihi-already="1"', $fields['lihi']['html']);
    }

    // -------------------------------------------------------------------------
    // Settings page registration (settings.php)
    // -------------------------------------------------------------------------

    public function test_admin_init_does_not_register_a_split_email_setting(): void
    {
        global $wp_registered_settings;
        $this->runAdminInit();
        $registered = is_array($wp_registered_settings) ? $wp_registered_settings : [];
        $this->assertArrayNotHasKey('lihi_email', $registered);
    }

    public function test_admin_menu_registers_settings_page(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        do_action('admin_menu');
        $this->assertNotFalse(menu_page_url('lihi-settings', false));
    }

    public function test_identity_and_tokens_share_one_atomic_option(): void
    {
        $credentials = [
            'email'         => 'new@example.com',
            'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
            'access_token'  => 'header.payload.signature',
            'refresh_token' => 'refresh.payload.signature',
        ];
        update_option('lihi_auth_tokens', $credentials, false);

        $this->assertSame($credentials, get_option('lihi_auth_tokens'));
    }
}
