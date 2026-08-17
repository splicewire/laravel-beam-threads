<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Splicewire\Beam\Threads\Enums\ParticipantKind;
use Splicewire\Beam\Threads\Enums\ParticipantRole;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Models\Thread;
use Splicewire\Beam\Threads\Tests\Fakes\FakeTurnDriver;
use Splicewire\Beam\Threads\Turns\TurnDriverResolver;
use Splicewire\Beam\Threads\Turns\TurnNotTriggerable;
use Splicewire\Beam\Threads\Turns\TurnService;

/**
 * The TRIGGER policy (ADR-0175 §6) is the substrate's answer to "a human just posted — does anything
 * reply, and if so, what?". Its three branches each guard a different real failure:
 *
 *  - 0 agents / no driver → NO-OP. This is the branch every ordinary human message on a bare beam site
 *    takes. Turning it into a throw (or letting it fall through to `resolveOrFail()`) would break plain
 *    messaging everywhere, which is why the driver check comes FIRST, before agents are even counted.
 *  - exactly 1 agent → auto-trigger. The single-agent UX the estate already had.
 *  - N agents → REFUSE. No implicit fan-out. This is the branch that costs money if it regresses: a
 *    "helpful" loop over all agents would fire N model calls per human message, silently, and each one
 *    would race the others to append to a linear path that admits one leaf.
 *
 * The drivability guard is tested alongside it because it is the other pre-flight refusal: a turn is
 * invoked FOR a named participant, and that participant must be an agent OF THIS THREAD. Dropping the
 * thread_id half would let a caller drive an agent belonging to someone else's conversation.
 *
 * Backed by the package's own shared migration stubs on the harness's sqlite connection, so the agent
 * count is a real indexed query rather than a mocked collection.
 */
beforeEach(function () {
    foreach ([
        'create_threads_table',
        'create_thread_participants_table',
        'create_thread_messages_table',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
    }

    $this->thread = (new Thread)->forceFill(['id' => (string) Str::uuid()]);

    $this->service = fn () => new TurnService(
        new TurnDriverResolver($this->app),
        $this->app['db']->connection(),
    );

    $this->addParticipant = function (ParticipantKind $kind, ?string $threadId = null) {
        return Participant::create([
            'thread_id' => $threadId ?? $this->thread->getKey(),
            'kind' => $kind->value,
            'role' => ParticipantRole::Member->value,
            'display_name' => ucfirst($kind->value),
        ]);
    };
});

it('takes no turn on a passive thread, even when an agent is present', function () {
    // The driver check must precede the agent count: a bare beam site with an agent participant but no
    // bound driver is a perfectly ordinary state, not an error.
    config()->set('beam.threads.turn_driver', null);
    ($this->addParticipant)(ParticipantKind::Agent);

    expect(($this->service)()->onHumanMessage($this->thread))->toBeNull();
    expect(($this->service)()->onHumanMessageStreamed($this->thread))->toBeNull();
});

it('takes no turn on a human-only thread', function () {
    config()->set('beam.threads.turn_driver', new FakeTurnDriver);
    ($this->addParticipant)(ParticipantKind::Human);
    ($this->addParticipant)(ParticipantKind::Visitor);

    expect(($this->service)()->onHumanMessage($this->thread))->toBeNull();
    expect(($this->service)()->onHumanMessageStreamed($this->thread))->toBeNull();
});

it('refuses to fan out across multiple agents and demands explicit addressing', function () {
    config()->set('beam.threads.turn_driver', new FakeTurnDriver);
    ($this->addParticipant)(ParticipantKind::Agent);
    ($this->addParticipant)(ParticipantKind::Agent);

    expect(fn () => ($this->service)()->onHumanMessage($this->thread))
        ->toThrow(TurnNotTriggerable::class, 'MULTIPLE agent participants');

    expect(fn () => ($this->service)()->onHumanMessageStreamed($this->thread))
        ->toThrow(TurnNotTriggerable::class, 'MULTIPLE agent participants');
});

it('counts agents of THIS thread only', function () {
    // Two agents exist, but only one is a member here — the trigger must auto-select, not refuse as
    // ambiguous. A missing thread_id predicate would make every thread in the estate look ambiguous.
    config()->set('beam.threads.turn_driver', new FakeTurnDriver);
    ($this->addParticipant)(ParticipantKind::Agent);
    ($this->addParticipant)(ParticipantKind::Agent, (string) Str::uuid());

    // The thread has no messages, so the auto-triggered turn dies at the root-message lookup. Reaching
    // THAT failure is the assertion: the trigger policy resolved to exactly one agent and proceeded.
    // A missing thread_id predicate would surface here as an ambiguity refusal instead.
    expect(fn () => ($this->service)()->onHumanMessage($this->thread))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses a turn for a participant that is not an agent', function () {
    config()->set('beam.threads.turn_driver', new FakeTurnDriver);
    $human = ($this->addParticipant)(ParticipantKind::Human);

    $stream = ($this->service)()->streamTurn($this->thread, $human);

    expect(fn () => iterator_to_array($stream))
        ->toThrow(TurnNotTriggerable::class, 'is not a drivable agent of this thread');
});

it('refuses a turn for an agent belonging to another thread', function () {
    // The cross-thread guard. Without the thread_id half of the check, a caller holding any agent
    // participant id could drive it against an unrelated conversation.
    config()->set('beam.threads.turn_driver', new FakeTurnDriver);
    $foreign = ($this->addParticipant)(ParticipantKind::Agent, (string) Str::uuid());

    $stream = ($this->service)()->streamTurn($this->thread, $foreign);

    expect(fn () => iterator_to_array($stream))
        ->toThrow(TurnNotTriggerable::class, 'is not a drivable agent of this thread');
});

it('honours the driver capability gate when it declines a participant', function () {
    // Drivability is the DRIVER's declaration, not a hardcode in beam-threads — the whole reason a
    // future agent kind needs no change here. A refusal must be a clean TurnNotTriggerable, not a crash
    // partway through assembly.
    config()->set('beam.threads.turn_driver', new FakeTurnDriver(drivable: false));
    $agent = ($this->addParticipant)(ParticipantKind::Agent);

    $stream = ($this->service)()->streamTurn($this->thread, $agent);

    expect(fn () => iterator_to_array($stream))
        ->toThrow(TurnNotTriggerable::class, 'cannot take a turn for agent participant');
});

it('refuses an explicit turn outright when no driver is bound', function () {
    config()->set('beam.threads.turn_driver', null);
    $agent = ($this->addParticipant)(ParticipantKind::Agent);

    $stream = ($this->service)()->streamTurn($this->thread, $agent);

    expect(fn () => iterator_to_array($stream))
        ->toThrow(RuntimeException::class, 'No turn driver is bound');
});
