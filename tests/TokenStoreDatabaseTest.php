<?php

declare(strict_types=1);

namespace Lihi\ShortUrl\Tests;

use Lihi\ShortUrl\Lihi_Token_Store;

/**
 * Exercises the token store against WordPress's real MySQL options table.
 */
final class TokenStoreDatabaseTest extends \WP_UnitTestCase
{
    private const ACCESS_TOKEN = 'CaseSensitiveAccessToken';
    private const EPOCH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EPOCH_KEY = 'lihi_auth_epoch';
    private const LOCK_KEY = 'lihi_auth_tokens_lock';
    private const OPTION_KEY = 'lihi_auth_tokens';
    private const REFRESH_TOKEN = 'CaseSensitiveRefreshToken';
    private const UUID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteOptionDirect( self::OPTION_KEY );
        $this->deleteOptionDirect( self::LOCK_KEY );
        $this->replaceOptionDirect( self::EPOCH_KEY, self::EPOCH );
    }

    protected function tearDown(): void
    {
        $this->deleteOptionDirect( self::OPTION_KEY );
        $this->deleteOptionDirect( self::LOCK_KEY );
        $this->deleteOptionDirect( self::EPOCH_KEY );

        update_option(
            self::OPTION_KEY,
            [
                'email'         => 'test@example.com',
                'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
                'access_token'  => 'header.payload.signature',
                'refresh_token' => 'refresh.payload.signature',
            ],
            false
        );
        update_option( self::EPOCH_KEY, self::EPOCH, false );

        parent::tearDown();
    }

    public function test_delete_if_access_token_cas_deletes_the_original_serialized_tuple(): void
    {
        $serialized = maybe_serialize( $this->nonCanonicalTuple() );
        $this->replaceOptionDirect( self::OPTION_KEY, $serialized );

        $this->assertSame(
            $serialized,
            $this->readOptionDirect( self::OPTION_KEY )
        );

        $store = new Lihi_Token_Store();
        $this->assertTrue( $store->acquire_lock() );

        try {
            $this->assertTrue(
                $store->delete_if_access_token( self::ACCESS_TOKEN )
            );
        } finally {
            $store->release_lock();
        }
        $this->assertFalse( $this->optionExistsDirect( self::OPTION_KEY ) );
    }

    public function test_delete_if_uuid_cas_deletes_the_original_serialized_tuple(): void
    {
        $serialized = maybe_serialize( $this->nonCanonicalTuple() );
        $this->replaceOptionDirect( self::OPTION_KEY, $serialized );

        $this->assertSame(
            $serialized,
            $this->readOptionDirect( self::OPTION_KEY )
        );

        $store = new Lihi_Token_Store();
        $this->assertTrue( $store->acquire_lock() );

        try {
            $this->assertTrue(
                $store->delete_if_uuid( strtoupper( self::UUID ) )
            );
        } finally {
            $store->release_lock();
        }
        $this->assertFalse( $this->optionExistsDirect( self::OPTION_KEY ) );
    }

    public function test_exact_delete_preserves_a_replacement_tuple_that_differs_only_by_token_case(): void
    {
        $expected_tuple = $this->canonicalTuple();
        $replacement    = $expected_tuple;

        $expected_tuple['access_token'] = self::ACCESS_TOKEN;
        $replacement['access_token']    = strtolower( self::ACCESS_TOKEN );

        $expected_serialized    = maybe_serialize( $expected_tuple );
        $replacement_serialized = maybe_serialize( $replacement );

        $this->assertNotSame(
            $expected_serialized,
            $replacement_serialized
        );
        $this->replaceOptionDirect(
            self::OPTION_KEY,
            $replacement_serialized
        );

        $method = new \ReflectionMethod(
            Lihi_Token_Store::class,
            'delete_option_if_exact'
        );
        $method->setAccessible( true );

        $deleted = $method->invoke(
            new Lihi_Token_Store(),
            self::OPTION_KEY,
            $expected_serialized
        );

        $this->assertFalse( $deleted );
        $this->assertSame(
            $replacement_serialized,
            $this->readOptionDirect( self::OPTION_KEY )
        );
    }

    /**
     * @dataProvider malformedEpochProvider
     */
    public function test_disable_removes_malformed_and_empty_epoch_rows(
        string $epoch
    ): void {
        $this->replaceOptionDirect( self::EPOCH_KEY, $epoch );

        $store = new Lihi_Token_Store();
        $store->disable();

        $this->assertFalse( $this->optionExistsDirect( self::EPOCH_KEY ) );
    }

    /**
     * @return array<string, array{string}>
     */
    public function malformedEpochProvider(): array
    {
        return [
            'malformed value' => [ 'not-a-valid-activation-epoch' ],
            'empty value'     => [ '' ],
        ];
    }

    /**
     * @dataProvider malformedLockProvider
     */
    public function test_acquire_lock_replaces_a_malformed_lock_row(
        string $malformed_lock
    ): void {
        $this->replaceOptionDirect( self::LOCK_KEY, $malformed_lock );

        $store = new Lihi_Token_Store();

        $this->assertTrue( $store->acquire_lock() );

        $replacement = $this->readOptionDirect( self::LOCK_KEY );
        $this->assertIsString( $replacement );
        $this->assertNotSame( $malformed_lock, $replacement );
        $this->assertMatchesRegularExpression(
            '/^[1-9][0-9]*:.+$/',
            $replacement
        );

        $store->release_lock();
        $this->assertFalse( $this->optionExistsDirect( self::LOCK_KEY ) );
    }

    /**
     * @return array<string, array{string}>
     */
    public function malformedLockProvider(): array
    {
        return [
            'malformed value' => [ 'not-a-timestamp:or-valid-lock-owner' ],
            'empty value'     => [ '' ],
        ];
    }

    public function test_guarded_upsert_cannot_overwrite_after_lock_replacement(): void
    {
        $replacement = $this->canonicalTuple();
        $replacement['access_token']  = 'new-owner-access';
        $replacement['refresh_token'] = 'new-owner-refresh';
        $replacement_raw = maybe_serialize( $replacement );
        $this->replaceOptionDirect( self::OPTION_KEY, $replacement_raw );

        $store = new Lihi_Token_Store();
        $this->assertTrue( $store->acquire_lock() );

        $new_owner = time() . ':' . str_repeat( 'b', 32 );
        $this->replaceOptionDirect( self::LOCK_KEY, $new_owner );

        $method = new \ReflectionMethod(
            Lihi_Token_Store::class,
            'persist_tuple_if_guarded'
        );
        $method->setAccessible( true );

        $this->assertFalse(
            $method->invoke( $store, $this->canonicalTuple() )
        );
        $this->assertSame(
            $replacement_raw,
            $this->readOptionDirect( self::OPTION_KEY )
        );
        $this->assertSame(
            $new_owner,
            $this->readOptionDirect( self::LOCK_KEY )
        );
    }

    /**
     * Return a valid tuple whose raw representation differs from the store's
     * normalized shape by case, an extra key, and key order.
     *
     * @return array<string, string>
     */
    private function nonCanonicalTuple(): array
    {
        return [
            'refresh_token' => self::REFRESH_TOKEN,
            'extra_key'     => 'must-not-be-normalized-before-cas',
            'access_token'  => self::ACCESS_TOKEN,
            'uuid'          => strtoupper( self::UUID ),
            'email'         => 'USER@EXAMPLE.COM',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function canonicalTuple(): array
    {
        return [
            'email'         => 'user@example.com',
            'uuid'          => self::UUID,
            'access_token'  => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
        ];
    }

    private function replaceOptionDirect(
        string $option_name,
        string $option_value
    ): void {
        global $wpdb;

        $this->deleteOptionDirect( $option_name );

        $inserted = $wpdb->insert(
            $wpdb->options,
            [
                'option_name'  => $option_name,
                'option_value' => $option_value,
                'autoload'     => 'no',
            ],
            [ '%s', '%s', '%s' ]
        );

        $this->assertSame( 1, $inserted );
        $this->clearOptionCache( $option_name );
    }

    private function deleteOptionDirect( string $option_name ): void
    {
        global $wpdb;

        $wpdb->delete(
            $wpdb->options,
            [ 'option_name' => $option_name ],
            [ '%s' ]
        );
        $this->clearOptionCache( $option_name );
    }

    private function readOptionDirect( string $option_name ): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $option_name
            )
        );

        return is_string( $value ) ? $value : null;
    }

    private function optionExistsDirect( string $option_name ): bool
    {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                $option_name
            )
        );

        return (int) $count === 1;
    }

    private function clearOptionCache( string $option_name ): void
    {
        wp_cache_delete( $option_name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }
}
