<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\Exception\LakeException;

final class HeadersTest extends TestCase
{
    public function testBasicAuthHeaders(): void
    {
        $config = Config::fromDsn('lake://alice:secret@h:443/db?tenant=t1&warehouse=wh1');
        $client = new Client($config);
        $headers = $client->makeHeaders();

        self::assertSame('Basic ' . base64_encode('alice:secret'), $headers['Authorization']);
        self::assertSame('t1', $headers[Client::HEADER_TENANT]);
        self::assertSame('wh1', $headers[Client::HEADER_WAREHOUSE]);
        self::assertSame('warehouse', $headers[Client::HEADER_ROUTE]);
        self::assertSame($client->sessionId() . '.0', $headers[Client::HEADER_QUERY_ID]);
        self::assertStringStartsWith('lake-php/', $headers['User-Agent']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $headers[Client::HEADER_ROUTE_HINT]);
    }

    public function testBearerAuthHeaders(): void
    {
        $config = Config::fromDsn('lake://h:443/db?access_token=tok-abc');
        $client = new Client($config);
        $headers = $client->makeHeaders();

        self::assertSame('Bearer tok-abc', $headers['Authorization']);
        self::assertArrayNotHasKey(Client::HEADER_TENANT, $headers);
        self::assertArrayNotHasKey(Client::HEADER_WAREHOUSE, $headers);
    }

    public function testUserAgentSuffix(): void
    {
        $config = Config::fromDsn('lake://u:p@h/db');
        $config->userAgent = 'my-app/2.0';
        $client = new Client($config);

        self::assertSame('lake-php/' . Client::VERSION . ' (my-app/2.0)', $client->makeHeaders()['User-Agent']);
    }

    public function testMissingCredentials(): void
    {
        $config = Config::fromDsn('lake://h:443/db');
        $client = new Client($config);

        $this->expectException(LakeException::class);
        $this->expectExceptionMessage('no user password or access token');
        $client->makeHeaders();
    }
}
