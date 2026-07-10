<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\DsnParser;
use TiDBCloud\Lake\Exception\LakeException;

final class DsnParserTest extends TestCase
{
    public function testBasicDsn(): void
    {
        $cfg = Config::fromDsn('lake://user:password@app.lake.example.com:443/default?warehouse=my-warehouse&tenant=t1&role=readonly');

        self::assertSame('user', $cfg->user);
        self::assertSame('password', $cfg->password);
        self::assertSame('app.lake.example.com:443', $cfg->host);
        self::assertSame('default', $cfg->database);
        self::assertSame('my-warehouse', $cfg->warehouse);
        self::assertSame('t1', $cfg->tenant);
        self::assertSame('readonly', $cfg->role);
        self::assertSame('', $cfg->sslMode);
        self::assertTrue($cfg->loginEnabled);
    }

    public function testHttpSchemeDisablesSsl(): void
    {
        $cfg = Config::fromDsn('http://u:p@localhost:8000/db');
        self::assertSame(Config::SSL_MODE_DISABLE, $cfg->sslMode);
        self::assertSame('localhost:8000', $cfg->host);
    }

    public function testLakeHttpSchemeDefaultsToPort80(): void
    {
        $cfg = Config::fromDsn('lake+http://u:p@localhost/db');
        self::assertSame(Config::SSL_MODE_DISABLE, $cfg->sslMode);
        self::assertSame('localhost:80', $cfg->host);
    }

    public function testDefaultPortIs443(): void
    {
        $cfg = Config::fromDsn('lake://u:p@lake.tidbcloud.com/db');
        self::assertSame('lake.tidbcloud.com:443', $cfg->host);
    }

    public function testNumericAndDurationParams(): void
    {
        $cfg = Config::fromDsn(
            'lake://u:p@h:443/db?timeout=1m30s&wait_time_secs=10&max_rows_in_buffer=1000&max_rows_per_page=500'
        );
        self::assertSame(90.0, $cfg->timeout);
        self::assertSame(10, $cfg->waitTimeSecs);
        self::assertSame(1000, $cfg->maxRowsInBuffer);
        self::assertSame(500, $cfg->maxRowsPerPage);
    }

    public function testPlainSecondsTimeout(): void
    {
        $cfg = Config::fromDsn('lake://u:p@h/db?timeout=30');
        self::assertSame(30.0, $cfg->timeout);
    }

    public function testLocationIsAliasOfTimezone(): void
    {
        $cfg = Config::fromDsn('lake://u:p@h/db?location=Asia/Shanghai');
        self::assertSame('Asia/Shanghai', $cfg->timezone);

        $cfg = Config::fromDsn('lake://u:p@h/db?timezone=UTC');
        self::assertSame('UTC', $cfg->timezone);
    }

    public function testLocationTimezoneConflict(): void
    {
        $this->expectException(LakeException::class);
        $this->expectExceptionMessage('conflict');
        Config::fromDsn('lake://u:p@h/db?location=Asia/Shanghai&timezone=UTC');
    }

    public function testLoginDisable(): void
    {
        $cfg = Config::fromDsn('lake://u:p@h/db?login=disable');
        self::assertFalse($cfg->loginEnabled);

        $cfg = Config::fromDsn('lake://u:p@h/db?login=enable');
        self::assertTrue($cfg->loginEnabled);

        $this->expectException(LakeException::class);
        Config::fromDsn('lake://u:p@h/db?login=bogus');
    }

    public function testAccessToken(): void
    {
        $cfg = Config::fromDsn('lake://h:443/db?access_token=tok123&tenant=t');
        self::assertSame('tok123', $cfg->accessToken);
        self::assertSame('', $cfg->user);
    }

    public function testUnknownParamsBecomeSessionSettings(): void
    {
        $cfg = Config::fromDsn('lake://u:p@h/db?binary_output_format=base64&http_json_result_mode=display&empty_field_as=null');
        self::assertSame('base64', $cfg->params['binary_output_format']);
        self::assertSame('display', $cfg->params['http_json_result_mode']);
        self::assertSame('null', $cfg->emptyFieldAs);
    }

    public function testForbiddenOptions(): void
    {
        $this->expectException(LakeException::class);
        $this->expectExceptionMessage("unknown option 'database'");
        Config::fromDsn('lake://u:p@h/db?database=other');
    }

    public function testArrowFormatRejected(): void
    {
        $this->expectException(LakeException::class);
        Config::fromDsn('lake://u:p@h/db?query_result_format=arrow');
    }

    public function testEncodedPassword(): void
    {
        $cfg = Config::fromDsn('lake://user:pa%40ss@h/db');
        self::assertSame('pa@ss', $cfg->password);
    }

    public function testSpecialCharPasswordAutoEncoded(): void
    {
        // Characters that would break parse_url get auto-encoded first.
        $cfg = Config::fromDsn('lake://user:pa ss@h/db');
        self::assertSame('pa ss', $cfg->password);
    }

    public function testSslModeParam(): void
    {
        $cfg = Config::fromDsn('lake://u:p@h:8000/db?sslmode=disable');
        self::assertSame(Config::SSL_MODE_DISABLE, $cfg->sslMode);
    }

    public function testParseDuration(): void
    {
        self::assertSame(0.5, DsnParser::parseDuration('500ms'));
        self::assertSame(3600.0, DsnParser::parseDuration('1h'));
        self::assertSame(90.0, DsnParser::parseDuration('1m30s'));
        self::assertSame(1.5, DsnParser::parseDuration('1.5'));

        $this->expectException(LakeException::class);
        DsnParser::parseDuration('abc');
    }
}
