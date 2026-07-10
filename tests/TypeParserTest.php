<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\TypeParser;

final class TypeParserTest extends TestCase
{
    private TypeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TypeParser();
    }

    public function testNullCell(): void
    {
        self::assertNull($this->parser->parse('Int32', null));
        self::assertNull($this->parser->parse('String', null));
    }

    public function testBoolean(): void
    {
        self::assertTrue($this->parser->parse('Boolean', 'true'));
        self::assertTrue($this->parser->parse('Boolean', '1'));
        self::assertFalse($this->parser->parse('Boolean', 'false'));
        self::assertFalse($this->parser->parse('Boolean', '0'));
    }

    public function testSmallInts(): void
    {
        self::assertSame(-128, $this->parser->parse('Int8', '-128'));
        self::assertSame(65535, $this->parser->parse('UInt16', '65535'));
        self::assertSame(-2147483648, $this->parser->parse('Int32', '-2147483648'));
    }

    public function testInt64WithinRange(): void
    {
        self::assertSame(PHP_INT_MAX, $this->parser->parse('Int64', (string) PHP_INT_MAX));
        self::assertSame(PHP_INT_MIN, $this->parser->parse('Int64', (string) PHP_INT_MIN));
        self::assertSame(0, $this->parser->parse('Int64', '0'));
    }

    public function testUInt64Overflow(): void
    {
        self::assertSame('18446744073709551615', $this->parser->parse('UInt64', '18446744073709551615'));
        self::assertSame('9223372036854775808', $this->parser->parse('Int64', '9223372036854775808'));
        self::assertSame(12345, $this->parser->parse('UInt64', '12345'));
    }

    public function testFloats(): void
    {
        self::assertSame(1.5, $this->parser->parse('Float64', '1.5'));
        self::assertSame(-0.25, $this->parser->parse('Float32', '-0.25'));
        self::assertNan($this->parser->parse('Float64', 'NaN'));
        self::assertInfinite($this->parser->parse('Float64', 'Infinity'));
    }

    public function testDecimalStaysString(): void
    {
        self::assertSame('123.4567890123456789', $this->parser->parse('Decimal(38, 16)', '123.4567890123456789'));
    }

    public function testString(): void
    {
        self::assertSame('hello', $this->parser->parse('String', 'hello'));
        // literal "NULL" string cell stays a string for String columns
        self::assertSame('NULL', $this->parser->parse('Nullable(String)', 'NULL'));
    }

    public function testDate(): void
    {
        $value = $this->parser->parse('Date', '2024-05-01');
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2024-05-01 00:00:00', $value->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $value->getTimezone()->getName());
    }

    public function testTimestampUsesConfiguredTimezone(): void
    {
        $parser = new TypeParser(new \DateTimeZone('Asia/Shanghai'));
        $value = $parser->parse('Timestamp', '2024-05-01 10:20:30.123456');
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2024-05-01 10:20:30.123456', $value->format('Y-m-d H:i:s.u'));
        self::assertSame('Asia/Shanghai', $value->getTimezone()->getName());
    }

    public function testTimestampTzKeepsOwnOffset(): void
    {
        $value = $this->parser->parse('Timestamp_Tz', '2024-05-01 10:20:30.123456 +0800');
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('+08:00', $value->getTimezone()->getName());
        self::assertSame('2024-05-01 02:20:30', $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testDateTimeAliasNormalizesToTimestamp(): void
    {
        $value = $this->parser->parse('DateTime', '2024-05-01 10:20:30');
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
    }

    public function testBinaryHexDefault(): void
    {
        self::assertSame('hello', $this->parser->parse('Binary', bin2hex('hello')));
    }

    public function testBinaryDisplayBase64(): void
    {
        $parser = new TypeParser(null, 'base64', 'display');
        self::assertSame('hello', $parser->parse('Binary', base64_encode('hello')));
    }

    public function testBinaryDriverModeIgnoresFormat(): void
    {
        // In driver mode the server sends hex regardless of binary_output_format.
        $parser = new TypeParser(null, 'base64', 'driver');
        self::assertSame('hello', $parser->parse('Binary', bin2hex('hello')));
    }

    public function testNullableWrapper(): void
    {
        self::assertSame(42, $this->parser->parse('Nullable(Int32)', '42'));
        self::assertNull($this->parser->parse('Nullable(Timestamp)', 'NULL'));
    }

    public function testNullSuffixSyntax(): void
    {
        self::assertSame(42, $this->parser->parse('Int32 NULL', '42'));
        self::assertNull($this->parser->parse('Date NULL', 'NULL'));
    }

    public function testComplexTypesStayString(): void
    {
        self::assertSame('[1,2,3]', $this->parser->parse('Array(Int32)', '[1,2,3]'));
        self::assertSame('{"k":"v"}', $this->parser->parse('Variant', '{"k":"v"}'));
        self::assertSame('(1,2)', $this->parser->parse('Tuple(Int32, Int32)', '(1,2)'));
        self::assertSame("{'k':'v'}", $this->parser->parse('Map(String, String)', "{'k':'v'}"));
        self::assertSame('POINT(1 2)', $this->parser->parse('Geometry', 'POINT(1 2)'));
    }

    public function testParseTypeDesc(): void
    {
        $desc = TypeParser::parseTypeDesc('Nullable(Decimal(10, 2))');
        self::assertSame('Nullable', $desc['name']);
        self::assertSame('Decimal', $desc['args'][0]['name']);
        self::assertSame('10', $desc['args'][0]['args'][0]['name']);
        self::assertSame('2', $desc['args'][0]['args'][1]['name']);

        $normalized = TypeParser::normalize($desc);
        self::assertSame('Decimal', $normalized['name']);
        self::assertTrue($normalized['nullable']);

        $desc = TypeParser::parseTypeDesc('Int32 NULL');
        self::assertSame('Int32', $desc['name']);
        self::assertTrue($desc['nullable']);
    }
}
