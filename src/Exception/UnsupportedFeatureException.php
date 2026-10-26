<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Exception;

/**
 * Raised, before anything is sent to the server, when a feature is not
 * available on TiDB Cloud Lake (e.g. a Laravel query builder feature that
 * has no Lake equivalent).
 */
class UnsupportedFeatureException extends LakeException
{
}
