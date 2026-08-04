<?php

namespace Splicewire\Beam\Threads\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BeamThreadsServiceProvider::class];
    }
}
