<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the "lake" database driver. Auto-discovered by Laravel through
 * composer.json "extra.laravel.providers".
 *
 * Outside a Laravel application (e.g. Illuminate\Database\Capsule\Manager),
 * call LakeServiceProvider::registerDriver($capsule->getContainer()).
 */
class LakeServiceProvider extends ServiceProvider
{
    public function register()
    {
        self::registerDriver($this->app);
    }

    public static function registerDriver(Container $container): void
    {
        $container->bind('db.connector.lake', static fn (): LakeConnector => new LakeConnector());

        Connection::resolverFor(
            'lake',
            static fn ($connection, $database, $prefix, $config): LakeConnection
                => new LakeConnection($connection, $database, $prefix, $config),
        );
    }
}
