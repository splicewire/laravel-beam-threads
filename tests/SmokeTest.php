<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Splicewire\Beam\Threads\Models\Message;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Models\Thread;

it('boots the provider and loads the threads config', function () {
    // The provider booted (registered by getPackageProviders), so its merged config is readable.
    // Beam config keys use the product word, not the `splicewire` vendor (ADR-0092) — merged under
    // `beam.threads`, not the bare `threads`.
    expect(config('beam.threads.turn_driver'))->toBeNull();

    // The config declares NO table names (beam-facade ticket 17): a published config file may not
    // name the Beam facade, and the static form it replaced was silently order-dependent
    // (`beam/threads.php` sorts before `beam/core.php`, so the prefix lookup fell through to a
    // hardcoded `beam_`). Prefixing moved to the models, which resolve after boot.
    expect(config('beam.threads.tables'))->toBeNull();
});

it('resolves the prefixed table names from the models, not the config', function () {
    expect((new Thread)->getTable())->toBe('beam_threads');
    expect((new Message)->getTable())->toBe('beam_thread_messages');
    expect((new Participant)->getTable())->toBe('beam_thread_participants');
});

it('follows beam.core.table_prefix, including a retrofit host emptying it', function () {
    // The seam behaviour A3 (env literals) would have broken, and the order-dependency bug masked:
    // one knob repoints every thread table. `''` is the documented retrofit-host value.
    config()->set('beam.core.table_prefix', 'acme_');

    expect((new Thread)->getTable())->toBe('acme_threads');
    expect((new Message)->getTable())->toBe('acme_thread_messages');
    expect((new Participant)->getTable())->toBe('acme_thread_participants');

    config()->set('beam.core.table_prefix', '');

    expect((new Thread)->getTable())->toBe('threads');
    expect((new Message)->getTable())->toBe('thread_messages');
    expect((new Participant)->getTable())->toBe('thread_participants');
});

it('still honours a host-declared table name override', function () {
    // The override path A5 (stop publishing) would have removed: a host publishes the config and
    // ADDS the key, carrying a full table name because it is overriding the seam, not feeding it.
    config()->set('beam.threads.tables.threads', 'legacy_threads');

    expect((new Thread)->getTable())->toBe('legacy_threads');
    expect((new Message)->getTable())->toBe('beam_thread_messages');
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
