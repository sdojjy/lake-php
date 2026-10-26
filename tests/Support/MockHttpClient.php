<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 mock returning queued responses and recording every request.
 *
 * A queued entry is either a response or a callable building one from the
 * request and its body.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> request bodies, since streams are single-read */
    public array $bodies = [];

    /** @param list<ResponseInterface|callable(RequestInterface, string): ResponseInterface> $queue */
    public function __construct(private array $queue = [])
    {
    }

    /**
     * @param ResponseInterface|callable(RequestInterface, string): ResponseInterface $response
     */
    public function push(ResponseInterface|callable $response): void
    {
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $this->requests[] = $request;
        $this->bodies[] = $body;
        if ($this->queue === []) {
            throw new \RuntimeException('no queued mock response for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        $response = array_shift($this->queue);

        return $response instanceof ResponseInterface ? $response : $response($request, $body);
    }
}
