<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Laravel;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\Laravel\LakeConnection;
use TiDBCloud\Lake\Laravel\LakeConnector;
use TiDBCloud\Lake\Laravel\LakeQueryGrammar;
use TiDBCloud\Lake\Laravel\LakeServiceProvider;

final class LakeConnectorTest extends TestCase
{
    public function testDsnFromLaravelConfig(): void
    {
        $dsn = (new LakeConnector())->dsn([
            'driver' => 'lake',
            'host' => 'lake.tidbcloud.com',
            'port' => 443,
            'database' => 'analytics',
            'username' => 'user@corp',
            'password' => 'p@ss:w/rd',
            'warehouse' => 'wh 1',
            'tenant' => 't1',
            'timeout' => '30s',
            'presigned_url_disabled' => true,
            'prefix' => '',
            'settings' => ['binary_output_format' => 'base64'],
        ]);

        self::assertSame(
            'lake://user%40corp:p%40ss%3Aw%2Frd@lake.tidbcloud.com:443/analytics'
            . '?warehouse=wh%201&tenant=t1&timeout=30s&presigned_url_disabled=true&binary_output_format=base64',
            $dsn,
        );

        $config = Config::fromDsn($dsn);
        self::assertSame('user@corp', $config->user);
        self::assertSame('p@ss:w/rd', $config->password);
        self::assertSame('lake.tidbcloud.com:443', $config->host);
        self::assertSame('analytics', $config->database);
        self::assertSame('wh 1', $config->warehouse);
        self::assertSame(30.0, $config->timeout);
        self::assertTrue($config->presignedUrlDisabled);
        self::assertSame(['binary_output_format' => 'base64'], $config->params);
    }

    public function testPlainHttpAndDefaults(): void
    {
        $dsn = (new LakeConnector())->dsn([
            'host' => 'query',
            'port' => 8000,
            'database' => 'default',
            'username' => 'root',
            'password' => '',
            'sslmode' => 'disable',
        ]);

        self::assertSame('lake://root@query:8000/default?sslmode=disable', $dsn);
        self::assertSame(Config::SSL_MODE_DISABLE, Config::fromDsn($dsn)->sslMode);

        $config = Config::fromDsn((new LakeConnector())->dsn(['host' => 'localhost', 'sslmode' => 'disable']));
        self::assertSame('localhost:80', $config->host);
    }

    public function testExplicitDsnWins(): void
    {
        $dsn = 'lake://u:p@lake.tidbcloud.com:443/default?warehouse=wh';

        self::assertSame($dsn, (new LakeConnector())->dsn(['dsn' => $dsn, 'host' => 'ignored']));
    }

    public function testMissingHostIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LakeConnector())->dsn(['database' => 'default']);
    }

    public function testDriverRegistration(): void
    {
        $capsule = new Capsule();
        LakeServiceProvider::registerDriver($capsule->getContainer());
        $capsule->addConnection([
            'driver' => 'lake',
            'host' => 'lake.example.invalid',
            'database' => 'default',
            'username' => 'u',
            'password' => 'p',
        ], 'lake');

        // Connecting is lazy: resolving the connection makes no request.
        $connection = $capsule->getConnection('lake');

        self::assertInstanceOf(LakeConnection::class, $connection);
        self::assertInstanceOf(LakeQueryGrammar::class, $connection->getQueryGrammar());
        self::assertSame('lake', $connection->getDriverName());
        self::assertInstanceOf(LakeConnector::class, $capsule->getContainer()->make('db.connector.lake'));
    }
}
