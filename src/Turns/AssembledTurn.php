<?php

namespace Splicewire\Beam\Threads\Turns;

use Splicewire\Beam\Threads\Data\Reference;
use Splicewire\Beam\Threads\Data\Segment;
use Splicewire\Beam\Threads\Models\Message;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * The fully-resolved TURN CONTEXT the substrate hands a {@see ParticipantTurnDriver} (threads-substrate
 * PRD §2.7, ADR-0175 §5) — the IN side of the port. It is built BY THE SUBSTRATE (beam-threads owns the
 * lineage walk); the driver receives it complete and NEVER hits the DB.
 *
 * It carries:
 *  - the `Thread` header context,
 *  - the AGENT `Participant` the turn is being taken FOR (never "for the thread" — ADR-0175 §6),
 *  - the LINEAR message path (root → selected leaf, walked via `selected_child_id` / the thread's
 *    `selected_root_id`, per {@see Message::linearConversation()}), each row projected to a pure
 *    {@see TurnMessage} (`Segment[]` + `references` + author) so the conversation is reconstructable with
 *    zero further queries.
 *
 * BUILT FROM `linearConversation()` (ADR-0175 §5). {@see fromLeaf()} takes the current leaf message + the
 * agent participant, walks the ONE selected path, and freezes each message into a {@see TurnMessage}. Every
 * field a driver needs is materialised here, so the port's "driver never queries the DB" invariant is
 * structurally guaranteed — there is nothing on this object that lazy-loads a relation.
 *
 * House style: plain mutable promoted params, NO `readonly`, NO `final`.
 */
class AssembledTurn
{
    /**
     * @param  Thread  $thread  the thread header context
     * @param  Participant  $agent  the agent participant the turn is taken FOR
     * @param  TurnMessage[]  $path  the linear conversation, root → selected leaf, each a pure projection
     */
    public function __construct(
        public Thread $thread,
        public Participant $agent,
        public array $path = [],
    ) {}

    /**
     * Assemble a turn for `$agent` from the conversation's `$root` message: walk the ONE selected path
     * ({@see Message::linearConversation()} — root → selected leaf via `selected_child_id`, the root chosen
     * off the thread's `selected_root_id`) and project each message into a DB-detached {@see TurnMessage}
     * (`Segment[]` content + `references` + resolved author identity). The whole root→leaf path is
     * materialised, so the returned object is complete; the driver reads it without touching the DB.
     *
     * The walk MUST start at the root (not the leaf): {@see Message::linearConversation()} walks DOWNWARD
     * from its receiver, so calling it on the leaf would yield only the leaf. The substrate resolves the
     * root and hands it here.
     */
    public static function fromRoot(Thread $thread, Participant $agent, Message $root): self
    {
        $path = [];

        foreach ($root->linearConversation() as $message) {
            $path[] = static::projectMessage($message);
        }

        return new self($thread, $agent, $path);
    }

    /**
     * Project one persisted {@see Message} into a pure {@see TurnMessage}: rebuild its `Segment[]` +
     * `Reference[]` off the (already-loaded, migrate-on-read-reconciled) `payload`, and snapshot the
     * authoring participant's identity. No relation is left lazy — the author is resolved to scalars here so
     * the driver can never trigger a query off the projection.
     */
    protected static function projectMessage(Message $message): TurnMessage
    {
        $payload = (array) ($message->getAttribute('payload') ?? []);

        $segments = array_map(
            fn (array $raw): Segment => Segment::from($raw),
            array_values((array) ($payload['content'] ?? [])),
        );

        $references = array_map(
            fn (array $raw): Reference => Reference::from($raw),
            array_values((array) ($payload['references'] ?? [])),
        );

        $author = $message->participant()->first();

        return new TurnMessage(
            messageId: (string) $message->getKey(),
            segments: $segments,
            references: $references,
            authorParticipantId: (string) $message->getAttribute('participant_id'),
            authorKind: $author?->getAttribute('kind')?->value ?? '',
            authorName: $author?->getAttribute('display_name'),
        );
    }

    /** The current leaf (last message of the linear path), or null for an empty conversation. */
    public function leaf(): ?TurnMessage
    {
        return $this->path === [] ? null : $this->path[count($this->path) - 1];
    }
}
