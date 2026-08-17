<?php

use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Enums\ThreadMode;

/**
 * `mode` is a render HINT, never a storage fork (ADR-0176's mode-invariance) — and the one number it
 * seeds, `max_depth`, has a genuinely surprising encoding: `null` means UNBOUNDED, not "unset". Any
 * code that reads it with a `?? 1` or a `(int)` cast collapses forum's unbounded nesting to flat, which
 * renders as "replies disappeared" rather than as an error.
 *
 * This pins the three seeds and the null-means-unbounded semantics, so a later `int` type hint on
 * `defaultMaxDepth()` cannot slip through.
 */
it('seeds the documented reply-nesting cap per mode, with null meaning unbounded', function () {
    expect(ThreadMode::Chat->defaultMaxDepth())->toBe(1);
    expect(ThreadMode::Board->defaultMaxDepth())->toBe(2);
    expect(ThreadMode::Forum->defaultMaxDepth())->toBeNull();
});

it('keeps mode orthogonal to kind — neither vocabulary leaks into the other', function () {
    // `kind ⟂ mode` is stated in both enums' docblocks and is the reason a single stored shape serves
    // all three renders. A token appearing in both would mean the axes had been collapsed.
    $modes = array_map(fn (ThreadMode $m) => $m->value, ThreadMode::cases());
    $kinds = array_map(fn (ThreadKind $k) => $k->value, ThreadKind::cases());

    expect($modes)->toBe(['chat', 'board', 'forum']);
    expect($kinds)->toBe(['interactive', 'embed_template', 'embed_session']);
    expect(array_intersect($modes, $kinds))->toBe([]);
});
