<?php

use Splicewire\Beam\Threads\Enums\ParticipantRole;
use Splicewire\Beam\Threads\Models\Participant;

/**
 * The in-thread capability ladder is the substrate's authorization axis, deliberately NOT routed through
 * permission-cascade's `HasVisibility` — so nothing else in the estate cross-checks it. It is expressed
 * as a rank comparison rather than a per-capability table, which is compact but has a specific failure
 * mode: change one integer in `rank()`, or an `atLeast()` `>=` into a `>`, and the whole ladder shifts
 * silently. An observer that can post is a read-only participant writing into a thread; an owner that
 * cannot close is a locked-out owner. Neither throws.
 *
 * The tests below pin the ladder ENDS (observer cannot post, owner can do everything) and the
 * monotonicity property itself, so a rank edit has to break an explicit assertion rather than a
 * spot-check that happens not to cover the changed rung.
 */
it('keeps the capability ladder monotonic — a higher role is a strict superset', function () {
    $ladder = [
        ParticipantRole::Observer,
        ParticipantRole::Member,
        ParticipantRole::Moderator,
        ParticipantRole::Owner,
    ];

    // Ranks strictly ascend in the documented order.
    $ranks = array_map(fn (ParticipantRole $r) => $r->rank(), $ladder);
    expect($ranks)->toBe([0, 1, 2, 3]);

    // And every capability a lower role has, every higher role also has.
    foreach ($ladder as $i => $lower) {
        foreach (array_slice($ladder, $i) as $higher) {
            foreach (['canPost', 'canModerate', 'canClose'] as $capability) {
                if ($lower->{$capability}()) {
                    expect($higher->{$capability}())
                        ->toBeTrue("{$higher->value} must inherit {$capability} from {$lower->value}");
                }
            }
        }
    }
});

it('holds the observer read-only floor', function () {
    // The one rung whose whole purpose is refusal. An observer that can post is an invisible ACL hole.
    expect(ParticipantRole::Observer->canPost())->toBeFalse();
    expect(ParticipantRole::Observer->canModerate())->toBeFalse();
    expect(ParticipantRole::Observer->canClose())->toBeFalse();
});

it('opens posting at member, moderation at moderator, and closing at owner only', function () {
    expect(ParticipantRole::Member->canPost())->toBeTrue();
    expect(ParticipantRole::Member->canModerate())->toBeFalse();
    expect(ParticipantRole::Member->canClose())->toBeFalse();

    expect(ParticipantRole::Moderator->canPost())->toBeTrue();
    expect(ParticipantRole::Moderator->canModerate())->toBeTrue();
    expect(ParticipantRole::Moderator->canClose())->toBeFalse();

    expect(ParticipantRole::Owner->canPost())->toBeTrue();
    expect(ParticipantRole::Owner->canModerate())->toBeTrue();
    expect(ParticipantRole::Owner->canClose())->toBeTrue();
});

it('answers atLeast against every floor, inclusive of the floor itself', function () {
    // `atLeast` is the shared primitive under all three gates — an off-by-one here moves all of them.
    expect(ParticipantRole::Member->atLeast(ParticipantRole::Member))->toBeTrue();
    expect(ParticipantRole::Member->atLeast(ParticipantRole::Observer))->toBeTrue();
    expect(ParticipantRole::Member->atLeast(ParticipantRole::Moderator))->toBeFalse();
});

it('gates the participant model off the role enum and refuses a roleless row', function () {
    // The model's gates guard against a NULL role (a row written before the column existed, or a host
    // insert that omitted it). Defaulting a null role to "can post" would be the worst possible failure,
    // so the model asserts `instanceof` rather than truthiness — pin that.
    $observer = new Participant(['role' => ParticipantRole::Observer->value, 'display_name' => 'O']);
    $owner = new Participant(['role' => ParticipantRole::Owner->value, 'display_name' => 'Ow']);
    $roleless = new Participant(['display_name' => 'Nobody']);

    expect($observer->canPost())->toBeFalse();
    expect($owner->canPost())->toBeTrue();
    expect($owner->canModerate())->toBeTrue();
    expect($owner->canClose())->toBeTrue();

    expect($roleless->canPost())->toBeFalse();
    expect($roleless->canModerate())->toBeFalse();
    expect($roleless->canClose())->toBeFalse();
});
