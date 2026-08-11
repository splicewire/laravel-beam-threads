<?php

namespace Splicewire\Beam\Threads\Tests\Doctor;

use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Threads\Doctor\BeamThreadsMigrationsAudit;
use Splicewire\Beam\Threads\Tests\TestCase;

/**
 * beam-threads' own operator check: its migrations must stay publish-only .stub files. Mirrors the
 * per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology`) —
 * a thin test wrapping a shared engine, declaring only "which audit is mine."
 */
class BeamThreadsMigrationsAuditTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_threads_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsAuditClass(): string
    {
        return BeamThreadsMigrationsAudit::class;
    }
}
