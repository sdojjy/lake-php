<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Exception;

/**
 * Raised when the connection failed after a statement was sent, so it may or
 * may not have run on the server. Such a statement is not retried unless it
 * only reads: check its effect before running it again.
 *
 * The network error is the previous exception. The message leaves it out on
 * purpose: Laravel re-runs a query whose error looks like a lost connection
 * (e.g. "Connection reset by peer"), which would repeat the statement.
 */
class QueryOutcomeUnknownException extends LakeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct(
            'The connection failed after the statement was sent, so it may or may not have run on the server. '
            . 'It was not retried because it can change data; see the previous exception for the network error.',
            0,
            $previous,
        );
    }
}
