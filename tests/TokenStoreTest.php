<?php

namespace Lihi\ShortUrl\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lihi\ShortUrl\Lihi_Server_Exception;
use Lihi\ShortUrl\Lihi_Token_Store;
use PHPUnit\Framework\TestCase;

class TokenStoreTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const EPOCH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var mixed */
    private $originalWpdb;

    /** @var object */
    private $wpdbStub;

    /** @var array<int, array{string, string}> */
    private array $cacheDeletes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdbStub = new class {
            public string $options = 'wp_options';
            public ?string $epoch = null;
            public ?string $tokens = null;
            public ?string $directLock = null;
            public string $preparedOption = '';
            public array $preparedArgs = [];
            public array $preparedCalls = [];
            public string $last_error = '';

            /** @var callable|null */
            public $queryCallback;

            /** @var callable|null */
            public $getVarCallback;

            public function prepare($query, ...$args)
            {
                $this->preparedArgs = $args;
                $this->preparedOption = isset($args[0])
                    ? (string) $args[0]
                    : '';
                $this->preparedCalls[] = [
                    'query' => $query,
                    'args'  => $args,
                ];

                return $query;
            }

            public function get_var($query)
            {
                $this->last_error = '';

                if (is_callable($this->getVarCallback)) {
                    $value = call_user_func(
                        $this->getVarCallback,
                        $this->preparedOption,
                        $query
                    );
                } else {
                    $value = $this->rawOption($this->preparedOption);
                }

                return is_string($value) ? 'x' . $value : null;
            }

            public function query($query)
            {
                $this->last_error = '';

                if (is_callable($this->queryCallback)) {
                    return call_user_func(
                        $this->queryCallback,
                        $query,
                        $this->preparedArgs
                    );
                }

                if (stripos($query, 'INSERT IGNORE') !== false) {
                    $option = (string) ($this->preparedArgs[0] ?? '');
                    if ($this->rawOption($option) !== null) {
                        return 0;
                    }

                    $this->setRawOption(
                        $option,
                        (string) ($this->preparedArgs[1] ?? '')
                    );
                    return 1;
                }

                if (
                    stripos($query, 'INSERT INTO') !== false
                    && stripos($query, 'ON DUPLICATE KEY UPDATE') !== false
                ) {
                    if (
                        $this->epoch !== ($this->preparedArgs[4] ?? null)
                        || $this->directLock !== ($this->preparedArgs[6] ?? null)
                    ) {
                        return 0;
                    }

                    $serialized = (string) ($this->preparedArgs[1] ?? '');
                    $existed = $this->tokens !== null;
                    $changed = $this->tokens !== $serialized;
                    $this->tokens = $serialized;

                    if (!$existed) {
                        return 1;
                    }

                    return $changed ? 2 : 0;
                }

                if (stripos($query, 'UPDATE') !== false) {
                    $renewed = (string) ($this->preparedArgs[0] ?? '');
                    $option  = (string) ($this->preparedArgs[1] ?? '');
                    $owned   = (string) ($this->preparedArgs[2] ?? '');

                    if (
                        $option !== 'lihi_auth_tokens_lock'
                        || $this->directLock === null
                        || !hash_equals($this->directLock, $owned)
                    ) {
                        return 0;
                    }

                    $this->directLock = $renewed;
                    return 1;
                }

                if (stripos($query, 'DELETE FROM') !== false) {
                    $option = (string) ($this->preparedArgs[0] ?? '');
                    $stored = $this->rawOption($option);
                    if ($stored === null) {
                        return 0;
                    }

                    if (
                        array_key_exists(1, $this->preparedArgs)
                        && !hash_equals(
                            $stored,
                            (string) $this->preparedArgs[1]
                        )
                    ) {
                        return 0;
                    }

                    $this->setRawOption($option, null);
                    return 1;
                }

                return false;
            }

            public function rawOption(string $option): ?string
            {
                if ($option === 'lihi_auth_epoch') {
                    return $this->epoch;
                }
                if ($option === 'lihi_auth_tokens') {
                    return $this->tokens;
                }
                if ($option === 'lihi_auth_tokens_lock') {
                    return $this->directLock;
                }

                return null;
            }

            public function setRawOption(string $option, ?string $value): void
            {
                if ($option === 'lihi_auth_epoch') {
                    $this->epoch = $value;
                } elseif ($option === 'lihi_auth_tokens') {
                    $this->tokens = $value;
                } elseif ($option === 'lihi_auth_tokens_lock') {
                    $this->directLock = $value;
                }
            }
        };
        $this->wpdbStub->epoch = self::EPOCH;
        $GLOBALS['wpdb'] = $this->wpdbStub;

        Functions\when('esc_html')->returnArg(1);
        Functions\when('usleep')->justReturn(null);
        Functions\when('wp_cache_delete')->alias(
            function ($key, $group) {
                $this->cacheDeletes[] = [(string) $key, (string) $group];
                return true;
            }
        );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function tuple(array $changes = []): array
    {
        return array_merge([
            'email'         => 'user@example.com',
            'uuid'          => self::UUID,
            'access_token'  => 'access',
            'refresh_token' => 'refresh',
        ], $changes);
    }

    /**
     * @param array<string, mixed> $tuple
     */
    private function storeTuple(array $tuple): void
    {
        $this->wpdbStub->tokens = \maybe_serialize($tuple);
    }

    private function markStoreAsLockOwner(
        Lihi_Token_Store $store,
        string $value = 'test-lock-owner'
    ): void {
        $this->wpdbStub->directLock = $value;
        $property = new \ReflectionProperty(
            Lihi_Token_Store::class,
            'lock_value'
        );
        $property->setAccessible(true);
        $property->setValue($store, $value);
    }

    /**
     * @return array{query: string, args: array}
     */
    private function preparedCallContaining(string $needle): array
    {
        foreach ($this->wpdbStub->preparedCalls as $call) {
            if (strpos($call['query'], $needle) !== false) {
                return $call;
            }
        }

        $this->fail('Expected a prepared query containing: ' . $needle);
    }

    /**
     * @param list<string> $optionNames
     */
    private function assertCacheInvalidations(array $optionNames): void
    {
        $expected = [];
        foreach ($optionNames as $optionName) {
            $expected[] = [$optionName, 'options'];
            $expected[] = ['notoptions', 'options'];
        }

        $this->assertSame($expected, $this->cacheDeletes);
    }

    /** @test */
    public function get_returns_one_normalized_complete_tuple(): void
    {
        $sessionId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $this->storeTuple([
            'email'         => 'USER@EXAMPLE.COM',
            'uuid'          => $sessionId,
            'access_token'  => 'access',
            'refresh_token' => 'refresh',
            'ignored'       => 'value',
        ]);
        Functions\expect('get_option')->never();

        $this->assertSame(
            $this->tuple(['uuid' => $sessionId]),
            (new Lihi_Token_Store())->get()
        );
    }

    /** @test */
    public function get_memoizes_credentials_until_a_fresh_read_is_requested(): void
    {
        $stored = \maybe_serialize($this->tuple([
            'email'         => 'first@example.com',
            'access_token'  => 'first-access',
            'refresh_token' => 'first-refresh',
        ]));
        $this->wpdbStub->getVarCallback = function ($option) use (&$stored) {
            return $option === 'lihi_auth_epoch'
                ? self::EPOCH
                : $stored;
        };
        Functions\expect('get_option')->never();

        $store = new Lihi_Token_Store();
        $this->assertSame('first-access', $store->get()['access_token']);

        $stored = \maybe_serialize($this->tuple([
            'email'         => 'second@example.com',
            'uuid'          => '22222222-2222-4222-8222-222222222222',
            'access_token'  => 'second-access',
            'refresh_token' => 'second-refresh',
        ]));

        $this->assertSame('first-access', $store->get()['access_token']);
        $this->assertSame('second-access', $store->get_fresh()['access_token']);
        $this->assertSame(
            '22222222-2222-4222-8222-222222222222',
            $store->get()['uuid']
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public function invalidTupleProvider(): array
    {
        return [
            'missing' => [false],
            'not an array' => ['token'],
            'missing refresh' => [[
                'email'        => 'user@example.com',
                'uuid'         => self::UUID,
                'access_token' => 'access',
            ]],
            'invalid uuid' => [$this->tuple(['uuid' => 'invalid identifier!'])],
            'empty email' => [$this->tuple(['email' => ''])],
            'invalid email' => [$this->tuple(['email' => 'not-an-email'])],
            'empty access' => [$this->tuple(['access_token' => ''])],
            'empty refresh' => [$this->tuple(['refresh_token' => " \t"])],
        ];
    }

    /**
     * @dataProvider invalidTupleProvider
     * @test
     *
     * @param mixed $stored
     */
    public function get_returns_false_for_incomplete_or_invalid_tuple($stored): void
    {
        $this->wpdbStub->tokens = $stored === false
            ? null
            : \maybe_serialize($stored);

        $this->assertFalse((new Lihi_Token_Store())->get());
    }

    /** @test */
    public function direct_database_read_failure_throws(): void
    {
        $this->wpdbStub->getVarCallback = function () {
            $this->wpdbStub->last_error = 'read failed';
            return null;
        };

        $this->expectException(Lihi_Server_Exception::class);
        new Lihi_Token_Store();
    }

    /** @test */
    public function set_uses_one_atomic_guarded_upsert_and_invalidates_all_caches(): void
    {
        Functions\expect('add_option')->never();
        Functions\expect('update_option')->never();

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);
        $store->set(
            'USER@EXAMPLE.COM',
            self::UUID,
            'access',
            'refresh'
        );

        $this->assertSame(
            \maybe_serialize($this->tuple()),
            $this->wpdbStub->tokens
        );
        $guarded = $this->preparedCallContaining('ON DUPLICATE KEY UPDATE');
        $this->assertStringContainsString(
            'CAST(auth_epoch.option_value AS BINARY) = CAST(%s AS BINARY)',
            $guarded['query']
        );
        $this->assertStringContainsString(
            'CAST(auth_lock.option_value AS BINARY) = CAST(%s AS BINARY)',
            $guarded['query']
        );
        $this->assertStringNotContainsString(
            'CAST(auth_lock.option_name AS BINARY)',
            $guarded['query']
        );
        $this->assertSame([
            'lihi_auth_tokens',
            \maybe_serialize($this->tuple()),
            'no',
            'lihi_auth_epoch',
            self::EPOCH,
            'lihi_auth_tokens_lock',
            'test-lock-owner',
        ], $guarded['args']);
        $this->assertCacheInvalidations(['lihi_auth_tokens']);
    }

    /** @test */
    public function set_refuses_to_write_while_authentication_is_disabled(): void
    {
        $this->wpdbStub->epoch = null;

        $this->expectException(Lihi_Server_Exception::class);
        (new Lihi_Token_Store())->set(
            'user@example.com',
            self::UUID,
            'access',
            'refresh'
        );
    }

    /** @test */
    public function set_requires_the_current_auth_lock_owner(): void
    {
        $this->expectException(Lihi_Server_Exception::class);
        $this->expectExceptionMessage('lock ownership');
        (new Lihi_Token_Store())->set(
            'user@example.com',
            self::UUID,
            'access',
            'refresh'
        );
    }

    /** @test */
    public function guarded_upsert_does_not_overwrite_after_epoch_changes(): void
    {
        $newer = \maybe_serialize($this->tuple([
            'access_token'  => 'newer-access',
            'refresh_token' => 'newer-refresh',
        ]));
        $this->wpdbStub->tokens = $newer;
        $this->wpdbStub->queryCallback = function ($query) {
            $this->assertStringContainsString(
                'ON DUPLICATE KEY UPDATE',
                $query
            );
            $this->wpdbStub->epoch = str_repeat('b', 64);
            return 0;
        };

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);

        try {
            $store->set(
                'user@example.com',
                self::UUID,
                'old-access',
                'old-refresh'
            );
            $this->fail('Expected the changed epoch to reject persistence.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertStringContainsString(
                'Unable to persist',
                $error->getMessage()
            );
        }

        $this->assertSame($newer, $this->wpdbStub->tokens);
        $this->assertCacheInvalidations(['lihi_auth_tokens']);
    }

    /** @test */
    public function guarded_upsert_does_not_overwrite_after_lock_changes(): void
    {
        $newer = \maybe_serialize($this->tuple([
            'access_token'  => 'newer-access',
            'refresh_token' => 'newer-refresh',
        ]));
        $this->wpdbStub->tokens = $newer;
        $this->wpdbStub->queryCallback = function ($query) {
            $this->assertStringContainsString(
                'ON DUPLICATE KEY UPDATE',
                $query
            );
            $this->wpdbStub->directLock = 'replacement-owner';
            return 0;
        };

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);

        try {
            $store->set(
                'user@example.com',
                self::UUID,
                'old-access',
                'old-refresh'
            );
            $this->fail('Expected the changed lock to reject persistence.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertStringContainsString(
                'Unable to persist',
                $error->getMessage()
            );
        }

        $this->assertSame($newer, $this->wpdbStub->tokens);
        $this->assertCacheInvalidations(['lihi_auth_tokens']);
    }

    /** @test */
    public function set_throws_when_guarded_database_query_fails(): void
    {
        $this->wpdbStub->queryCallback = function () {
            $this->wpdbStub->last_error = 'write failed';
            return false;
        };

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);

        $this->expectException(Lihi_Server_Exception::class);
        $store->set(
            'user@example.com',
            self::UUID,
            'access',
            'refresh'
        );
    }

    /** @test */
    public function set_throws_when_database_readback_does_not_match(): void
    {
        $this->wpdbStub->queryCallback = function ($query) {
            $this->assertStringContainsString(
                'ON DUPLICATE KEY UPDATE',
                $query
            );
            return 1;
        };

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);

        $this->expectException(Lihi_Server_Exception::class);
        $store->set(
            'user@example.com',
            self::UUID,
            'access',
            'refresh'
        );
    }

    /** @test */
    public function old_request_epoch_stays_disabled_after_a_fast_reactivation(): void
    {
        $oldRequestStore = new Lihi_Token_Store();
        $this->wpdbStub->epoch = str_repeat('b', 64);

        $this->expectException(Lihi_Server_Exception::class);
        $oldRequestStore->ensure_enabled();
    }

    /** @test */
    public function disable_and_enable_rotate_a_non_autoloaded_activation_epoch(): void
    {
        $store = new Lihi_Token_Store();
        $store->disable();
        $store->enable();

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $this->wpdbStub->epoch
        );
        $this->assertNotSame(self::EPOCH, $this->wpdbStub->epoch);
        $this->assertCacheInvalidations([
            'lihi_auth_epoch',
            'lihi_auth_epoch',
        ]);
    }

    /** @test */
    public function empty_epoch_row_can_be_removed_and_reenabled(): void
    {
        $this->wpdbStub->epoch = '';
        $store = new Lihi_Token_Store();

        $store->disable();
        $store->enable();

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $this->wpdbStub->epoch
        );
        $this->assertCacheInvalidations([
            'lihi_auth_epoch',
            'lihi_auth_epoch',
        ]);
    }

    /** @test */
    public function delete_and_has_credentials_use_the_raw_tuple_option(): void
    {
        $this->storeTuple($this->tuple());
        $store = new Lihi_Token_Store();

        $this->assertTrue($store->has_credentials());
        $store->delete();

        $this->assertNull($this->wpdbStub->tokens);
        $delete = $this->preparedCallContaining('DELETE FROM');
        $this->assertSame(['lihi_auth_tokens'], $delete['args']);
        $this->assertCacheInvalidations(['lihi_auth_tokens']);
    }

    /** @test */
    public function delete_throws_when_database_query_fails(): void
    {
        $this->storeTuple($this->tuple());
        $this->wpdbStub->queryCallback = function ($query) {
            $this->assertStringContainsString('DELETE FROM', $query);
            return false;
        };

        $this->expectException(Lihi_Server_Exception::class);
        (new Lihi_Token_Store())->delete();
    }

    /** @test */
    public function delete_throws_when_database_readback_fails(): void
    {
        $this->storeTuple($this->tuple());
        $deleted = false;
        $this->wpdbStub->queryCallback = function ($query) use (&$deleted) {
            $this->assertStringContainsString('DELETE FROM', $query);
            $this->wpdbStub->tokens = null;
            $deleted = true;
            return 1;
        };
        $this->wpdbStub->getVarCallback = function ($option) use (&$deleted) {
            if ($deleted && $option === 'lihi_auth_tokens') {
                $this->wpdbStub->last_error = 'readback failed';
                return null;
            }

            return $this->wpdbStub->rawOption($option);
        };

        $this->expectException(Lihi_Server_Exception::class);
        (new Lihi_Token_Store())->delete();
    }

    /** @test */
    public function conditional_delete_does_not_clear_a_newer_tuple(): void
    {
        $this->storeTuple($this->tuple([
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
        ]));

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);
        $deleted = $store->delete_if_access_token('old-access');

        $this->assertFalse($deleted);
        $this->assertNotNull($this->wpdbStub->tokens);
        $this->assertSame([], $this->cacheDeletes);
    }

    /** @test */
    public function conditional_delete_uses_the_exact_raw_noncanonical_tuple(): void
    {
        $rawTuple = [
            'refresh_token' => 'old-refresh',
            'ignored'       => 'preserved-in-raw-cas',
            'access_token'  => 'old-access',
            'uuid'          => strtoupper(self::UUID),
            'email'         => 'USER@EXAMPLE.COM',
        ];
        $raw = \maybe_serialize($rawTuple);
        $this->wpdbStub->tokens = $raw;

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);
        $deleted = $store->delete_if_access_token('old-access');

        $this->assertTrue($deleted);
        $this->assertNull($this->wpdbStub->tokens);
        $delete = $this->preparedCallContaining('DELETE FROM');
        $this->assertStringContainsString(
            'CAST(option_value AS BINARY) = CAST(%s AS BINARY)',
            $delete['query']
        );
        $this->assertStringNotContainsString(
            'CAST(option_name AS BINARY)',
            $delete['query']
        );
        $this->assertSame(['lihi_auth_tokens', $raw], $delete['args']);
        $this->assertCacheInvalidations(['lihi_auth_tokens']);
    }

    /** @test */
    public function conditional_delete_preserves_an_intervening_replacement(): void
    {
        $this->storeTuple($this->tuple([
            'access_token'  => 'old-access',
            'refresh_token' => 'old-refresh',
        ]));
        $replacement = \maybe_serialize($this->tuple([
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
        ]));
        $this->wpdbStub->queryCallback = function ($query) use ($replacement) {
            $this->assertStringContainsString('DELETE FROM', $query);
            $this->wpdbStub->tokens = $replacement;
            return 0;
        };

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);
        $deleted = $store->delete_if_access_token('old-access');

        $this->assertTrue($deleted);
        $this->assertSame($replacement, $this->wpdbStub->tokens);
        $this->assertSame([], $this->cacheDeletes);
    }

    /** @test */
    public function conditional_delete_throws_when_database_query_fails(): void
    {
        $this->storeTuple($this->tuple([
            'access_token' => 'old-access',
        ]));
        $this->wpdbStub->queryCallback = function () {
            return false;
        };

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);

        $this->expectException(Lihi_Server_Exception::class);
        $store->delete_if_access_token('old-access');
    }

    /** @test */
    public function uuid_conditional_delete_preserves_a_new_login_session(): void
    {
        $this->storeTuple($this->tuple([
            'uuid'          => '22222222-2222-4222-8222-222222222222',
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
        ]));

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);
        $deleted = $store->delete_if_uuid(self::UUID);

        $this->assertFalse($deleted);
        $this->assertNotNull($this->wpdbStub->tokens);
    }

    /** @test */
    public function uuid_conditional_delete_clears_the_matching_session(): void
    {
        $this->storeTuple($this->tuple([
            'access_token'  => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
        ]));

        $store = new Lihi_Token_Store();
        $this->markStoreAsLockOwner($store);
        $deleted = $store->delete_if_uuid(self::UUID);

        $this->assertTrue($deleted);
        $this->assertNull($this->wpdbStub->tokens);
        $this->assertCacheInvalidations(['lihi_auth_tokens']);
    }

    /** @test */
    public function lock_is_option_backed_non_autoloaded_and_owner_releases_it(): void
    {
        $store = new Lihi_Token_Store();

        $this->assertTrue($store->acquire_lock());
        $this->assertMatchesRegularExpression(
            '/^[0-9]+:[a-f0-9]{32}$/',
            $this->wpdbStub->directLock
        );
        $insert = $this->preparedCallContaining('INSERT IGNORE');
        $this->assertSame('lihi_auth_tokens_lock', $insert['args'][0]);
        $this->assertSame('no', $insert['args'][2]);

        $store->release_lock();

        $this->assertNull($this->wpdbStub->directLock);
        $this->assertCacheInvalidations([
            'lihi_auth_tokens_lock',
            'lihi_auth_tokens_lock',
        ]);
    }

    /** @test */
    public function lock_owner_can_atomically_renew_its_lease(): void
    {
        $store = new Lihi_Token_Store();
        $this->assertTrue($store->acquire_lock());
        $previous = $this->wpdbStub->directLock;
        $this->cacheDeletes = [];

        $this->assertTrue($store->renew_lock());

        $renewed = $this->wpdbStub->directLock;
        $this->assertNotSame($previous, $renewed);
        $this->assertMatchesRegularExpression(
            '/^[0-9]+:[a-f0-9]{32}$/',
            $renewed
        );
        $update = $this->preparedCallContaining('UPDATE wp_options');
        $this->assertStringContainsString(
            'CAST(option_value AS BINARY) = CAST(%s AS BINARY)',
            $update['query']
        );
        $this->assertSame([
            $renewed,
            'lihi_auth_tokens_lock',
            $previous,
        ], $update['args']);
        $this->assertCacheInvalidations(['lihi_auth_tokens_lock']);

        $store->release_lock();
        $this->assertNull($this->wpdbStub->directLock);
    }

    /** @test */
    public function failed_renewal_readback_immediately_releases_the_renewed_row(): void
    {
        $store = new Lihi_Token_Store();
        $this->assertTrue($store->acquire_lock());
        $previous = $this->wpdbStub->directLock;
        $misread  = time() . ':' . str_repeat('c', 32);
        $this->cacheDeletes = [];

        $this->wpdbStub->getVarCallback = function ($option) use (
            $previous,
            $misread
        ) {
            if ($option === 'lihi_auth_epoch') {
                return self::EPOCH;
            }
            if (
                $option === 'lihi_auth_tokens_lock'
                && $this->wpdbStub->directLock !== $previous
            ) {
                return $misread;
            }

            return $this->wpdbStub->rawOption($option);
        };

        $this->assertFalse($store->renew_lock());
        $this->assertNull($this->wpdbStub->directLock);
        $this->assertCacheInvalidations([
            'lihi_auth_tokens_lock',
            'lihi_auth_tokens_lock',
        ]);

        $store->release_lock();
        $this->assertNull($this->wpdbStub->directLock);
    }

    /** @test */
    public function lease_renewal_never_reclaims_a_foreign_replacement(): void
    {
        $store = new Lihi_Token_Store();
        $this->assertTrue($store->acquire_lock());

        $replacement = time() . ':' . str_repeat('b', 32);
        $this->wpdbStub->directLock = $replacement;

        $this->assertFalse($store->renew_lock());
        $store->release_lock();

        $this->assertSame($replacement, $this->wpdbStub->directLock);
    }

    /** @test */
    public function lock_acquisition_throws_when_the_insert_query_fails(): void
    {
        $this->wpdbStub->queryCallback = function ($query) {
            $this->assertStringContainsString('INSERT IGNORE', $query);
            return false;
        };

        $this->expectException(Lihi_Server_Exception::class);
        (new Lihi_Token_Store())->acquire_lock();
    }

    /** @test */
    public function lock_release_throws_when_the_exact_delete_query_fails(): void
    {
        $store = new Lihi_Token_Store();
        $this->assertTrue($store->acquire_lock());
        $this->wpdbStub->queryCallback = function ($query) {
            $this->assertStringContainsString('DELETE FROM', $query);
            return false;
        };

        $this->expectException(Lihi_Server_Exception::class);
        $store->release_lock();
    }

    /** @test */
    public function concurrent_lock_acquisition_uses_insert_ignore(): void
    {
        Functions\expect('add_option')->never();
        $first = new Lihi_Token_Store();
        $second = new Lihi_Token_Store();

        $this->assertTrue($first->acquire_lock());
        $firstOwner = $this->wpdbStub->directLock;
        $this->assertFalse($second->acquire_lock());

        $this->assertSame($firstOwner, $this->wpdbStub->directLock);
        $this->assertCacheInvalidations(['lihi_auth_tokens_lock']);
    }

    /** @test */
    public function lock_owner_never_deletes_a_foreign_replacement(): void
    {
        $store = new Lihi_Token_Store();
        $this->assertTrue($store->acquire_lock());

        $replacement = time() . ':' . str_repeat('b', 32);
        $this->wpdbStub->directLock = $replacement;
        $store->release_lock();

        $this->assertSame($replacement, $this->wpdbStub->directLock);
        $this->assertCacheInvalidations(['lihi_auth_tokens_lock']);
    }

    /** @test */
    public function stale_option_lock_can_be_replaced(): void
    {
        $this->wpdbStub->directLock =
            (time() - 240) . ':' . str_repeat('a', 32);

        $this->assertTrue((new Lihi_Token_Store())->acquire_lock());

        $this->assertMatchesRegularExpression(
            '/^[0-9]+:[a-f0-9]{32}$/',
            $this->wpdbStub->directLock
        );
        $this->assertStringNotContainsString(
            str_repeat('a', 32),
            $this->wpdbStub->directLock
        );
        $this->assertCacheInvalidations([
            'lihi_auth_tokens_lock',
            'lihi_auth_tokens_lock',
        ]);
    }

    /** @test */
    public function auth_and_lifecycle_wait_budgets_cover_one_http_leg_safely(): void
    {
        $store = new \ReflectionClass(Lihi_Token_Store::class);
        $transition = $store->getMethod('transition_activation');
        $wait = $store->getMethod('wait_for_access_token_change');
        $flush = $store->getMethod('flush');
        $leaseTtl = $store->getConstant('LOCK_TTL');
        $lifecycleWait = $transition->getParameters()[1]->getDefaultValue();

        $this->assertSame(
            18_000_000,
            Lihi_Token_Store::AUTH_LOCK_WAIT_US
        );
        $this->assertSame(
            Lihi_Token_Store::AUTH_LOCK_WAIT_US,
            $wait->getParameters()[1]->getDefaultValue()
        );
        $this->assertSame(
            Lihi_Token_Store::AUTH_LOCK_WAIT_US,
            $flush->getParameters()[0]->getDefaultValue()
        );
        $this->assertSame(20, $leaseTtl);
        $this->assertSame(22_000_000, $lifecycleWait);
        $this->assertGreaterThan($leaseTtl * 1_000_000, $lifecycleWait);
        $this->assertLessThan(30_000_000, $lifecycleWait);
    }

    /** @test */
    public function lifecycle_transition_reclaims_an_orphan_within_its_wait_budget(): void
    {
        $this->storeTuple($this->tuple());
        $this->wpdbStub->directLock =
            (time() - 21) . ':' . str_repeat('a', 32);

        $store = new Lihi_Token_Store();
        $store->transition_activation(true);

        $this->assertNull($this->wpdbStub->tokens);
        $this->assertNull($this->wpdbStub->directLock);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $this->wpdbStub->epoch
        );
        $this->assertNotSame(self::EPOCH, $this->wpdbStub->epoch);
    }

    /** @test */
    public function malformed_empty_lock_row_can_be_replaced(): void
    {
        $this->wpdbStub->directLock = '';

        $this->assertTrue((new Lihi_Token_Store())->acquire_lock());

        $this->assertMatchesRegularExpression(
            '/^[0-9]+:[a-f0-9]{32}$/',
            $this->wpdbStub->directLock
        );
        $this->assertCacheInvalidations([
            'lihi_auth_tokens_lock',
            'lihi_auth_tokens_lock',
        ]);
    }

    /** @test */
    public function wait_returns_credentials_when_another_request_rotates_access(): void
    {
        $reads = 0;
        $old = \maybe_serialize($this->tuple([
            'access_token'  => 'old-access',
            'refresh_token' => 'old-refresh',
        ]));
        $new = \maybe_serialize($this->tuple([
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
        ]));
        $lock = time() . ':' . str_repeat('a', 32);
        $this->wpdbStub->getVarCallback = function ($option) use (
            &$reads,
            $old,
            $new,
            $lock
        ) {
            if ($option === 'lihi_auth_epoch') {
                return self::EPOCH;
            }
            if ($option === 'lihi_auth_tokens_lock') {
                return $lock;
            }

            $reads++;
            return $reads === 1 ? $old : $new;
        };

        $tokens = (new Lihi_Token_Store())
            ->wait_for_access_token_change('old-access', 300_000);

        $this->assertSame('new-access', $tokens['access_token']);
    }

    /** @test */
    public function wait_stops_when_lock_is_released_without_rotation(): void
    {
        $old = \maybe_serialize($this->tuple([
            'access_token'  => 'old-access',
            'refresh_token' => 'old-refresh',
        ]));
        $this->wpdbStub->getVarCallback = function ($option) use ($old) {
            if ($option === 'lihi_auth_epoch') {
                return self::EPOCH;
            }
            if ($option === 'lihi_auth_tokens_lock') {
                return null;
            }

            return $old;
        };

        $this->assertFalse(
            (new Lihi_Token_Store())
                ->wait_for_access_token_change('old-access', 300_000)
        );
    }

    /** @test */
    public function wait_stops_as_soon_as_the_observed_lease_is_stale(): void
    {
        $old = \maybe_serialize($this->tuple([
            'access_token'  => 'old-access',
            'refresh_token' => 'old-refresh',
        ]));
        $lockReads = 0;
        $this->wpdbStub->getVarCallback = function ($option) use (
            $old,
            &$lockReads
        ) {
            if ($option === 'lihi_auth_epoch') {
                return self::EPOCH;
            }
            if ($option === 'lihi_auth_tokens_lock') {
                $lockReads++;
                return (time() - 60) . ':' . str_repeat('a', 32);
            }

            return $old;
        };

        $this->assertFalse(
            (new Lihi_Token_Store())
                ->wait_for_access_token_change('old-access', 3_000_000)
        );
        $this->assertSame(1, $lockReads);
    }

    /** @test */
    public function flush_deletes_credentials_while_holding_owned_lock(): void
    {
        $this->storeTuple($this->tuple());

        (new Lihi_Token_Store())->flush();

        $this->assertNull($this->wpdbStub->tokens);
        $this->assertNull($this->wpdbStub->directLock);
        $this->assertCacheInvalidations([
            'lihi_auth_tokens_lock',
            'lihi_auth_tokens',
            'lihi_auth_tokens_lock',
        ]);
    }

    /** @test */
    public function flush_never_deletes_without_obtaining_the_lock(): void
    {
        $this->storeTuple($this->tuple());
        $this->wpdbStub->directLock =
            time() . ':' . str_repeat('a', 32);

        try {
            (new Lihi_Token_Store())->flush(200_000);
            $this->fail('Expected a held auth lock to reject the flush.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertStringContainsString(
                'Unable to clear',
                $error->getMessage()
            );
        }

        $this->assertNotNull($this->wpdbStub->tokens);
        $this->assertSame([], $this->cacheDeletes);
    }
}
