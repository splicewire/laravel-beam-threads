<?php

namespace Splicewire\Beam\Threads\Contracts;

use Splicewire\Beam\Threads\Data\Segment;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Turns\AssembledTurn;

/**
 * The neutral TURN-TAKING port (threads-substrate PRD §2.7, ADR-0175 §4–5, charter 04) — the seam a host
 * tier binds a concrete turn-taker behind so an agent participant can take a turn WITHOUT beam-threads ever
 * naming an AI vendor, model, or SDK.
 *
 * AI-FREE by construction. This interface names NOTHING AI — no vendor, model, or SDK, no concrete
 * agent-actor class: a "turn" is a generic capability — "given an already-assembled conversation, produce
 * the next message's content as a stream of {@see Segment} deltas." The tower tier binds a concrete driver
 * (TH-08) that wraps its own agent stack; beam-threads only ever resolves this interface from
 * `config('beam.threads.turn_driver')` and calls it. A future agent kind is drivable without touching this
 * package.
 *
 * A PORT, NOT AN EVENT (ADR-0175 §4). A turn is a SYNCHRONOUS capability with a RETURN (the streamed
 * content), not a fire-and-forget notification — so it is a called interface the substrate drives, and the
 * substrate consumes the stream and owns the write.
 *
 * THE DRIVER IS PURE — IT NEVER HITS THE DB (ADR-0175 §5). It receives a fully-resolved {@see AssembledTurn}
 * (the substrate walked the lineage and projected every message to `Segment[]` + `references` + author) and
 * returns a stream of `Segment` deltas. It reconstructs no conversation, queries no rows, and PERSISTS
 * NOTHING — the substrate commits the completed message through the beam {@see ParticleWriter}.
 *
 * Not `interface Foo` with a `final` — house style: a plain interface, freely implemented and extended.
 */
interface ParticipantTurnDriver
{
    /**
     * DRIVABILITY BY CAPABILITY (ADR-0175 §Consequences) — can THIS driver take a turn FOR this participant?
     * The substrate asks before assembling/invoking, so drivability is a capability the driver declares, not
     * a hardcode in beam-threads. A tower driver answers true for an `agent` participant whose morph resolves
     * to its concrete agent-actor; a future agent kind teaches its own driver to say yes — beam-threads is
     * untouched.
     */
    public function canTakeTurn(Participant $agent): bool;

    /**
     * Take the turn: consume the already-assembled conversation and STREAM the next message's content as
     * {@see Segment} deltas, in reading order. The final assembled content is the concatenation/finalisation
     * of the yielded deltas ({@see AssembledTurn} carries everything the driver needs — it never touches the
     * DB). The driver persists nothing; the substrate collects this stream and commits ONE agent-authored
     * message through the beam write pipeline.
     *
     * @return iterable<int, Segment> the ordered stream of content-segment deltas
     */
    public function takeTurn(AssembledTurn $turn): iterable;
}
