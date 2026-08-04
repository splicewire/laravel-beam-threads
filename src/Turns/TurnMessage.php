<?php

namespace Splicewire\Beam\Threads\Turns;

use Splicewire\Beam\Threads\Data\Reference;
use Splicewire\Beam\Threads\Data\Segment;

/**
 * One message of an {@see AssembledTurn}'s linear path, PROJECTED off the persisted {@see Message} row into a
 * pure, DB-detached value (threads-substrate PRD §2.7, ADR-0175 §5). The substrate builds these when it
 * assembles the turn; the driver reads them and NEVER re-queries the DB.
 *
 * It carries exactly what a driver needs to reconstruct the conversation turn-by-turn:
 *  - `segments`   — the message's ordered `Segment[]` content (Axis A: text runs + inline reference markers).
 *  - `references` — the message's out-of-band `Reference[]` records (Axis B: citations + injected/sidecar).
 *  - `author*`    — the authoring participant's identity, resolved off the {@see Participant} at assembly
 *                   time (kind + display snapshot) so the driver can map who-said-what without a DB read.
 *
 * House style: plain mutable promoted params, NO `readonly`, NO `final`.
 */
class TurnMessage
{
    /**
     * @param  string  $messageId  the source message row id (identity only; the driver still never queries it)
     * @param  Segment[]  $segments  the ordered content segments of this message
     * @param  Reference[]  $references  the out-of-band reference records of this message
     * @param  string  $authorParticipantId  the id of the authoring participant
     * @param  string  $authorKind  the authoring participant's kind token (`human`/`agent`/…)
     * @param  string|null  $authorName  the authoring participant's display snapshot (may be null)
     */
    public function __construct(
        public string $messageId,
        public array $segments = [],
        public array $references = [],
        public string $authorParticipantId = '',
        public string $authorKind = '',
        public ?string $authorName = null,
    ) {}
}
