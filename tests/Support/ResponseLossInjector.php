<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client for fault injection against a real server: once armed, it
 * sends the next query whose SQL starts with the given prefix, so the server
 * runs it, then drops the response the way Guzzle reports a connection reset.
 */
final class ResponseLossInjector implements ClientInterface
{
    private ?string $prefix = null;

    /** @var list<string> SQL of every query sent */
    public array $sent = [];

    public function __construct(private readonly ClientInterface $inner = new GuzzleClient(['http_errors' => false]))
    {
    }

    public function loseResponseTo(string $sqlPrefix): void
    {
        $this->prefix = $sqlPrefix;
    }

    /**
     * @return int how many times a query starting with $sqlPrefix was sent
     */
    public function timesSent(string $sqlPrefix): int
    {
        return count(array_filter($this->sent, static fn (string $sql): bool => str_starts_with($sql, $sqlPrefix)));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $sql = (string) (json_decode((string) $request->getBody(), true)['sql'] ?? '');
        $request->getBody()->rewind();
        if ($sql !== '') {
            $this->sent[] = $sql;
        }

        $response = $this->inner->sendRequest($request);
        if ($this->prefix !== null && str_starts_with($sql, $this->prefix)) {
            $this->prefix = null;

            throw new RequestException(
                'cURL error 56: Recv failure: Connection reset by peer',
                $request,
                null,
                null,
                ['errno' => 56, 'request_size' => strlen((string) $request->getBody())],
            );
        }

        return $response;
    }
}
