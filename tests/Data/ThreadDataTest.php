<?php

use Splicewire\Beam\Threads\Data\ThreadData;

it('maps the camelCase maxDepth property to the stable max_depth wire key', function () {
    $thread = ThreadData::from([
        'kind' => 'interactive',
        'mode' => 'chat',
        'max_depth' => 3,
    ]);

    expect(property_exists($thread, 'maxDepth'))->toBeTrue()
        ->and($thread->maxDepth)->toBe(3)
        ->and($thread->toArray())->toHaveKey('max_depth', 3)
        ->and($thread->toArray())->not->toHaveKey('maxDepth');
});
