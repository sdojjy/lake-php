<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\Tests\Support\MockHttpClient;

final class CookieTest extends TestCase
{
    public function testCookieEnabledSentAndServerCookiesEchoedBack(): void
    {
        $http = new MockHttpClient([
            // login sets a session cookie (real Lake gateways panic without
            // cookie_enabled=true and track the session via session_id)
            new Response(200, [
                'Content-Type' => 'application/json',
                'Set-Cookie' => ['session_id=abc-123; Path=/', 'last_refresh_time=1700000000; Path=/'],
            ], '{}'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'q-1',
                'schema' => [['name' => 'x', 'type' => 'Int32']],
                'data' => [['1']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ])),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable');
        $conn = (new Client($config, $http))->connect();
        $conn->queryRow('SELECT 1 AS x');

        self::assertSame('cookie_enabled=true', $http->requests[0]->getHeaderLine('Cookie'));
        self::assertSame(
            'cookie_enabled=true; session_id=abc-123; last_refresh_time=1700000000',
            $http->requests[1]->getHeaderLine('Cookie'),
        );
    }
}
