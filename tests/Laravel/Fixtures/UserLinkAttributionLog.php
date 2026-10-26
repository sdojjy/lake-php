<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Laravel\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Shaped like the customer model the Laravel driver is built for.
 */
class UserLinkAttributionLog extends Model
{
    protected $table = 'user_link_attribution_logs';

    protected $guarded = [];
}
