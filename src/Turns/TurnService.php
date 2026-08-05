<?php

namespace Splicewire\Beam\Threads\Turns;

use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Splicewire\Beam\Threads\Contracts\ParticipantTurnDriver;
use Splicewire\Beam\Threads\Data\Segment;
use Splicewire\Beam\Threads\Enums\ParticipantKind;
use Splicewire\Beam\Threads\Models\Message;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * The SUBSTRATE side of the turn seam (threads-substrate PRD §2.7, ADR-0175 §5–6) — everything beam-threads
 * owns so an agent participant can take a turn: it RESOLVES the driver ({@see TurnDriverResolver}), enforces
 * the TRIGGER policy (§6), SERIALISES one active turn per thread, ASSEMBLES the turn off the lineage walk
 * ({@see AssembledTurn::fromLeaf()}), consumes the driver's `Segment`-delta stream, and COMMITS the completed
 * agent-authored message through the beam write pipeline. The commit is FAILURE-TOLERANT
 * (create-then-advance): it opens an empty streaming CHILD up front ({@see Message::beginChild()}), then
 * {@see Message::finalizeCompletion()} on success or {@see Message::failCompletion()} on a mid-stream crash —
 * so a driver failure can never lose the partial reply or leave no record (both ride {@see ParticleWriter}).
 *
 * The driver is PURE: this service hands it a fully-resolved {@see AssembledTurn} and it returns a stream —
 * it never queries or persists. beam-threads owns the tree invariants (append as CHILD of the leaf, repoint
 * `selected_child_id`), the write, and the trigger/serialisation policy; tower owns only the concrete driver.
 *
 * AI-FREE: this class names nothing AI. It drives whatever `config('beam.threads.turn_driver')` binds.
 *
 * House style: NO `final`, NO `readonly`.
 */
class TurnService
{
    /**
     * The thread ids with a turn ACTIVE in THIS process — the in-process half of the one-active-turn guard.
     * The Postgres advisory lock is re-entrant within a session (a nested call on the same connection would
     * re-acquire), so this static set is the authoritative same-process refusal; the advisory lock covers the
     * cross-process/session case. Static (not per-instance) because the service is resolved fresh per call.
     *
     * @var array<string, true>
     */
    protected static array $active = [];

    public function __construct(
        private TurnDriverResolver $resolver,
        private ConnectionInterface $connection,
    ) {}

    /**
     * TRIGGER on a human message (ADR-0175 §6): the substrate decides WHETHER/WHOM to trigger.
     *  - 0 agents  → NO-OP (return null): a human-only thread takes no turn.
     *  - 1 agent   → AUTO-TRIGGER it (preserves today's single-agent UX).
     *  - N agents  → REFUSE ({@see TurnNotTriggerable::ambiguousAgent()}): explicit addressing required, no
     *                implicit fan-out. The caller must then invoke {@see takeTurn()} naming the participant.
     *
     * A NULL driver is also a no-op (a passive thread) — resolved before agent counting so a driverless
     * substrate never throws on a plain human message.
     */
    public function onHumanMessage(Thread $thread): ?Message
    {
        if ($this->resolver->resolve() === null) {
            return null; // passive thread — no automated turn-taking.
        }

        $agents = $this->agentsOf($thread);

        if ($agents->isEmpty()) {
            return null; // human-only thread — nothing to trigger.
        }

        if ($agents->count() > 1) {
            throw TurnNotTriggerable::ambiguousAgent($thread);
        }

        return $this->takeTurn($thread, $agents->first());
    }

    /**
     * Take a turn FOR a named agent participant (ADR-0175 §6 — never "for the thread"). Serialised (one
     * active turn per thread), capability-gated, assembled off the lineage walk, streamed, and committed as
     * a CHILD of the current leaf through {@see ParticleWriter}. Returns the persisted agent-authored message.
     *
     * @throws TurnNotTriggerable the participant is not a drivable agent, or the driver refused it
     * @throws TurnInProgress a turn is already active on the thread (serialisation guard)
     */
    public function takeTurn(Thread $thread, Participant $agent): Message
    {
        // Drain the streaming variant to its committed message. Both paths share ONE implementation
        // ({@see streamTurn()}) so the buffered and streamed turns can never diverge on assembly, the
        // serialisation guard, or the write.
        $stream = $this->streamTurn($thread, $agent);

        foreach ($stream as $_) {
            // discard the deltas — the buffered caller only wants the committed message.
        }

        return $stream->getReturn();
    }

    /**
     * The STREAMING sibling of {@see takeTurn()} (threads-substrate PRD §2.7, ADR-0175 §5) — take a turn FOR a
     * named agent participant and YIELD each {@see Segment} delta as the driver produces it, so a live caller
     * (an SSE response) can flush the reply as it streams, WHILE the substrate still owns the write.
     *
     * Identical policy to {@see takeTurn()} — driver resolution, the drivable-agent guard, the two-layer
     * one-active-turn serialisation, and assembly off the ONE selected root→leaf path. The only difference is
     * that the driver's delta stream is passed THROUGH to the caller (yielded) as well as collected. The reply
     * is committed FAILURE-TOLERANTLY (create-then-advance, PRD §2.7): an empty streaming CHILD of the leaf is
     * opened FIRST ({@see Message::beginChild()}), then finalized ({@see Message::finalizeCompletion()}) when the
     * stream completes — or, if the driver throws mid-stream, the accumulated-so-far partial is persisted +
     * flagged failed ({@see Message::failCompletion()}) before the exception re-propagates, so a partial reply
     * is never lost and always leaves a record. All writes ride the beam write pipeline ({@see ParticleWriter}).
     * The generator's RETURN is the persisted agent-authored {@see Message} — the substrate owns the write, the
     * driver persisted nothing.
     *
     * @return Generator<int, Segment, mixed, Message> the ordered Segment deltas; returns the committed reply
     *
     * @throws TurnNotTriggerable the participant is not a drivable agent, or the driver refused it
     * @throws TurnInProgress a turn is already active on the thread (serialisation guard)
     */
    public function streamTurn(Thread $thread, Participant $agent): Generator
    {
        $driver = $this->resolver->resolveOrFail();

        $this->assertDrivableAgent($driver, $thread, $agent);

        // SERIALISE — one active turn per thread. Two-layer guard: an in-process registry refuses a nested
        // same-process turn (the advisory lock is re-entrant within a session and would not), and a Postgres
        // advisory lock refuses a concurrent turn from another process/session. Both released in finally.
        $threadId = (string) $thread->getKey();

        if (isset(static::$active[$threadId])) {
            throw TurnInProgress::for($thread);
        }

        $lockKey = $this->lockKey($thread);

        if (! $this->tryLock($lockKey)) {
            throw TurnInProgress::for($thread);
        }

        static::$active[$threadId] = true;

        try {
            $root = $this->resolveRoot($thread);

            // Assemble off the ROOT (linearConversation walks downward), then take the LEAF as the message
            // the agent reply extends — both are the ONE selected path.
            $turn = AssembledTurn::fromRoot($thread, $agent, $root);

            /** @var Message $leaf */
            $leaf = $root->linearConversation()->last();

            // FAILURE-TOLERANT create-then-advance (PRD §2.7): open the agent reply as an EMPTY streaming
            // CHILD FIRST — the row (and its id) exists live the instant the turn opens, so a mid-stream
            // driver crash cannot lose the partial reply or leave no record. Two writes per turn (begin +
            // finalize/fail), NOT per-token: segments still accumulate in memory and are written once.
            $child = $leaf->beginChild((string) $agent->getKey());

            $segments = [];

            try {
                // Consume the driver's Segment-delta stream, in order, into the finalised content — AND yield
                // each delta straight through so a live caller streams it as it arrives.
                foreach ($driver->takeTurn($turn) as $delta) {
                    if ($delta instanceof Segment) {
                        $segments[] = $delta;
                        yield $delta;
                    }
                }

                // Success: finalize the accumulated content + mark complete, through the beam write pipeline.
                $child->finalizeCompletion($segments);

                return $child->fresh();
            } catch (\Throwable $e) {
                // Mid-stream failure: persist the accumulated-so-far partial + mark failed (with the error),
                // then RE-THROW. The record survives for audit; linearConversation() excludes it, so a retry
                // assembles from the last successful leaf, not the broken partial.
                $child->failCompletion($segments, $e);

                throw $e;
            }
        } finally {
            unset(static::$active[$threadId]);
            $this->unlock($lockKey);
        }
    }

    /**
     * The STREAMING sibling of {@see onHumanMessage()} — apply the same TRIGGER policy (0 agents / null driver
     * → null no-op; 1 agent → auto-trigger; N agents → refuse), but return a {@see streamTurn()} generator so a
     * live caller streams the reply. Null when there is nothing to trigger (a passive/human-only thread).
     *
     * @return Generator<int, Segment, mixed, Message>|null
     */
    public function onHumanMessageStreamed(Thread $thread): ?Generator
    {
        if ($this->resolver->resolve() === null) {
            return null; // passive thread — no automated turn-taking.
        }

        $agents = $this->agentsOf($thread);

        if ($agents->isEmpty()) {
            return null; // human-only thread — nothing to trigger.
        }

        if ($agents->count() > 1) {
            throw TurnNotTriggerable::ambiguousAgent($thread);
        }

        return $this->streamTurn($thread, $agents->first());
    }

    /**
     * The agent participants of a thread (kind = `agent`). The substrate counts these to decide the trigger
     * (single auto-trigger vs multiple explicit-addressing).
     *
     * @return Collection<int, Participant>
     */
    protected function agentsOf(Thread $thread): Collection
    {
        return Participant::query()
            ->where('thread_id', $thread->getKey())
            ->where('kind', ParticipantKind::Agent->value)
            ->get();
    }

    /**
     * Guard: the target must be an AGENT participant OF this thread, and the driver must declare it drivable
     * ({@see ParticipantTurnDriver::canTakeTurn()} — the capability gate). A future agent kind is admitted
     * purely by teaching its driver to say yes; beam-threads is untouched.
     */
    protected function assertDrivableAgent(ParticipantTurnDriver $driver, Thread $thread, Participant $agent): void
    {
        $isAgentOfThread = $agent->getAttribute('kind') === ParticipantKind::Agent
            && (string) $agent->getAttribute('thread_id') === (string) $thread->getKey();

        if (! $isAgentOfThread) {
            throw TurnNotTriggerable::notAnAgent($agent);
        }

        if (! $driver->canTakeTurn($agent)) {
            throw TurnNotTriggerable::driverRefused($agent);
        }
    }

    /**
     * The ROOT message of the thread's conversation — the parentless first message (the thread's selected
     * root variant, or the earliest). {@see Message::linearConversation()} then walks the ONE selected path
     * root → leaf off it. Assumes the thread has at least one message (a turn extends an existing
     * conversation).
     */
    protected function resolveRoot(Thread $thread): Message
    {
        return Message::query()
            ->where('thread_id', $thread->getKey())
            ->whereNull('parent_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Serialisation — one active turn per thread (Postgres advisory lock)
    // -------------------------------------------------------------------------

    /** A stable 63-bit integer lock key derived from the thread id (advisory locks are keyed on bigint). */
    protected function lockKey(Thread $thread): int
    {
        return (int) (hexdec(substr(md5('beam_threads_turn:'.$thread->getKey()), 0, 15)));
    }

    protected function tryLock(int $key): bool
    {
        // pg_try_advisory_lock returns immediately: true if acquired, false if held elsewhere. A non-pgsql
        // connection (no advisory-lock support) falls through to "acquired" — the guard is a Postgres seam.
        if ($this->connection->getDriverName() !== 'pgsql') {
            return true;
        }

        return (bool) $this->connection->selectOne(
            'SELECT pg_try_advisory_lock(?) AS locked', [$key]
        )->locked;
    }

    protected function unlock(int $key): void
    {
        if ($this->connection->getDriverName() !== 'pgsql') {
            return;
        }

        $this->connection->select('SELECT pg_advisory_unlock(?)', [$key]);
    }
}
