<?php

namespace Splicewire\Beam\Threads\Doctor;

use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;

class BeamThreadsMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-threads';
    }

    protected function serviceProviderClass(): string
    {
        return BeamThreadsServiceProvider::class;
    }
}
