<?php

declare(strict_types=1);

namespace ProductTrap\ColesAustralia;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use ProductTrap\Contracts\Factory;
use ProductTrap\ProductTrap;

class ColesAustraliaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var ProductTrap $factory */
        $factory = $this->app->make(Factory::class);

        $factory->extend(ColesAustralia::IDENTIFIER, function () {
            /** @var CacheRepository $cache */
            $cache = $this->app->make(CacheRepository::class);

            return new ColesAustralia(
                cache: $cache,
            );
        });
    }
}
