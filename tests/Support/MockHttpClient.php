<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 mock returning queued responses and recording every request.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> request bodies, since streams are single-read */
    public array $bodies = [];

    /** @param list<ResponseInterface> $queue */
    public function __construct(private array $queue = [])
    {
    }

    public function push(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();
        if ($this->queue === []) {
            throw new \RuntimeException('no queued mock response for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return array_shift($this->queue);
    }
}
