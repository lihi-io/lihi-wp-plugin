<?php

namespace Lihi\ShortUrl\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lihi\ShortUrl\Lihi_Account_Already_Exists_Exception;
use Lihi\ShortUrl\Lihi_Account_Not_Found_Exception;
use Lihi\ShortUrl\Lihi_Authorization_Code_Invalid_Exception;
use Lihi\ShortUrl\Lihi_Client;
use Lihi\ShortUrl\Lihi_Email_Or_Password_Invalid_Exception;
use Lihi\ShortUrl\Lihi_Rate_Limit_Exception;
use Lihi\ShortUrl\Lihi_Refresh_Token_Invalid_Exception;
use Lihi\ShortUrl\Lihi_Server_Exception;
use Lihi\ShortUrl\Lihi_User_Invalid_Exception;
use Mockery;
use PHPUnit\Framework\TestCase;

class AuthClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('home_url')->justReturn('https://wp.example.com:8443');
        Functions\when('wp_parse_url')->justReturn('wp.example.com');
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
        return new Lihi_Client('https://app.lihi.com');
    }

    /**
     * @return callable(): array{url: string|null, args: array|null}
     */
    private function mockRequest(
        int $code = 200,
        string $body = '{"result":true,"data":[]}'
    ): callable {
        $captured = ['url' => null, 'args' => null];
        $fake     = ['code' => $code, 'body' => $body];

        Functions\expect('wp_remote_request')
            ->once()
            ->andReturnUsing(function ($url, $args) use (&$captured, $fake) {
                $captured['url']  = $url;
                $captured['args'] = $args;
                return $fake;
            });

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')
            ->alias(function ($response) {
                return $response['code'];
            });
        Functions\when('wp_remote_retrieve_body')
            ->alias(function ($response) {
                return $response['body'];
            });

        return function () use (&$captured) {
            return $captured;
        };
    }

    /** @test */
    public function login_posts_credentials_and_pkce_challenge_only(): void
    {
        $challenge = str_repeat('A', 43);
        $capture   = $this->mockRequest(
            200,
            '{"result":true,"data":{"code":"authorization-code"}}'
        );

        $result = $this->client()->login(
            'admin@example.com',
            'account-password',
            $challenge
        );

        $request = $capture();
        $body    = json_decode($request['args']['body'], true);

        $this->assertSame(
            'https://app.lihi.com/api/wordpress/v1/auth/login',
            $request['url']
        );
        $this->assertSame('POST', $request['args']['method']);
        $this->assertSame([
            'email'          => 'admin@example.com',
            'password'       => 'account-password',
            'code_challenge' => $challenge,
        ], $body);
        $this->assertArrayNotHasKey('Authorization', $request['args']['headers']);
        $this->assertArrayNotHasKey('hostname', $body);
        $this->assertArrayNotHasKey('uuid', $body);
        $this->assertArrayNotHasKey('is_mobile', $body);
        $this->assertSame(['code' => 'authorization-code'], $result);
    }

    /** @test */
    public function login_maps_missing_account_password_and_invalid_user_errors(): void
    {
        $cases = [
            [
                '{"result":false,"msg":"account does not exist"}',
                Lihi_Account_Not_Found_Exception::class,
            ],
            [
                '{"result":false,"msg":"password invalid"}',
                Lihi_Email_Or_Password_Invalid_Exception::class,
            ],
            [
                '{"result":false,"msg":"User Invalid"}',
                Lihi_User_Invalid_Exception::class,
            ],
        ];

        foreach ($cases as [$body, $exception]) {
            Monkey\tearDown();
            Monkey\setUp();
            Functions\when('wp_json_encode')->alias('json_encode');
            Functions\when('esc_html')->returnArg(1);
            $this->mockRequest(403, $body);

            try {
                $this->client()->login(
                    'admin@example.com',
                    'password',
                    str_repeat('A', 43)
                );
                $this->fail('Expected ' . $exception);
            } catch (\Exception $error) {
                $this->assertInstanceOf($exception, $error);
            }
        }
    }

    /** @test */
    public function register_posts_trusted_hostname_and_password(): void
    {
        $capture = $this->mockRequest(200, '{"result":true,"msg":""}');

        $this->client()->register('new@example.com', 'new-password');

        $request = $capture();
        $body    = json_decode($request['args']['body'], true);

        $this->assertSame(
            'https://app.lihi.com/api/wordpress/v1/auth/register',
            $request['url']
        );
        $this->assertSame([
            'email'    => 'new@example.com',
            'hostname' => 'wp.example.com',
            'password' => 'new-password',
        ], $body);
        $this->assertArrayNotHasKey('Authorization', $request['args']['headers']);
    }

    /** @test */
    public function register_maps_existing_account_conflict(): void
    {
        $this->mockRequest(
            409,
            '{"result":false,"msg":"account already exists"}'
        );

        $this->expectException(Lihi_Account_Already_Exists_Exception::class);
        $this->client()->register('existing@example.com', 'password');
    }

    /** @test */
    public function exchange_authorization_code_posts_pkce_grant(): void
    {
        $capture = $this->mockRequest(
            200,
            '{"result":true,"data":{"uuid":"11111111-1111-4111-8111-111111111111","token":"access","refresh_token":"refresh"}}'
        );

        $result = $this->client()->exchange_authorization_code(
            str_repeat('c', 43),
            str_repeat('v', 43)
        );

        $request = $capture();
        $body    = json_decode($request['args']['body'], true);

        $this->assertSame(
            'https://app.lihi.com/api/wordpress/v1/auth/token',
            $request['url']
        );
        $this->assertSame([
            'grant_type'    => 'authorization_code',
            'code'          => str_repeat('c', 43),
            'code_verifier' => str_repeat('v', 43),
        ], $body);
        $this->assertSame('access', $result['token']);
        $this->assertSame('refresh', $result['refresh_token']);
    }

    /** @test */
    public function exchange_authorization_code_maps_invalid_code(): void
    {
        $this->mockRequest(403, '{"result":false,"msg":"code invalid"}');

        $this->expectException(
            Lihi_Authorization_Code_Invalid_Exception::class
        );
        $this->client()->exchange_authorization_code(
            str_repeat('c', 43),
            str_repeat('v', 43)
        );
    }

    /** @test */
    public function refresh_posts_uuid_and_refresh_token_grant(): void
    {
        $capture = $this->mockRequest(
            200,
            '{"result":true,"data":{"uuid":"11111111-1111-4111-8111-111111111111","token":"fresh-access","refresh_token":"fresh-refresh"}}'
        );

        $result = $this->client()->refresh_access_token(
            '11111111-1111-4111-8111-111111111111',
            'old-refresh'
        );

        $request = $capture();
        $body    = json_decode($request['args']['body'], true);

        $this->assertSame([
            'grant_type'    => 'refresh_token',
            'uuid'          => '11111111-1111-4111-8111-111111111111',
            'refresh_token' => 'old-refresh',
        ], $body);
        $this->assertArrayNotHasKey('Authorization', $request['args']['headers']);
        $this->assertSame('fresh-access', $result['token']);
        $this->assertSame('fresh-refresh', $result['refresh_token']);
    }

    /** @test */
    public function refresh_maps_invalid_refresh_token(): void
    {
        $this->mockRequest(
            403,
            '{"result":false,"msg":"refresh token invalid"}'
        );

        $this->expectException(Lihi_Refresh_Token_Invalid_Exception::class);
        $this->client()->refresh_access_token(
            '11111111-1111-4111-8111-111111111111',
            'invalid-refresh'
        );
    }

    /** @test */
    public function auth_rate_limit_is_typed(): void
    {
        $this->mockRequest(429, '{"result":false,"msg":"Too Many Attempts."}');

        $this->expectException(Lihi_Rate_Limit_Exception::class);
        $this->client()->login(
            'admin@example.com',
            'password',
            str_repeat('A', 43)
        );
    }

    /** @test */
    public function auth_success_envelope_on_non_success_status_fails_closed(): void
    {
        $this->mockRequest(
            404,
            '{"result":true,"data":{"code":"unexpected-code"}}'
        );

        $this->expectException(Lihi_Server_Exception::class);
        $this->client()->login(
            'admin@example.com',
            'password',
            str_repeat('A', 43)
        );
    }
}
