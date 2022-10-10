<?php

declare(strict_types=1);

namespace ProductTrap\ColesAustralia\Tests;

use ProductTrap\ProductTrapServiceProvider;
use ProductTrap\ColesAustralia\ColesAustraliaServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ProductTrapServiceProvider::class, ColesAustraliaServiceProvider::class];
    }
}
