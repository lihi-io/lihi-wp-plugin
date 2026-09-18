<?php

namespace Lihi\ShortUrl\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lihi\ShortUrl\Lihi_Client;
use Lihi\ShortUrl\Lihi_Need_Upgrade_Exception;
use Lihi\ShortUrl\Lihi_Rate_Limit_Exception;
use Lihi\ShortUrl\Lihi_Server_Exception;
use Lihi\ShortUrl\Lihi_Token_Invalid_Exception;
use Lihi\ShortUrl\Lihi_User_Invalid_Exception;
use Lihi\ShortUrl\Lihi_Validation_Exception;
use Mockery;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('esc_html')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function client(): Lihi_Client
    {
        return new Lihi_Client('https://app.lihi.io');
    }

    /**
     * @param list<array{code: int, body: string}> $responses
     * @param array<int, array{url: string, args: array}> $captured
     */
    private function mockRequests(array $responses, array &$captured): void
    {
        $index = 0;

        Functions\expect('wp_remote_request')
            ->times(count($responses))
            ->andReturnUsing(
                function ($url, $args) use (
                    &$captured,
                    &$index,
                    $responses
                ) {
                    $captured[] = [
                        'url'  => $url,
                        'args' => $args,
                    ];
                    $response = $responses[$index];
                    $index++;
                    return $response;
                }
            );

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')
            ->alias(function ($response) {
                return $response['code'];
            });
        Functions\when('wp_remote_retrieve_body')
            ->alias(function ($response) {
                return $response['body'];
            });
    }

    /**
     * @return array<string, array{string}>
     */
    public function protectedEndpointProvider(): array
    {
        return [
            'profile'       => ['profile'],
            'options'       => ['options'],
            'group options' => ['group_options'],
            'switch group'  => ['switch_group'],
            'passthrough'   => ['passthrough'],
            'find'          => ['find'],
            'store'         => ['store'],
        ];
    }

    /**
     * @dataProvider protectedEndpointProvider
     * @test
     */
    public function every_protected_endpoint_replaces_access_and_retries_once(
        string $endpoint
    ): void {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 401,
                'body' => '{"result":"failed","msg":"Token expired ,please login again"}',
            ],
            [
                'code' => 200,
                'body' => '{"result":true,"data":{}}',
            ],
        ], $captured);

        $fallbackCalls = [];
        $fallback      = function ($rejected) use (&$fallbackCalls) {
            $fallbackCalls[] = $rejected;
            return 'fresh-access';
        };

        $this->invokeProtected(
            $endpoint,
            'stale-access',
            $fallback
        );

        $this->assertCount(2, $captured);
        $this->assertSame($captured[0]['url'], $captured[1]['url']);
        $this->assertSame(
            $captured[0]['args']['method'],
            $captured[1]['args']['method']
        );
        $this->assertSame(
            $captured[0]['args']['body'] ?? null,
            $captured[1]['args']['body'] ?? null
        );
        $this->assertSame(
            'Bearer stale-access',
            $captured[0]['args']['headers']['Authorization']
        );
        $this->assertSame(
            'Bearer fresh-access',
            $captured[1]['args']['headers']['Authorization']
        );
        $this->assertSame(['stale-access'], $fallbackCalls);
    }

    /** @test */
    public function second_unauthorized_response_propagates_without_another_fallback(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 401,
                'body' => '{"result":"failed","msg":"Token expired ,please login again"}',
            ],
            [
                'code' => 401,
                'body' => '{"result":"failed","msg":"Token expired ,please login again"}',
            ],
        ], $captured);

        $calls = 0;
        $fallback = function () use (&$calls) {
            $calls++;
            return 'fresh-access';
        };

        try {
            $this->client()->get_profile('stale-access', $fallback);
            $this->fail('Expected token-invalid exception.');
        } catch (Lihi_Token_Invalid_Exception $error) {
            $this->assertSame(1, $calls);
            $this->assertCount(2, $captured);
        }
    }

    /** @test */
    public function empty_fallback_token_does_not_send_a_second_request(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 401,
                'body' => '{"result":"failed","msg":"Token expired ,please login again"}',
            ],
        ], $captured);

        $this->expectException(Lihi_Token_Invalid_Exception::class);

        try {
            $this->client()->get_profile(
                'stale-access',
                function () {
                    return '';
                }
            );
        } finally {
            $this->assertCount(1, $captured);
        }
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public function nonRefreshableErrorProvider(): array
    {
        return [
            'validation' => [
                400,
                '{"result":false,"msg":"bad request"}',
                Lihi_Validation_Exception::class,
            ],
            'invalid user' => [
                403,
                '{"result":"failed","msg":"User Invalid"}',
                Lihi_User_Invalid_Exception::class,
            ],
            'rate limit' => [
                429,
                '{"result":false,"msg":"Too Many Attempts."}',
                Lihi_Rate_Limit_Exception::class,
            ],
            'server error' => [
                500,
                '{"result":false,"msg":"upstream unavailable"}',
                Lihi_Server_Exception::class,
            ],
            'server error with user-invalid marker' => [
                500,
                '{"result":false,"msg":"User Invalid"}',
                Lihi_Server_Exception::class,
            ],
            'server error with user-not-found marker' => [
                500,
                '{"result":false,"msg":"user_not_found ,please login again"}',
                Lihi_Server_Exception::class,
            ],
        ];
    }

    /**
     * @dataProvider nonRefreshableErrorProvider
     * @test
     */
    public function non_401_errors_never_invoke_access_fallback(
        int $code,
        string $body,
        string $exceptionClass
    ): void {
        $captured = [];
        $this->mockRequests([
            ['code' => $code, 'body' => $body],
        ], $captured);

        $fallbackCalls = 0;
        $fallback = function () use (&$fallbackCalls) {
            $fallbackCalls++;
            return 'fresh-access';
        };

        try {
            $this->client()->get_options('access', $fallback);
            $this->fail('Expected ' . $exceptionClass);
        } catch (\Exception $error) {
            $this->assertInstanceOf($exceptionClass, $error);
            $this->assertSame(0, $fallbackCalls);
            $this->assertCount(1, $captured);
        }
    }

    /** @test */
    public function html_upgrade_page_is_server_error_not_token_invalid(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 500,
                'body' => '<html><head><title>網站升級中...</title></head></html>',
            ],
        ], $captured);

        $fallbackCalls = 0;

        try {
            $this->client()->get_profile(
                'access',
                function () use (&$fallbackCalls) {
                    $fallbackCalls++;
                    return 'fresh-access';
                }
            );
            $this->fail('Expected server exception.');
        } catch (Lihi_Token_Invalid_Exception $error) {
            $this->fail('HTML outages must not rotate refresh tokens.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertSame(0, $fallbackCalls);
        }
    }

    /** @test */
    public function malformed_401_is_fail_closed_without_access_fallback(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 401,
                'body' => '<html><body>Unauthorized</body></html>',
            ],
        ], $captured);

        $fallbackCalls = 0;

        try {
            $this->client()->get_profile(
                'stale-access',
                function () use (&$fallbackCalls) {
                    $fallbackCalls++;
                    return 'fresh-access';
                }
            );
            $this->fail('Expected malformed HTTP response to fail closed.');
        } catch (Lihi_Token_Invalid_Exception $error) {
            $this->fail('Malformed responses must not rotate refresh tokens.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertSame(0, $fallbackCalls);
            $this->assertCount(1, $captured);
        }
    }

    /** @test */
    public function empty_500_response_is_fail_closed(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 500,
                'body' => '',
            ],
        ], $captured);

        $fallbackCalls = 0;

        try {
            $this->client()->get_short_link(
                'access',
                'post:example.com',
                42,
                function () use (&$fallbackCalls) {
                    $fallbackCalls++;
                    return 'fresh-access';
                }
            );
            $this->fail('Empty HTTP 500 must fail closed.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertSame(0, $fallbackCalls);
            $this->assertCount(1, $captured);
        }
    }

    /** @test */
    public function find_uses_query_string_and_store_uses_json_body(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 200,
                'body' => '{"result":true,"data":{"site":""}}',
            ],
            [
                'code' => 200,
                'body' => '{"result":true,"data":{"short_url":"https://lihi.io/a"}}',
            ],
        ], $captured);

        $never = function () {
            $this->fail('Fallback should not run.');
        };

        $this->client()->get_short_link(
            'access',
            'post:example.com',
            42,
            $never
        );
        $this->client()->create_site(
            'access',
            [
                'domain'  => 'lihi.io',
                'urls'    => ['https://example.com/post'],
                'type'    => 'post:example.com',
                'type_id' => '42',
            ],
            $never
        );

        $this->assertStringContainsString(
            '/site/find?type=post%3Aexample.com&type_id=42',
            $captured[0]['url']
        );
        $this->assertArrayNotHasKey('body', $captured[0]['args']);

        $stored = json_decode($captured[1]['args']['body'], true);
        $this->assertSame(
            ['https://example.com/post'],
            $stored['urls']
        );
        $this->assertStringEndsWith('/site/store', $captured[1]['url']);
    }

    /** @test */
    public function site_store_need_upgrade_uses_dedicated_exception(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 400,
                'body' => '{"result":false,"msg":"need_upgrade"}',
            ],
        ], $captured);

        $fallbackCalls = 0;

        try {
            $this->client()->create_site(
                'access',
                [
                    'domain' => 'lihi.io',
                    'urls'   => ['https://example.com/post'],
                    'type'   => 'post:example.com',
                    'type_id' => '42',
                ],
                function () use (&$fallbackCalls) {
                    $fallbackCalls++;
                    return 'fresh-access';
                }
            );
            $this->fail('Expected need-upgrade exception.');
        } catch (Lihi_Need_Upgrade_Exception $error) {
            $this->assertSame('need_upgrade', $error->getMessage());
            $this->assertSame(0, $fallbackCalls);
            $this->assertCount(1, $captured);
        }
    }

    /** @test */
    public function site_store_failure_uses_validation_exception(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 400,
                'body' => '{"result":false,"msg":"site_create_fail"}',
            ],
        ], $captured);

        try {
            $this->client()->create_site(
                'access',
                [
                    'domain' => 'lihi.io',
                    'urls'   => ['https://example.com/post'],
                    'type'   => 'post:example.com',
                    'type_id' => '42',
                ],
                function () {
                    $this->fail('Fallback should not run.');
                }
            );
            $this->fail('Expected validation exception.');
        } catch (Lihi_Validation_Exception $error) {
            $this->assertSame(
                Lihi_Validation_Exception::class,
                get_class($error)
            );
            $this->assertSame('site_create_fail', $error->getMessage());
            $this->assertCount(1, $captured);
        }
    }

    /** @test */
    public function passthrough_posts_target_and_challenge(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 200,
                'body' => '{"result":true,"data":{"nonce":"nonce-token"}}',
            ],
        ], $captured);

        $challenge = str_repeat('A', 43);
        $result = $this->client()->create_passthrough_nonce(
            'access',
            '/myDomain',
            $challenge,
            function () {
                return 'unused';
            }
        );

        $body = json_decode($captured[0]['args']['body'], true);
        $this->assertSame('/myDomain', $body['target']);
        $this->assertSame($challenge, $body['challenge']);
        $this->assertSame('nonce-token', $result['data']['nonce']);
    }

    /** @test */
    public function work_group_endpoints_use_the_expected_paths_and_nullable_json_id(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 200,
                'body' => '{"result":true,"data":{"groups":[{"id":null,"name":"My Group"}],"group_id":null}}',
            ],
            [
                'code' => 200,
                'body' => '{"result":true,"data":{"group_id":null}}',
            ],
        ], $captured);

        $never = function () {
            $this->fail('Fallback should not run.');
        };

        $this->client()->get_group_options('access', $never);
        $this->client()->switch_group('access', null, $never);

        $this->assertStringEndsWith(
            '/api/wordpress/v1/user/group-options',
            $captured[0]['url']
        );
        $this->assertSame('GET', $captured[0]['args']['method']);
        $this->assertArrayNotHasKey('body', $captured[0]['args']);
        $this->assertStringEndsWith(
            '/api/wordpress/v1/user/switch-group',
            $captured[1]['url']
        );
        $this->assertSame('POST', $captured[1]['args']['method']);
        $this->assertSame(
            ['group_id' => null],
            json_decode($captured[1]['args']['body'], true)
        );
    }

    /** @test */
    public function domain_options_uses_the_renamed_endpoint(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 200,
                'body' => '{"result":true,"data":{"domains":[],"utm_sources":[],"utm_mediums":[]}}',
            ],
        ], $captured);

        $this->client()->get_options(
            'access',
            function () {
                $this->fail('Fallback should not run.');
            }
        );

        $this->assertStringEndsWith(
            '/api/wordpress/v1/user/domain-options',
            $captured[0]['url']
        );
    }

    /** @test */
    public function html_404_is_a_server_failure_not_a_missing_short_link(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 404,
                'body' => '<html><head><title>Page Not Found</title></head></html>',
            ],
        ], $captured);

        $this->expectException(Lihi_Server_Exception::class);
        $this->client()->get_profile(
            'access',
            function () {
                return 'unused';
            }
        );
    }

    /** @test */
    public function empty_404_is_fail_closed_without_access_fallback(): void
    {
        $captured = [];
        $this->mockRequests([
            [
                'code' => 404,
                'body' => '',
            ],
        ], $captured);

        $fallbackCalls = 0;

        try {
            $this->client()->get_short_link(
                'access',
                'post:example.com',
                42,
                function () use (&$fallbackCalls) {
                    $fallbackCalls++;
                    return 'fresh-access';
                }
            );
            $this->fail('Empty HTTP 404 must fail closed.');
        } catch (Lihi_Server_Exception $error) {
            $this->assertSame(0, $fallbackCalls);
            $this->assertCount(1, $captured);
        }
    }

    /**
     * @return array
     */
    private function invokeProtected(
        string $endpoint,
        string $access,
        callable $fallback
    ): array {
        switch ($endpoint) {
            case 'profile':
                return $this->client()->get_profile($access, $fallback);
            case 'options':
                return $this->client()->get_options($access, $fallback);
            case 'group_options':
                return $this->client()->get_group_options($access, $fallback);
            case 'switch_group':
                return $this->client()->switch_group(
                    $access,
                    42,
                    $fallback
                );
            case 'passthrough':
                return $this->client()->create_passthrough_nonce(
                    $access,
                    '/myDomain',
                    str_repeat('A', 43),
                    $fallback
                );
            case 'find':
                return $this->client()->get_short_link(
                    $access,
                    'post:example.com',
                    42,
                    $fallback
                );
            case 'store':
                return $this->client()->create_site(
                    $access,
                    [
                        'domain' => 'lihi.io',
                        'urls'   => ['https://example.com'],
                        'type'   => 'post:example.com',
                    ],
                    $fallback
                );
        }

        throw new \InvalidArgumentException('Unknown endpoint.');
    }
}
