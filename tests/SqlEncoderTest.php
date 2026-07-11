<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Exception\LakeException;
use TiDBCloud\Lake\SqlEncoder;

final class SqlEncoderTest extends TestCase
{
    public function testNoParams(): void
    {
        self::assertSame('SELECT 1', SqlEncoder::interpolate('SELECT 1', null));
        self::assertSame('SELECT 1', SqlEncoder::interpolate('SELECT 1', []));
    }

    public function testBasicTypes(): void
    {
        $sql = SqlEncoder::interpolate(
            'INSERT INTO t VALUES (?, ?, ?, ?, ?, ?)',
            [1, 1.5, 'hello', true, false, null],
        );
        self::assertSame("INSERT INTO t VALUES (1, 1.5, 'hello', 1, 0, NULL)", $sql);
    }

    public function testStringEscaping(): void
    {
        $sql = SqlEncoder::interpolate('SELECT ?', ["it's a \\ test"]);
        self::assertSame("SELECT 'it\\'s a \\\\ test'", $sql);
    }

    public function testSqlInjectionIsEscaped(): void
    {
        $sql = SqlEncoder::interpolate('SELECT * FROM t WHERE name = ?', ["'; DROP TABLE t; --"]);
        self::assertSame("SELECT * FROM t WHERE name = '\\'; DROP TABLE t; --'", $sql);
    }

    public function testQuestionMarkInsideStringLiteralIsNotAPlaceholder(): void
    {
        $sql = SqlEncoder::interpolate("SELECT 'a?b', ? FROM t", [42]);
        self::assertSame("SELECT 'a?b', 42 FROM t", $sql);
    }

    public function testDateTime(): void
    {
        $dt = new \DateTimeImmutable('2024-05-01 10:20:30.123456', new \DateTimeZone('+08:00'));
        $sql = SqlEncoder::interpolate('SELECT ?', [$dt]);
        self::assertSame("SELECT '2024-05-01 10:20:30.123456+08:00'", $sql);
    }

    public function testArray(): void
    {
        $sql = SqlEncoder::interpolate('SELECT ?', [[1, 'a', null]]);
        self::assertSame("SELECT [1,'a',NULL]", $sql);
    }

    public function testDecimalAsString(): void
    {
        $sql = SqlEncoder::interpolate('SELECT ?', ['123.456789012345678901']);
        self::assertSame("SELECT '123.456789012345678901'", $sql);
    }

    public function testParamCountMismatch(): void
    {
        $this->expectException(LakeException::class);
        $this->expectExceptionMessage('expected 2 parameters, got 1');
        SqlEncoder::interpolate('SELECT ?, ?', [1]);
    }

    public function testAssociativeArrayRejected(): void
    {
        $this->expectException(LakeException::class);
        SqlEncoder::interpolate('SELECT ?', [['k' => 'v']]);
    }

    public function testObjectParams(): void
    {
        $params = new \stdClass();
        $params->a = 1;
        $params->b = 'x';
        self::assertSame("SELECT 1, 'x'", SqlEncoder::interpolate('SELECT ?, ?', $params));
    }

    public function testNamedParams(): void
    {
        self::assertSame(
            "SELECT 1, 'x', 1",
            SqlEncoder::interpolate('SELECT :a, :b, :a', ['a' => 1, 'b' => 'x']),
        );
    }

    public function testNamedParamsIgnoreCastsAndQuotedStrings(): void
    {
        self::assertSame(
            "SELECT 'x:y', 1::Int32, 'alice'",
            SqlEncoder::interpolate("SELECT 'x:y', 1::Int32, :name", ['name' => 'alice']),
        );
    }

    public function testMissingNamedParam(): void
    {
        $this->expectException(LakeException::class);
        $this->expectExceptionMessage('missing named SQL parameter: b');
        SqlEncoder::interpolate('SELECT :a, :b', ['a' => 1]);
    }

    public function testVariantPathAccessWithPositionalParams(): void
    {
        // ":" is Lake's VARIANT path syntax; list params must keep it literal.
        self::assertSame(
            'SELECT v:name FROM t WHERE id = 1',
            SqlEncoder::interpolate('SELECT v:name FROM t WHERE id = ?', [1]),
        );
    }

    public function testVariantPathAccessWithoutParams(): void
    {
        self::assertSame(
            'SELECT v:name FROM t',
            SqlEncoder::interpolate('SELECT v:name FROM t', []),
        );
    }

    public function testPlaceholderPositions(): void
    {
        self::assertSame([7, 10], SqlEncoder::placeholders('SELECT ?, ?'));
        self::assertSame([], SqlEncoder::placeholders("SELECT '?'"));
        self::assertSame([], SqlEncoder::placeholders('SELECT 1'));
        self::assertSame([['pos' => 7, 'len' => 2, 'name' => 'x']], SqlEncoder::namedPlaceholders('SELECT :x'));
    }

    public function testFloatSpecialValues(): void
    {
        self::assertSame('NaN', SqlEncoder::encodeValue(NAN));
        self::assertSame('Infinity', SqlEncoder::encodeValue(INF));
        self::assertSame('-Infinity', SqlEncoder::encodeValue(-INF));
    }
}
