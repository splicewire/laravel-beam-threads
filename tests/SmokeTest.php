<?php

use Illuminate\Database\Eloquent\Relations\Relation;

it('boots the provider and loads the threads config', function () {
    // The provider booted (registered by getPackageProviders), so its merged config is readable.
    expect(config('threads.turn_driver'))->toBeNull();
    expect(config('threads.tables'))->toBe([
        'threads' => 'threads',
        'messages' => 'thread_messages',
        'participants' => 'thread_participants',
    ]);
});

it('enforces the participant morph map with the AI-free vocabulary', function () {
    $map = Relation::morphMap();

    expect($map)->toHaveKeys(['user', 'visitor', 'system', 'external']);

    // The AI-driver participant alias is bound by the tower tier, not here.
    expect($map)->not->toHaveKey('assistant');
});

it('registers the shared migration dir into the tenant migrate pass', function () {
    $paths = config('tenancy.migration_parameters.--path', []);

    expect($paths)->toContain(realpath(__DIR__.'/../database/migrations/shared'));
});
