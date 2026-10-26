<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Laravel\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Owner of {@see UserLinkAttributionLog} rows, for relation tests.
 */
class LinkUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public function logs(): HasMany
    {
        return $this->hasMany(UserLinkAttributionLog::class, 'user_id');
    }
}
