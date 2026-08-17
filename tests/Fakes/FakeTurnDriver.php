<?php

namespace Splicewire\Beam\Threads\Tests\Fakes;

use Splicewire\Beam\Threads\Contracts\ParticipantTurnDriver;
use Splicewire\Beam\Threads\Data\Segment;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Turns\AssembledTurn;

/**
 * A {@see ParticipantTurnDriver} that yields caller-supplied segments and records the turn it was
 * handed. The port exists precisely so beam-threads can be exercised with no AI vendor present, so a
 * fake driver is not a compromise here — it is the intended free-tier shape.
 */
class FakeTurnDriver implements ParticipantTurnDriver
{
    public ?AssembledTurn $receivedTurn = null;

    /**
     * @param  list<Segment>  $segments
     */
    public function __construct(
        private array $segments = [],
        private bool $drivable = true,
    ) {}

    public function canTakeTurn(Participant $agent): bool
    {
        return $this->drivable;
    }

    public function takeTurn(AssembledTurn $turn): iterable
    {
        $this->receivedTurn = $turn;

        yield from $this->segments;
    }
}
