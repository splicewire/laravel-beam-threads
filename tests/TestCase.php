<?php

namespace Splicewire\Beam\Threads\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;

class TestCase extends Orchestra
{
    /**
     * beam-threads' `Data\*` value objects extend `Spatie\LaravelData\Data`, whose transformation path
     * reads `config('data.*')` at runtime — so without spatie's own provider merged in, `toArray()`
     * fails with a TypeError on a null config value rather than anything legible. A real host always
     * has the provider (it is auto-discovered); the package harness has to name it.
     *
     * LaravelDataSchemasServiceProvider is named for the same reason: it binds the DataSchemas
     * `Generator` interface that ConfigSectionSchema depends on, which the config-section coverage
     * test resolves when it validates the package defaults against the generated schema (the same
     * check the host audit runs). A real host auto-discovers it; the package harness must name it.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            BeamThreadsServiceProvider::class,
        ];
    }
}
