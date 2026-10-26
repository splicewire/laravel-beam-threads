<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Splicewire\Beam\Threads\Models\Thread;

/*
 * EP-42 (2026-10-26): spatie/laravel-sluggable 4.0.5 gave HasSlug a resolveRouteBindingQuery, which laravel/framework
 * 13.38's HasUuids (here through PersistsBeamParticle) also defines. With both releases, Thread did not load: a PHP
 * fatal on any host that loads it. A thread binds by its uuid key (no getRouteKeyName override) with self-healing URLs
 * off, so it takes HasUuids' choice: a malformed key is not found, before any query.
 */
it('loads, and answers a malformed route key as not found before any query', function () {
    expect(fn () => (new Thread)->resolveRouteBinding('not-a-uuid'))->toThrow(ModelNotFoundException::class);
});
