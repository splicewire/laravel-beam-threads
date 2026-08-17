<?php

namespace Splicewire\Beam\Threads\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;

class TestCase extends Orchestra
{
    /**
     * beam-threads' `Data\*` value objects extend `Spatie\LaravelData\Data`, whose transformation path
     * reads `config('data.*')` at runtime — so without spatie's own provider merged in, `toArray()`
     * fails with a TypeError on a null config value rather than anything legible. A real host always
     * has the provider (it is auto-discovered); the package harness has to name it.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            BeamThreadsServiceProvider::class,
        ];
    }
}
