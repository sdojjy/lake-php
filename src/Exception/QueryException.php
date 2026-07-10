<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Exception;

/**
 * Raised when a query response carries an `error` object.
 */
class QueryException extends LakeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = 0,
        public readonly string $kind = '',
        public readonly string $detail = '',
    ) {
        parent::__construct($message, $errorCode);
    }

    /**
     * @param array{code?: int, message?: string, kind?: string, detail?: string} $error
     */
    public static function fromError(array $error): self
    {
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? '');
        $kind = (string) ($error['kind'] ?? '');
        $detail = (string) ($error['detail'] ?? '');

        $text = sprintf('code: %d', $code);
        if ($message !== '') {
            $text .= ', message: ' . $message;
        }
        if ($detail !== '') {
            $text .= ', detail: ' . $detail;
        }
        if ($kind !== '') {
            $text .= ', kind: ' . $kind;
        }

        return new self($text, $code, $kind, $detail);
    }
}
