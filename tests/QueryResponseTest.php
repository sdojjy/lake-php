<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\QueryResponse;

final class QueryResponseTest extends TestCase
{
    public function testDecode(): void
    {
        $json = <<<'JSON'
        {
            "id": "q-123",
            "node_id": "node-1",
            "state": "Running",
            "session": {"database": "default", "need_sticky": true},
            "settings": {"timezone": "UTC", "binary_output_format": "hex"},
            "schema": [
                {"name": "id", "type": "Int64"},
                {"name": "name", "type": "Nullable(String)"}
            ],
            "data": [["1", "alice"], ["2", null]],
            "error": null,
            "stats": {"running_time_ms": 12.5, "scan_progress": {"rows": 2, "bytes": 64}},
            "next_uri": "/v1/query/q-123/page/1",
            "final_uri": "/v1/query/q-123/final",
            "kill_uri": "/v1/query/q-123/kill",
            "stats_uri": "/v1/query/q-123"
        }
        JSON;

        $resp = QueryResponse::fromArray(json_decode($json, true));

        self::assertSame('q-123', $resp->id);
        self::assertSame('node-1', $resp->nodeId);
        self::assertSame('Running', $resp->state);
        self::assertSame(['database' => 'default', 'need_sticky' => true], $resp->session);
        self::assertSame('UTC', $resp->settings['timezone']);
        self::assertCount(2, $resp->schema);
        self::assertSame(['name' => 'id', 'type' => 'Int64'], $resp->schema[0]);
        self::assertSame([['1', 'alice'], ['2', null]], $resp->data);
        self::assertNull($resp->error);
        self::assertSame(12.5, $resp->stats['running_time_ms']);
        self::assertSame('/v1/query/q-123/page/1', $resp->nextUri);
        self::assertSame('/v1/query/q-123/final', $resp->finalUri);
        self::assertSame('/v1/query/q-123/kill', $resp->killUri);
        self::assertFalse($resp->readFinished());
        self::assertSame(2, $resp->rowCount());
    }

    public function testReadFinished(): void
    {
        $resp = QueryResponse::fromArray(['next_uri' => '']);
        self::assertTrue($resp->readFinished());

        $resp = QueryResponse::fromArray(['next_uri' => '/v1/query/q/final']);
        self::assertTrue($resp->readFinished());

        $resp = QueryResponse::fromArray(['next_uri' => '/v1/query/q/page/2']);
        self::assertFalse($resp->readFinished());
    }

    public function testError(): void
    {
        $resp = QueryResponse::fromArray([
            'error' => ['code' => 1065, 'message' => 'syntax error', 'kind' => 'BadArguments', 'detail' => 'near FROM'],
        ]);
        self::assertSame(1065, $resp->error['code']);
        self::assertSame('syntax error', $resp->error['message']);
    }

    public function testEmptyBody(): void
    {
        $resp = QueryResponse::fromArray([]);
        self::assertSame('', $resp->id);
        self::assertTrue($resp->readFinished());
        self::assertSame(0, $resp->rowCount());
    }
}
