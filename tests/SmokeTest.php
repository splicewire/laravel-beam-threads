<?php

use Illuminate\Database\Eloquent\Relations\Relation;

it('boots the provider and loads the threads config', function () {
    // The provider booted (registered by getPackageProviders), so its merged config is readable.
    // Beam config keys use the product word, not the `splicewire` vendor (ADR-0092) — merged under
    // `beam.threads`, not the bare `threads`.
    expect(config('beam.threads.turn_driver'))->toBeNull();
    expect(config('beam.threads.tables'))->toBe([
        'threads' => 'beam_threads',
        'messages' => 'beam_thread_messages',
        'participants' => 'beam_thread_participants',
    ]);
});

it('enforces the participant morph map with the AI-free vocabulary', function () {
    $map = Relation::morphMap();

    expect($map)->toHaveKeys(['user', 'visitor', 'system', 'external']);

    // The AI-driver participant alias is bound by the tower tier, not here.
    expect($map)->not->toHaveKey('assistant');
});

// The former "registers the shared migration dir into the tenant migrate pass" assertion tested
// BeamThreadsServiceProvider's OWN hand-rolled loadMigrationsFrom()+`--path` push — retired by the
// publish-only .stub conversion. beam-threads no longer registers ANY migration path itself; its
// `shared/` stubs publish into the HOST's `database/migrations/shared/`, and beam-tenancy's
// registerSharedMigrationsPath() is what runs that host directory in both migration passes. That
// mechanical "no loadMigrationsFrom() over its own source" invariant is now covered by
// {@see \Splicewire\Beam\Threads\Tests\Doctor\BeamThreadsMigrationsAuditTest} instead.
