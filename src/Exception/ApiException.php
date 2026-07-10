<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Exception;

/**
 * Raised when the Lake HTTP API responds with a non-200 status code.
 */
class ApiException extends LakeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $responseBody = '',
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(int $statusCode, string $responseBody): self
    {
        $hint = match (true) {
            $statusCode === 401 => 'authorization failed',
            $statusCode > 500 => 'please retry again later',
            $statusCode === 500 => 'internal server error',
            $statusCode >= 400 => 'please check your arguments',
            default => 'unexpected HTTP status code',
        };

        $message = '';
        $decoded = json_decode($responseBody, true);
        if (is_array($decoded)) {
            // Bodies come as {"message": "..."}, {"error": "..."} or the
            // query-style nested {"error": {"code": ..., "message": "..."}}.
            $candidate = $decoded['message'] ?? $decoded['error'] ?? '';
            if (is_array($candidate)) {
                $candidate = $candidate['message'] ?? json_encode($candidate);
            }
            $message = (string) $candidate;
        }
        if ($message === '') {
            $message = $responseBody;
        }

        return new self(sprintf('%d %s. %s', $statusCode, rtrim($message, '.'), $hint), $statusCode, $responseBody);
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isAuthFailed(): bool
    {
        return $this->statusCode === 401;
    }
}
