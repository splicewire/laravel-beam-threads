<?php

use Splicewire\Beam\Threads\Data\Reference;
use Splicewire\Beam\Threads\Data\Segment;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Models\Thread;
use Splicewire\Beam\Threads\Turns\AssembledTurn;
use Splicewire\Beam\Threads\Turns\TurnMessage;

/**
 * {@see AssembledTurn} is the IN side of the turn port, and its whole contract is "the driver never
 * touches the DB". That is a STRUCTURAL claim: every field must already be materialised, with no lazy
 * relation left on the object. Adding a convenience accessor that reached back through a model relation
 * would break the port's guarantee without breaking any assertion about the driver's output — the
 * symptom would be an N+1 (or a query on a detached connection) inside a streaming response.
 *
 * {@see AssembledTurn::leaf()} is the small piece a driver actually calls to find "the message I am
 * replying to". Its empty-path branch is the one a first-turn conversation hits.
 */
function turnMessage(string $id, array $segments = []): TurnMessage
{
    return new TurnMessage(messageId: $id, segments: $segments);
}

it('reports no leaf for an empty conversation rather than erroring', function () {
    $turn = new AssembledTurn(new Thread, new Participant);

    expect($turn->path)->toBe([]);
    expect($turn->leaf())->toBeNull();
});

it('reports the LAST message of the path as the leaf', function () {
    // Not the first, and not "the one with no children" recomputed from the rows — the path is already
    // the single selected root→leaf walk, so the leaf is positional.
    $turn = new AssembledTurn(new Thread, new Participant, [
        turnMessage('m1'),
        turnMessage('m2'),
        turnMessage('m3'),
    ]);

    expect($turn->leaf()->messageId)->toBe('m3');
});

it('carries the agent the turn is taken FOR, never merely the thread', function () {
    // ADR-0175 §6: a turn is always addressed to a named participant. A driver reads the agent off the
    // turn to decide which persona/config it is speaking as; dropping it would make multi-agent threads
    // silently answer as the wrong member.
    $agent = new Participant(['display_name' => 'Assistant']);
    $turn = new AssembledTurn(new Thread, $agent);

    expect($turn->agent)->toBe($agent);
});

it('projects a message into DB-detached scalars plus rebuilt value objects', function () {
    // The projection shape the driver consumes: content as Segment[], out-of-band records as
    // Reference[], and the author flattened to scalars (kind token + display snapshot) so resolving
    // "who said this" can never trigger a query.
    $message = new TurnMessage(
        messageId: 'm1',
        segments: [Segment::text('hi'), Segment::reference('r1', '[1]')],
        references: [Reference::citation('r1', 'Source')],
        authorParticipantId: 'p1',
        authorKind: 'human',
        authorName: 'Ada',
    );

    expect($message->segments)->toHaveCount(2);
    expect($message->segments[0])->toBeInstanceOf(Segment::class);
    expect($message->references[0])->toBeInstanceOf(Reference::class);
    expect($message->authorKind)->toBe('human');
    expect($message->authorName)->toBe('Ada');

    // Scalars only — no model, no relation, nothing lazy.
    foreach (['messageId', 'authorParticipantId', 'authorKind'] as $field) {
        expect($message->{$field})->toBeString();
    }
});

it('defaults an author-less projection to empty scalars, never null-object model reads', function () {
    // A system-authored or orphaned message projects with an empty kind rather than a null the driver
    // would have to guard — that default is what keeps `authorKind` a plain string on the port.
    $message = new TurnMessage(messageId: 'm1');

    expect($message->authorKind)->toBe('');
    expect($message->authorParticipantId)->toBe('');
    expect($message->authorName)->toBeNull();
    expect($message->segments)->toBe([]);
    expect($message->references)->toBe([]);
});
