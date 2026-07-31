<?php

namespace Lihi\ShortUrl\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lihi\ShortUrl\Lihi_Authentication_Busy_Exception;
use Lihi\ShortUrl\Lihi_Client_Interface;
use Lihi\ShortUrl\Lihi_Not_Found_Exception;
use Lihi\ShortUrl\Lihi_Rate_Limit_Exception;
use Lihi\ShortUrl\Lihi_Refresh_Token_Invalid_Exception;
use Lihi\ShortUrl\Lihi_Server_Exception;
use Lihi\ShortUrl\Lihi_Service;
use Lihi\ShortUrl\Lihi_Token_Store;
use Lihi\ShortUrl\Lihi_Token_Invalid_Exception;
use Lihi\ShortUrl\Lihi_User_Invalid_Exception;
use Mockery;
use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('esc_html__')->returnArg(1);
        Functions\when('usleep')->justReturn(null);
        Functions\when('Lihi\ShortUrl\lihi_site_host')
            ->justReturn('example.com');
        Functions\when('Lihi\ShortUrl\lihi_resolve_url')
            ->alias(function ($itemId, $type) {
                if ($type === 'attachment') {
                    return 'https://example.com/uploads/' . $itemId . '.jpg';
                }

                return 'https://example.com/?p=' . $itemId;
            });
        Functions\when('add_query_arg')
            ->alias(function (array $params, string $url): string {
                $separator = strpos($url, '?') === false ? '?' : '&';
                return $url . $separator . http_build_query($params);
            });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return \Mockery\MockInterface&Lihi_Client_Interface
     */
    private function client(): Lihi_Client_Interface
    {
        return Mockery::mock(Lihi_Client_Interface::class);
    }

    /**
     * @return \Mockery\MockInterface&Lihi_Token_Store
     */
    private function store(): Lihi_Token_Store
    {
        $store = Mockery::mock(Lihi_Token_Store::class);
        $store->shouldReceive('ensure_enabled')->byDefault();
        $store->shouldReceive('renew_lock')->byDefault()->andReturn(true);
        $store->shouldReceive('get_fresh')
            ->byDefault()
            ->andReturnUsing(function () use ($store) {
                return $store->get();
            });

        return $store;
    }

    private function service(
        Lihi_Client_Interface $client,
        Lihi_Token_Store $store
    ): Lihi_Service {
        return new Lihi_Service($client, $store);
    }

    /**
     * @return array{
     *   uuid: string,
     *   access_token: string,
     *   refresh_token: string,
     * }
     */
    private function credentials(
        string $access = 'old-access',
        string $refresh = 'old-refresh',
        string $uuid = self::UUID,
        string $email = 'user@example.com'
    ): array {
        return [
            'email'         => $email,
            'uuid'          => $uuid,
            'access_token'  => $access,
            'refresh_token' => $refresh,
        ];
    }

    private function findResponse(string $site): array
    {
        return [
            'result' => true,
            'data'   => ['site' => $site],
        ];
    }

    /** @test */
    public function login_performs_pkce_exchange_and_atomically_stores_credentials(): void
    {
        $client    = $this->client();
        $store     = $this->store();
        $challenge = '';
        $verifier  = '';
        $events    = [];

        $client->shouldReceive('login')
            ->once()
            ->with(
                'user@example.com',
                'password',
                Mockery::type('string')
            )
            ->andReturnUsing(
                function ($email, $password, $value) use (&$challenge, &$events) {
                    $events[] = 'login';
                    $challenge = $value;
                    return ['code' => str_repeat('c', 43)];
                }
            );
        $client->shouldReceive('exchange_authorization_code')
            ->once()
            ->with(str_repeat('c', 43), Mockery::type('string'))
            ->andReturnUsing(function ($code, $value) use (&$verifier, &$events) {
                $events[] = 'exchange';
                $verifier = $value;
                return [
                    'uuid'          => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    'token'         => 'access-token',
                    'refresh_token' => 'refresh-token',
                ];
            });

        $store->shouldReceive('acquire_lock')
            ->once()
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'acquire';
                return true;
            });
        $store->shouldReceive('renew_lock')
            ->twice()
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'renew';
                return true;
            });
        $store->shouldReceive('set')
            ->once()
            ->with(
                'user@example.com',
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'access-token',
                'refresh-token'
            )
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'set';
            });
        $store->shouldReceive('release_lock')
            ->once()
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'release';
            });

        $result = $this->service($client, $store)
            ->login('user@example.com', 'password');

        $expectedChallenge = rtrim(
            strtr(
                base64_encode(hash('sha256', $verifier, true)),
                '+/',
                '-_'
            ),
            '='
        );
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]{43}$/',
            $verifier
        );
        $this->assertSame($expectedChallenge, $challenge);
        $this->assertSame([
            'email'         => 'user@example.com',
            'uuid'          => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'access_token'  => 'access-token',
            'refresh_token' => 'refresh-token',
        ], $result);
        $this->assertSame(
            ['acquire', 'login', 'renew', 'exchange', 'renew', 'set', 'release'],
            $events
        );
    }

    /** @test */
    public function login_stops_when_the_auth_lease_cannot_be_renewed(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $client->shouldReceive('login')
            ->once()
            ->andReturn(['code' => str_repeat('c', 43)]);
        $client->shouldNotReceive('exchange_authorization_code');
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('renew_lock')->once()->andReturn(false);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');

        $this->expectException(Lihi_Authentication_Busy_Exception::class);
        $this->service($client, $store)
            ->login('user@example.com', 'password');
    }

    /** @test */
    public function login_stops_after_one_http_leg_when_activation_is_disabled(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $checks = 0;
        $failure = new Lihi_Server_Exception('authentication disabled');

        $store->shouldReceive('ensure_enabled')
            ->times(3)
            ->andReturnUsing(function () use (&$checks, $failure) {
                $checks++;
                if ($checks === 3) {
                    throw $failure;
                }
            });
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldNotReceive('renew_lock');
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');
        $client->shouldReceive('login')
            ->once()
            ->andReturn(['code' => str_repeat('c', 43)]);
        $client->shouldNotReceive('exchange_authorization_code');

        try {
            $this->service($client, $store)
                ->login('user@example.com', 'password');
            $this->fail('Expected the activation fence to stop Login.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertSame($failure, $error);
        }
    }

    /** @test */
    public function login_reports_lock_contention_as_authentication_busy(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $attempts = 0;

        $store->shouldReceive('acquire_lock')
            ->andReturnUsing(function () use (&$attempts) {
                $attempts++;
                return false;
            });
        $store->shouldNotReceive('set');
        $client->shouldNotReceive('login');
        $client->shouldNotReceive('exchange_authorization_code');

        try {
            $this->service($client, $store)
                ->login('user@example.com', 'password');
            $this->fail('Expected authentication lock contention.');
        } catch (Lihi_Authentication_Busy_Exception $error) {
            $this->assertSame(181, $attempts);
        }
    }

    /** @test */
    public function login_never_starts_when_the_plugin_is_deactivated(): void
    {
        $client  = $this->client();
        $store   = $this->store();
        $failure = new Lihi_Server_Exception('authentication disabled');

        $store->shouldReceive('ensure_enabled')
            ->once()
            ->andThrow($failure);
        $store->shouldNotReceive('acquire_lock');
        $store->shouldNotReceive('set');
        $client->shouldNotReceive('login');
        $client->shouldNotReceive('exchange_authorization_code');

        try {
            $this->service($client, $store)
                ->login('user@example.com', 'password');
            $this->fail('Expected deactivated authentication to fail.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertSame($failure, $error);
        }
    }

    /** @test */
    public function login_never_stores_when_authorization_code_is_missing(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $client->shouldReceive('login')
            ->once()
            ->andReturn([]);
        $client->shouldNotReceive('exchange_authorization_code');
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');

        $this->expectException(Lihi_Server_Exception::class);
        $this->service($client, $store)
            ->login('user@example.com', 'password');
    }

    /** @test */
    public function login_rejects_a_malformed_authorization_code(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $client->shouldReceive('login')
            ->once()
            ->andReturn(['code' => 'not-base64url-code']);
        $client->shouldNotReceive('exchange_authorization_code');
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');

        $this->expectException(Lihi_Server_Exception::class);
        $this->expectExceptionMessage('Invalid authorization code');
        $this->service($client, $store)
            ->login('user@example.com', 'password');
    }

    /** @test */
    public function login_rejects_incomplete_token_exchange_without_overwriting_store(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $client->shouldReceive('login')
            ->once()
            ->andReturn(['code' => str_repeat('c', 43)]);
        $client->shouldReceive('exchange_authorization_code')
            ->once()
            ->andReturn([
                'uuid'  => self::UUID,
                'token' => 'access-only',
            ]);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');

        $this->expectException(Lihi_Server_Exception::class);
        $this->service($client, $store)
            ->login('user@example.com', 'password');
    }

    /** @test */
    public function login_releases_store_lock_when_persistence_fails(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $client->shouldReceive('login')
            ->once()
            ->andReturn(['code' => str_repeat('c', 43)]);
        $client->shouldReceive('exchange_authorization_code')
            ->once()
            ->andReturn([
                'uuid'          => self::UUID,
                'token'         => 'access',
                'refresh_token' => 'refresh',
            ]);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('set')
            ->once()
            ->andThrow(new Lihi_Server_Exception('database unavailable'));
        $store->shouldReceive('release_lock')->once();

        $this->expectException(Lihi_Server_Exception::class);
        $this->service($client, $store)
            ->login('user@example.com', 'password');
    }

    /** @test */
    public function login_releases_store_lock_when_the_auth_request_fails(): void
    {
        $client  = $this->client();
        $store   = $this->store();
        $failure = new Lihi_Server_Exception('network unavailable');

        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');
        $client->shouldReceive('login')
            ->once()
            ->andThrow($failure);
        $client->shouldNotReceive('exchange_authorization_code');

        try {
            $this->service($client, $store)
                ->login('user@example.com', 'password');
            $this->fail('Expected the Login request to fail.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertSame($failure, $error);
        }
    }

    /** @test */
    public function register_never_creates_a_local_session(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $client->shouldReceive('register')
            ->once()
            ->with('new@example.com', 'new-password');
        $store->shouldNotReceive('set');
        $store->shouldNotReceive('acquire_lock');

        $this->service($client, $store)
            ->register('new@example.com', 'new-password');

        $this->assertTrue(true);
    }

    /** @test */
    public function logout_revokes_the_current_session_and_deletes_the_tuple_under_lock(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $events = [];

        $store->shouldReceive('acquire_lock')
            ->once()
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'lock';
                return true;
            });
        $store->shouldReceive('get_fresh')
            ->once()
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'read';
                return $this->credentials();
            });
        $client->shouldReceive('logout')
            ->once()
            ->with('old-access')
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'remote';
            });
        $store->shouldReceive('delete')
            ->once()
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'delete';
            });
        $store->shouldReceive('release_lock')
            ->once()
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'release';
            });

        $this->service($client, $store)->logout();

        $this->assertSame(
            ['lock', 'read', 'remote', 'delete', 'release'],
            $events
        );
    }

    /** @test */
    public function logout_ignores_remote_failure_and_still_deletes_the_local_tuple(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('get_fresh')
            ->once()
            ->andReturn($this->credentials());
        $client->shouldReceive('logout')
            ->once()
            ->with('old-access')
            ->andThrow(new Lihi_Server_Exception('lihi unavailable'));
        $store->shouldReceive('delete')->once();
        $store->shouldReceive('release_lock')->once();

        $this->service($client, $store)->logout();

        $this->assertTrue(true);
    }

    /** @test */
    public function protected_call_requires_complete_credentials(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $store->shouldReceive('get')->once()->andReturn(false);
        $client->shouldNotReceive('get_profile');

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function profile_uses_persisted_access_and_returns_profile_data(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $tokens = $this->credentials();

        $store->shouldReceive('get')->once()->andReturn($tokens);
        $client->shouldReceive('get_profile')
            ->once()
            ->with('old-access', Mockery::type('callable'))
            ->andReturn([
                'result' => true,
                'data'   => [
                    'user_role'  => 'admin',
                    'group_name' => 'Marketing Team',
                ],
            ]);

        $profile = $this->service($client, $store)->get_profile();

        $this->assertSame('admin', $profile['user_role']);
        $this->assertSame('Marketing Team', $profile['group_name']);
    }

    /** @test */
    public function profile_normalizes_missing_nullable_fields(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $store->shouldReceive('get')
            ->once()
            ->andReturn($this->credentials());
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturn([
                'result' => true,
                'data'   => [],
            ]);

        $this->assertSame(
            [
                'user_role'  => null,
                'group_name' => null,
            ],
            $this->service($client, $store)->get_profile()
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public function malformedProfileProvider(): array
    {
        return [
            'data is not an object' => ['invalid'],
            'role is an array' => [[
                'user_role'  => ['admin'],
                'group_name' => null,
            ]],
            'group name is an array' => [[
                'user_role'  => 'admin',
                'group_name' => ['Marketing Team'],
            ]],
            'role is numeric' => [[
                'user_role'  => 1,
                'group_name' => null,
            ]],
        ];
    }

    /**
     * @dataProvider malformedProfileProvider
     * @test
     *
     * @param mixed $data
     */
    public function profile_rejects_values_that_are_not_nullable_strings(
        $data
    ): void {
        $client = $this->client();
        $store  = $this->store();

        $store->shouldReceive('get')
            ->once()
            ->andReturn($this->credentials());
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturn([
                'result' => true,
                'data'   => $data,
            ]);

        $this->expectException(Lihi_Server_Exception::class);
        $this->expectExceptionMessage('Invalid profile');
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function work_group_options_and_switch_use_persisted_access(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $tokens = $this->credentials();

        $store->shouldReceive('get')->twice()->andReturn($tokens);
        $client->shouldReceive('get_group_options')
            ->once()
            ->with('old-access', Mockery::type('callable'))
            ->andReturn([
                'result' => true,
                'data'   => [
                    'groups'   => [
                        ['id' => null, 'name' => 'My Group'],
                        ['id' => 42, 'name' => 'Marketing Team'],
                    ],
                    'group_id' => null,
                ],
            ]);
        $client->shouldReceive('switch_group')
            ->once()
            ->with('old-access', 42, Mockery::type('callable'))
            ->andReturn([
                'result' => true,
                'data'   => ['group_id' => 42],
            ]);

        $service = $this->service($client, $store);
        $options = $service->get_work_group_options();
        $groupId = $service->switch_work_group(42);

        $this->assertCount(2, $options['groups']);
        $this->assertNull($options['group_id']);
        $this->assertSame(42, $groupId);
    }

    /** @test */
    public function token_fallback_rotates_and_persists_both_tokens(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->times(3)
            ->andReturn($old, $old, $old);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('set')
            ->once()
            ->with(
                'user@example.com',
                self::UUID,
                'new-access',
                'new-refresh'
            );
        $store->shouldReceive('release_lock')->once();

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->with(self::UUID, 'old-refresh')
            ->andReturn([
                'uuid'          => self::UUID,
                'token'         => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $this->assertSame('old-access', $access);
                $replacement = $fallback($access);
                $this->assertSame('new-access', $replacement);

                return [
                    'result' => true,
                    'data'   => ['user_role' => 'user', 'group_name' => null],
                ];
            });

        $profile = $this->service($client, $store)->get_profile();
        $this->assertSame('user', $profile['user_role']);
    }

    /** @test */
    public function refresh_stops_before_renewal_when_activation_is_disabled(): void
    {
        $client  = $this->client();
        $store   = $this->store();
        $old     = $this->credentials();
        $checks  = 0;
        $failure = new Lihi_Server_Exception('authentication disabled');

        $store->shouldReceive('ensure_enabled')
            ->times(4)
            ->andReturnUsing(function () use (&$checks, $failure) {
                $checks++;
                if ($checks === 4) {
                    throw $failure;
                }
            });
        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldNotReceive('renew_lock');
        $store->shouldNotReceive('set');
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andReturn(true);
        $store->shouldReceive('release_lock')->once();

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andReturn([
                'uuid'          => self::UUID,
                'token'         => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        try {
            $this->service($client, $store)->get_profile();
            $this->fail('Expected the activation fence to stop Refresh.');
        } catch (Lihi_Token_Invalid_Exception $error) {
            $this->assertSame(4, $checks);
            $this->assertSame($failure, $error->getPrevious());
        }
    }

    /** @test */
    public function find_refresh_updates_access_used_by_later_create_endpoint(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->times(3)
            ->andReturn($old, $old, $old);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('set')
            ->once()
            ->with(
                'user@example.com',
                self::UUID,
                'new-access',
                'new-refresh'
            );
        $store->shouldReceive('release_lock')->once();

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andReturn([
                'uuid'          => self::UUID,
                'token'         => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);
        $client->shouldReceive('get_short_link')
            ->once()
            ->andReturnUsing(function ($access, $type, $itemId, $fallback) {
                $this->assertSame('old-access', $access);
                $this->assertSame('new-access', $fallback($access));
                return $this->findResponse('');
            });
        $client->shouldReceive('create_site')
            ->once()
            ->with(
                'new-access',
                Mockery::type('array'),
                Mockery::type('callable')
            )
            ->andReturn([
                'result' => true,
                'data'   => ['short_url' => 'https://lihi.io/new'],
            ]);

        $url = $this->service($client, $store)
            ->get_or_create_short_url(42, 'post');

        $this->assertSame('https://lihi.io/new', $url);
    }

    /** @test */
    public function fallback_reuses_access_already_rotated_by_another_request(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();
        $new    = $this->credentials('new-access', 'new-refresh');

        $store->shouldReceive('get')
            ->twice()
            ->andReturn($old, $new);
        $store->shouldNotReceive('acquire_lock');
        $store->shouldNotReceive('set');
        $client->shouldNotReceive('refresh_access_token');
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $this->assertSame('new-access', $fallback($access));
                return ['result' => true, 'data' => []];
            });

        $this->service($client, $store)->get_profile();
        $this->assertTrue(true);
    }

    /** @test */
    public function fallback_never_replays_an_old_request_under_a_new_login_session(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();
        $new    = $this->credentials(
            'other-access',
            'other-refresh',
            '22222222-2222-4222-8222-222222222222',
            'other@example.com'
        );

        $store->shouldReceive('get')
            ->times(3)
            ->andReturn($old, $new, $new);
        $store->shouldNotReceive('acquire_lock');
        $store->shouldNotReceive('set');
        $store->shouldNotReceive('delete_if_access_token');

        $client->shouldNotReceive('refresh_access_token');
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $this->assertSame('old-access', $access);
                $fallback($access);
                $this->fail('A changed login session must stop the request.');
            });

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->expectExceptionMessage('connection changed');
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function fallback_waits_for_lock_owner_and_reuses_its_rotation(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();
        $new    = $this->credentials('new-access', 'new-refresh');

        $store->shouldReceive('get')
            ->twice()
            ->andReturn($old, $old);
        $store->shouldReceive('acquire_lock')->once()->andReturn(false);
        $store->shouldReceive('wait_for_access_token_change')
            ->once()
            ->with('old-access')
            ->andReturn($new);
        $store->shouldNotReceive('set');
        $client->shouldNotReceive('refresh_access_token');
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $this->assertSame('new-access', $fallback($access));
                return ['result' => true, 'data' => []];
            });

        $this->service($client, $store)->get_profile();
        $this->assertTrue(true);
    }

    /** @test */
    public function waiting_fallback_rejects_a_tuple_from_a_new_login_session(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();
        $new    = $this->credentials(
            'other-access',
            'other-refresh',
            '22222222-2222-4222-8222-222222222222',
            'other@example.com'
        );

        $store->shouldReceive('get')
            ->times(3)
            ->andReturn($old, $old, $new);
        $store->shouldReceive('acquire_lock')->once()->andReturn(false);
        $store->shouldReceive('wait_for_access_token_change')
            ->once()
            ->with('old-access')
            ->andReturn($new);
        $store->shouldNotReceive('set');
        $store->shouldNotReceive('delete_if_access_token');
        $client->shouldNotReceive('refresh_access_token');
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->expectExceptionMessage('connection changed');
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function locked_fallback_rechecks_the_login_session_before_refresh(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();
        $new    = $this->credentials(
            'other-access',
            'other-refresh',
            '22222222-2222-4222-8222-222222222222',
            'other@example.com'
        );

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $new, $new);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');
        $store->shouldNotReceive('delete_if_access_token');
        $client->shouldNotReceive('refresh_access_token');
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->expectExceptionMessage('connection changed');
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function fallback_never_refreshes_independently_after_lock_timeout(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->twice()
            ->andReturn($old, $old);
        $store->shouldReceive('acquire_lock')
            ->twice()
            ->andReturn(false, false);
        $store->shouldReceive('wait_for_access_token_change')
            ->once()
            ->with('old-access')
            ->andReturn(false);
        $client->shouldNotReceive('refresh_access_token');
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        $this->expectException(Lihi_Authentication_Busy_Exception::class);
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function refresh_invalid_conditionally_clears_only_rejected_tuple(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andReturn(true);
        $store->shouldReceive('release_lock')->once();

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andThrow(
                new Lihi_Refresh_Token_Invalid_Exception(
                    'refresh token invalid'
                )
            );
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->service($client, $store)->get_profile();
    }

    /**
     * @return array<string, array{\Exception}>
     */
    public function refreshFailureProvider(): array
    {
        return [
            'network/server' => [new Lihi_Server_Exception('network down')],
            'rate limit' => [new Lihi_Rate_Limit_Exception('too many')],
        ];
    }

    /**
     * @dataProvider refreshFailureProvider
     * @test
     */
    public function refresh_failure_clears_matching_credentials_and_requires_login(
        \Exception $failure
    ): void {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('delete');
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andReturn(true);
        $store->shouldNotReceive('set');

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andThrow($failure);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        try {
            $this->service($client, $store)->get_profile();
            $this->fail('Expected refresh failure.');
        } catch (Lihi_Token_Invalid_Exception $error) {
            $this->assertStringContainsString(
                'Please sign in again',
                $error->getMessage()
            );
            $this->assertSame($failure, $error->getPrevious());
        }
    }

    /** @test */
    public function refresh_cleanup_failure_still_requires_login(): void
    {
        $client  = $this->client();
        $store   = $this->store();
        $old     = $this->credentials();
        $failure = new Lihi_Server_Exception('refresh network failure');

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andThrow(new Lihi_Server_Exception('cleanup failed'));
        $store->shouldReceive('release_lock')->once();

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andThrow($failure);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        try {
            $this->service($client, $store)->get_profile();
            $this->fail('Expected refresh failure.');
        } catch (Lihi_Token_Invalid_Exception $error) {
            $this->assertStringContainsString(
                'Please sign in again',
                $error->getMessage()
            );
            $this->assertSame($failure, $error->getPrevious());
        }
    }

    /** @test */
    public function refresh_release_failure_never_hides_the_required_relogin_error(): void
    {
        $client  = $this->client();
        $store   = $this->store();
        $old     = $this->credentials();
        $failure = new Lihi_Server_Exception('refresh network failure');

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andReturn(true);
        $store->shouldReceive('release_lock')
            ->once()
            ->andThrow(new Lihi_Server_Exception('release failed'));

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andThrow($failure);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        try {
            $this->service($client, $store)->get_profile();
            $this->fail('Expected refresh failure.');
        } catch (Lihi_Token_Invalid_Exception $error) {
            $this->assertStringContainsString(
                'Please sign in again',
                $error->getMessage()
            );
            $this->assertSame($failure, $error->getPrevious());
        }
    }

    /** @test */
    public function malformed_rotated_response_clears_now_unusable_old_tuple(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andReturn([
                'uuid'          => '22222222-2222-4222-8222-222222222222',
                'token'         => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->expectExceptionMessage('Please sign in again');
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function refresh_lease_loss_clears_the_old_session_and_requires_login(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('renew_lock')->once()->andReturn(false);
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andReturn(true);
        $store->shouldReceive('release_lock')->once();
        $store->shouldNotReceive('set');

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andReturn([
                'uuid'          => self::UUID,
                'token'         => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->expectExceptionMessage('Please sign in again');
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function failed_refresh_persistence_clears_matching_credentials_and_requires_login(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->times(4)
            ->andReturn($old, $old, $old, false);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('set')
            ->once()
            ->andThrow(new Lihi_Server_Exception('database unavailable'));
        $store->shouldReceive('delete_if_uuid')
            ->once()
            ->with(self::UUID)
            ->andReturn(true);
        $store->shouldReceive('release_lock')->once();

        $client->shouldReceive('refresh_access_token')
            ->once()
            ->andReturn([
                'uuid'          => self::UUID,
                'token'         => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);
        $client->shouldReceive('get_profile')
            ->once()
            ->andReturnUsing(function ($access, $fallback) {
                $fallback($access);
            });

        $this->expectException(Lihi_Token_Invalid_Exception::class);
        $this->expectExceptionMessage('Please sign in again');
        $this->service($client, $store)->get_profile();
    }

    /**
     * @return array<string, array{\Exception}>
     */
    public function terminalProtectedFailureProvider(): array
    {
        return [
            'invalid user' => [
                new Lihi_User_Invalid_Exception('User Invalid'),
            ],
            'second 401' => [
                new Lihi_Token_Invalid_Exception('access rejected again'),
            ],
        ];
    }

    /**
     * @dataProvider terminalProtectedFailureProvider
     * @test
     */
    public function terminal_protected_failure_conditionally_clears_current_tuple(
        \Exception $failure
    ): void {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();

        $store->shouldReceive('get')
            ->twice()
            ->andReturn($old, $old);
        $store->shouldReceive('acquire_lock')->once()->andReturn(true);
        $store->shouldReceive('delete_if_access_token')
            ->once()
            ->with('old-access')
            ->andReturn(true);
        $store->shouldReceive('release_lock')->once();

        $client->shouldReceive('get_profile')
            ->once()
            ->andThrow($failure);

        try {
            $this->service($client, $store)->get_profile();
            $this->fail('Expected terminal protected failure.');
        } catch (\Exception $error) {
            $this->assertSame($failure, $error);
        }
    }

    /** @test */
    public function terminal_failure_never_deletes_credentials_rotated_concurrently(): void
    {
        $client = $this->client();
        $store  = $this->store();
        $old    = $this->credentials();
        $new    = $this->credentials('new-access', 'new-refresh');

        $store->shouldReceive('get')
            ->twice()
            ->andReturn($old, $new);
        $store->shouldNotReceive('acquire_lock');
        $store->shouldNotReceive('delete_if_access_token');
        $client->shouldReceive('get_profile')
            ->once()
            ->andThrow(new Lihi_User_Invalid_Exception('User Invalid'));

        $this->expectException(Lihi_User_Invalid_Exception::class);
        $this->service($client, $store)->get_profile();
    }

    /** @test */
    public function create_workflow_preserves_url_tags_domain_and_utm_behavior(): void
    {
        $client      = $this->client();
        $store       = $this->store();
        $capturedBody = null;

        $store->shouldReceive('get')
            ->once()
            ->andReturn($this->credentials());
        $client->shouldReceive('get_short_link')
            ->once()
            ->with(
                'old-access',
                'post:example.com',
                42,
                Mockery::type('callable')
            )
            ->andReturn($this->findResponse(''));
        $client->shouldReceive('create_site')
            ->once()
            ->andReturnUsing(
                function ($access, $body, $fallback) use (&$capturedBody) {
                    $this->assertSame('old-access', $access);
                    $this->assertIsCallable($fallback);
                    $capturedBody = $body;
                    return [
                        'result' => true,
                        'data'   => ['short_url' => 'https://lihi.io/new'],
                    ];
                }
            );

        $url = $this->service($client, $store)
            ->get_or_create_short_url(42, 'post', [
                'domain' => 'go.example.com',
                'tags'   => ['campaign', 'wordpress', 'campaign'],
                'utm'    => [
                    'source'  => 'newsletter',
                    'medium'  => 'email',
                    'ignored' => 'value',
                ],
            ]);

        $this->assertSame('https://lihi.io/new', $url);
        $this->assertSame('go.example.com', $capturedBody['domain']);
        $this->assertSame('campaign,wordpress', $capturedBody['tags']);
        $this->assertSame('post:example.com', $capturedBody['type']);
        $this->assertSame('42', $capturedBody['type_id']);
        $this->assertSame([
            'https://example.com/?p=42&utm_source=newsletter&utm_medium=email',
        ], $capturedBody['urls']);
        $this->assertArrayNotHasKey('utm', $capturedBody);
    }

    /** @test */
    public function existing_short_url_never_calls_create(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $store->shouldReceive('get')
            ->once()
            ->andReturn($this->credentials());
        $client->shouldReceive('get_short_link')
            ->once()
            ->andReturn($this->findResponse('https://lihi.io/existing'));
        $client->shouldNotReceive('create_site');

        $url = $this->service($client, $store)
            ->get_or_create_short_url(42, 'post');

        $this->assertSame('https://lihi.io/existing', $url);
    }

    /** @test */
    public function copy_only_flow_throws_when_short_url_is_missing(): void
    {
        $client = $this->client();
        $store  = $this->store();

        $store->shouldReceive('get')
            ->once()
            ->andReturn($this->credentials());
        $client->shouldReceive('get_short_link')
            ->once()
            ->andReturn($this->findResponse(''));
        $client->shouldNotReceive('create_site');

        $this->expectException(Lihi_Not_Found_Exception::class);
        $this->service($client, $store)
            ->get_existing_short_url(42, 'post');
    }

    /** @test */
    public function passthrough_returns_nonce_from_protected_client(): void
    {
        $client    = $this->client();
        $store     = $this->store();
        $challenge = str_repeat('A', 43);

        $store->shouldReceive('get')
            ->once()
            ->andReturn($this->credentials());
        $client->shouldReceive('create_passthrough_nonce')
            ->once()
            ->with(
                'old-access',
                '/myDomain',
                $challenge,
                Mockery::type('callable')
            )
            ->andReturn([
                'result' => true,
                'data'   => ['nonce' => 'nonce-token'],
            ]);

        $nonce = $this->service($client, $store)
            ->create_passthrough_nonce('/myDomain', $challenge);

        $this->assertSame('nonce-token', $nonce);
    }
}
