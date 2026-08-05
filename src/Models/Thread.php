<?php

namespace Splicewire\Beam\Threads\Models;

use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\Contracts\Versionable;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Threads\Data\ThreadData;
use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Enums\ThreadMode;
use Splicewire\Beam\Threads\Transitions\ReModeOutcome;
use Splicewire\Beam\Threads\Transitions\ThreadModeLocked;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The generic thread particle (threads-substrate PRD §2.3, charter 02) — the successor to beam-core's
 * retired `ChatBase` (removed in TH-07), re-cast as a schema-typed, snapshot-versioned,
 * migrate-on-read particle. A thread is a THIN aggregate header: its config is the {@see ThreadData}
 * payload; its messages are NOT folded in (they reference `thread_id` and hydrate as a relation on their
 * OWN independent version stream — landed in a later ticket).
 *
 * It composes the three beam-core particle disciplines, exactly like {@see BeamParticle}:
 *  - {@see PersistsBeamParticle} — the uuid7 (HasUuids ⇒ time-ordered uuid7) particle skeleton +
 *    `payload`/`meta` casts; the write goes THROUGH beam-core's shared {@see ParticleWriter}
 *    (authorize → validate → persist → emit), never a fork.
 *  - {@see ReconcilesPayloadOnRead} — migrate-on-read forward as `ThreadData` evolves, AND the
 *    read-at-version PIN ({@see withPinnedSchemaVersion()}) an `embed_session` uses so a visitor session
 *    frozen under schema vN renders under vN.
 *  - {@see VersionableTrait} — config revision history + restore; the `embed_session` frozen-config
 *    snapshot folds HERE (it is a named version snapshot, HEAD pinned on `head_version`), inheriting
 *    ChatBase's embed→version mechanism on the generic substrate.
 *
 * REAL COLUMNS vs PAYLOAD. `kind` / `mode` / `max_depth` mirror into flat, queryable columns (a thread
 * is filtered/listed by mode and kind) AND live in the `ThreadData` payload (the schema-typed source of
 * truth the writer validates + migrate-on-read reconciles). The mirror is written from the payload in
 * {@see fillFromSchemaPayload()}, so the payload stays canonical and the columns are a projection.
 *
 * NO REALM anywhere (ADR-0176 §2): no realm column, no pin, no override — not on this model, not in
 * `ThreadData`, not in config. Realm is the reused, ambient, UNSTORED route axis the render layer reads
 * via `RealmRegistry::effective()`.
 *
 * Derived facts (message/participant counts, last-activity) are computed/cached ACCESSORS, never stored
 * schema fields, so they never drive a migration (charter 02 §4).
 *
 * AI-FREE by construction (ADR-0173): no assistant/model/tool machinery here — the AI participant enters
 * at the tower tier behind a neutral turn-driver port, never inside this substrate.
 *
 * Not final — a host may extend it (add relations/columns, pin a connection) and repoint a swappable
 * binding at the subclass.
 */
class Thread extends Model implements Versionable
{
    use HasSlug;
    use PersistsBeamParticle;
    use ReconcilesPayloadOnRead;
    use VersionableTrait;

    /**
     * The schema BINDING this particle is written under — the {@see ThreadData} class FQCN. Binding to
     * the FQCN (not a bare stem) is what makes the host's `SchemaTargetResolver` recognise it as a SYSTEM
     * schema and project the current target live from the class (`isSystemSchemaClass` requires
     * `class_exists`). `recordType()` returns the FQCN unchanged (SchemaId sees no digit tail), the writer
     * resolves + validates the target schema from it, and migrate-on-read / `readAtVersion` resolve
     * versions from the class's `schemaName()` stem. One source of truth for the binding (model, writer,
     * test all reference it).
     */
    public const SCHEMA_REF = ThreadData::class;

    /**
     * The queryable projection columns + the particle machinery columns. `kind`/`mode` cast to their
     * enums; `max_depth` is a plain nullable int; `payload`/`meta` come from {@see PersistsBeamParticle}.
     * The versioning columns (`schema_id`, `migration_status`, `head_version`) are trait-managed, not
     * fillable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'schema_ref',
        'title',
        'slug',
        'kind',
        'mode',
        'max_depth',
        // The SERVER-DURABLE selected ROOT variant (TH-04, Ruling B). Root messages that are edits/regens
        // of each other are siblings at the root with no parent row to hold a `selected_child_id`, so the
        // thread holds the pointer to the selected first-message variant. Default = the newest root sibling.
        'selected_root_id',
        // The SPIN-OFF origin pointer (TH-06, ADR-0176 §4) — the SOURCE message (in ANOTHER thread) this
        // thread was spun off from ("forum post → open a chat"). The ONLY thread-to-thread link; a soft
        // uuid pointer set once at spin-off creation, null on an ordinary thread.
        'origin_message_id',
        'payload',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ThreadKind::class,
            'mode' => ThreadMode::class,
        ];
    }

    /**
     * The thread table — the config table-prefix seam ({@see config('beam.threads.tables.threads')}). A
     * property default cannot call config(), so it is resolved here, mirroring how beam particles resolve
     * their prefixed table name.
     */
    public function getTable(): string
    {
        return config('beam.threads.tables.threads', 'threads');
    }

    /**
     * The stable morph alias — the polymorphic key this particle writes into `beam_versions.versionable_type`
     * (and the permission-token prefix). The durable token, NOT the FQCN, so a later class rename leaves
     * stored version rows resolvable (mirrors {@see BeamParticle::getMorphClass()}).
     */
    public function getMorphClass(): string
    {
        return 'thread';
    }

    /** The JSON column holding the schema-typed {@see ThreadData} payload. */
    public function payloadColumn(): string
    {
        return 'payload';
    }

    /**
     * Slug generation (TH-07 header migration): derive the URL `slug` from the header `title`. A titleless
     * thread (many particle rows carry no title) yields a NULL slug (`skipGenerateWhen` blank-title guard),
     * and `doNotGenerateSlugsOnUpdate` keeps the slug stable once set so an edited title never breaks a link.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->skipGenerateWhen(fn () => blank($this->title))
            ->doNotGenerateSlugsOnUpdate();
    }

    /**
     * The beam write-pipeline persist seam ({@see ParticleWriter}). The
     * schema-shaped content IS the `payload` JSON column (like the base {@see BeamParticle}),
     * so route it there — then MIRROR the queryable axes (`kind`/`mode`/`max_depth`) out of the payload
     * onto their flat columns so a thread is filterable without unpacking JSON. The payload stays
     * canonical; the columns are a projection of it. The `schema_ref` binding is set on the instance
     * BEFORE the write and preserved.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fillFromSchemaPayload(array $payload): void
    {
        $this->setAttribute($this->payloadColumn(), $payload);

        // Mirror the queryable axes off the canonical payload.
        if (array_key_exists('kind', $payload)) {
            $this->setAttribute('kind', $payload['kind']);
        }
        if (array_key_exists('mode', $payload)) {
            $this->setAttribute('mode', $payload['mode']);
        }
        if (array_key_exists('max_depth', $payload)) {
            $this->setAttribute('max_depth', $payload['max_depth']);
        }
    }

    /**
     * Seed the creation-time default `max_depth` from `mode` — ONCE, at creation, only when the payload
     * carried no explicit `max_depth` (ADR-0176 §4). This makes editing `max_depth` later never
     * re-forced by mode: it fires on `creating` only, and only when the value is absent/null, so a
     * subsequent update that changes `max_depth` (or re-modes without touching depth) is never
     * overwritten. Writes the seeded value into BOTH the canonical payload and the mirror column.
     */
    protected static function booted(): void
    {
        static::creating(function (Thread $thread): void {
            if ($thread->getAttribute('max_depth') !== null) {
                return;
            }

            $mode = $thread->getAttribute('mode');
            if (! $mode instanceof ThreadMode) {
                return;
            }

            $seeded = $mode->defaultMaxDepth();
            $thread->setAttribute('max_depth', $seeded);

            // Keep the canonical payload in step with the seeded projection.
            $payload = (array) ($thread->getAttribute('payload') ?? []);
            $payload['max_depth'] = $seeded;
            $thread->setAttribute('payload', $payload);
        });
    }

    // -------------------------------------------------------------------------
    // Root-variant selection (TH-04, Ruling B) — the thread-level counterpart to
    // a message's selected_child_id, for the parentless root siblings.
    // -------------------------------------------------------------------------

    /**
     * Explicitly SELECT a ROOT variant — repoint `selected_root_id` at `$messageId` (the root-message
     * equivalent of {@see Message::selectChild()}). Root messages that are edits/regens of each other are
     * SIBLINGS at the root with no parent row to hold the pointer, so the thread holds it. Server-durable,
     * so the next assembled {@see Message::linearConversation()} walks from the chosen first-message
     * variant (and its whole downstream chain).
     */
    public function selectRoot(string $messageId): void
    {
        $this->forceFill(['selected_root_id' => $messageId])->save();
    }

    // -------------------------------------------------------------------------
    // Mode TRANSITIONS (TH-06, PRD §2.8, ADR-0176 §4) — spin-off + re-mode-in-place.
    // A thread is mode-INVARIANT: mode is a RENDER pivot over ONE message series, not
    // a storage fork. So both transitions go THROUGH the shared ParticleWriter (the
    // thread payload write path — authorize → validate → persist → emit), and NEITHER
    // rewrites message rows. Spin-off spawns a new thread; re-mode flips this thread's
    // `mode` LOSSLESSLY.
    // -------------------------------------------------------------------------

    /**
     * SPIN-OFF a NEW, independent thread from a SOURCE message (PRD §2.8, ADR-0176 §4) — the
     * "forum post → open a chat" case. Spawns a fresh thread (through the shared {@see ParticleWriter},
     * exactly like any other thread write) carrying `origin_message_id = $source->id` — a soft pointer back
     * to the source message in ANOTHER thread. This is the ONLY thread-to-thread link (thread-to-thread
     * parent-child nesting is deliberately NOT first-class, ADR-0176 §3).
     *
     * The new thread is FULLY INDEPENDENT: its own id, its own (later-added) participants and messages. The
     * SOURCE thread and the SOURCE message are UNTOUCHED — spin-off reads the source's id and writes nothing
     * back to it.
     *
     * The content write is routed through the writer: the `schema_ref` + `origin_message_id` bindings are
     * pre-set on the new instance, then the writer authorizes → VALIDATES the {@see ThreadData} payload →
     * persists via {@see fillFromSchemaPayload()} (payload column + mirrored axes; the pre-set
     * `origin_message_id` binding survives) → emits one persist signal. `mode` seeds the new thread's
     * creation-time default `max_depth` as usual unless `$maxDepth` is given.
     *
     * @param  Message  $source  the source message this thread is spun off from
     * @param  array<string, mixed>  $config  neutral freeform config bag for the new thread
     */
    public static function spinOffFrom(
        Message $source,
        ThreadMode $mode,
        ThreadKind $kind = ThreadKind::Interactive,
        ?int $maxDepth = null,
        array $config = [],
        ?string $membership = null,
    ): static {
        $thread = new static([
            'schema_ref' => static::SCHEMA_REF,
            // The soft pointer back to the source message — pre-set BEFORE the write; fillFromSchemaPayload()
            // touches only payload + mirrored axes, so this binding is preserved through the writer.
            'origin_message_id' => $source->getKey(),
        ]);

        $payload = (new ThreadData(
            kind: $kind,
            mode: $mode,
            max_depth: $maxDepth,
            config: $config,
            membership: $membership,
        ))->toArray();

        return app(ParticleWriter::class)->write($thread, $payload);
    }

    /**
     * RE-MODE this thread IN PLACE (PRD §2.8, ADR-0176 §4) — flip `mode` LOSSLESSLY. The substrate is
     * mode-invariant: `mode` is a RENDER pivot over ONE uniform message series, never a storage fork. So this
     * updates ONLY the thread's `mode` (and recomputes the render-time `max_depth` default for the new mode)
     * and re-renders the SAME message rows — it NEVER rewrites a single message row.
     *
     * LOSSLESS through the writer: the update goes THROUGH the shared {@see ParticleWriter} as a THREAD
     * payload write (a new {@see ThreadData} with only `mode`/`max_depth` changed, everything else carried
     * over) — authorize → validate → persist → emit. Because messages are a SEPARATE particle stream in a
     * SEPARATE table (`thread_id`-referenced, never folded into the thread payload), a thread payload write
     * PHYSICALLY CANNOT touch message rows. So `reply_to_id`/`parent_id` and every message payload byte
     * SURVIVE a `forum→chat` collapse (the nesting data stays in the rows), and a flip-BACK (`chat→forum`)
     * RESTORES the nesting render — because it was never destroyed, only re-capped at render time.
     *
     * GUARD (a): a thread LOCKED to its authored mode (`embed_template`/`embed_session`) REJECTS the re-mode
     * (throws {@see ThreadModeLocked}) — an embed's mode is its published contract.
     *
     * GUARD (b): a re-mode that TIGHTENS the render cap below the actual reply nesting (e.g. `forum→chat`,
     * where chat's `max_depth = 1` hides `reply_to` chains deeper than 1) is ALLOWED but FLAGGED. The data is
     * untouched (the rows still hold every reply edge); the returned {@see ReModeOutcome} carries the deepest
     * orphaned depth so the caller can badge it. Never a block.
     *
     * @return ReModeOutcome the (always-successful) outcome; {@see ReModeOutcome::orphansNesting()} flags guard (b)
     *
     * @throws ThreadModeLocked when this thread's kind locks it to its authored mode (guard (a))
     */
    public function reMode(ThreadMode $newMode): ReModeOutcome
    {
        $kind = $this->getAttribute('kind');

        // Guard (a): embeds are locked to their authored mode — reject the re-mode outright.
        if ($kind === ThreadKind::EmbedTemplate || $kind === ThreadKind::EmbedSession) {
            throw new ThreadModeLocked($kind);
        }

        $from = $this->getAttribute('mode');
        // The new render cap = the new mode's default. This is a RENDER-time cap recompute, not a message write.
        $newMaxDepth = $newMode->defaultMaxDepth();

        // Guard (b): does the NEW cap hide reply nesting the rows still hold? Measure the deepest reply chain
        // present BEFORE the write; if the new cap can't render it, flag (but never block) — data is untouched.
        $orphanedDepth = $this->orphanedReplyDepthUnder($newMaxDepth);

        // Route the payload update THROUGH the writer — a THREAD payload write, only mode/max_depth changed.
        // Everything else on the payload is carried over so the write is a pure re-mode, not a reset.
        $current = (array) ($this->getAttribute('payload') ?? []);
        $current['mode'] = $newMode->value;
        $current['max_depth'] = $newMaxDepth;

        app(ParticleWriter::class)->write($this, $current);

        return new ReModeOutcome(
            from: $from instanceof ThreadMode ? $from : $newMode,
            to: $newMode,
            newMaxDepth: $newMaxDepth,
            orphanedDepth: $orphanedDepth,
        );
    }

    /**
     * Guard (b) measurement: the DEEPEST reply-nesting level in this thread's messages that a render cap of
     * `$cap` would NO LONGER render — or null when nothing is hidden (or the cap is unbounded).
     *
     * The reply tree ({@see Message::replyTree()}) renders replies from depth 1 down to `cap` levels; a reply
     * at depth `> cap` is beyond the cap. So this walks the `reply_to_id` chains, finds the maximum depth
     * present, and returns it when it EXCEEDS `$cap` (the deepest level now orphaned). A null `$cap` (unbounded,
     * e.g. re-mode TO forum) never orphans. Reads the messages table directly (tolerating its absence).
     */
    protected function orphanedReplyDepthUnder(?int $cap): ?int
    {
        if ($cap === null) {
            return null; // unbounded render cap — nothing is ever hidden.
        }

        $maxDepth = $this->deepestReplyDepth();

        return $maxDepth > $cap ? $maxDepth : null;
    }

    /**
     * The deepest `reply_to_id` nesting level present in this thread's messages (0 = no replies, 1 = a direct
     * reply, N = an N-deep reply chain). Computed by walking the reply edges in memory. Tolerates a missing
     * messages table (returns 0) so it is always safe to call.
     */
    protected function deepestReplyDepth(): int
    {
        $table = config('beam.threads.tables.messages', 'thread_messages');

        if ($this->getKey() === null || ! $this->getConnection()->getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        // Load (id ⇒ reply_to_id) for every message in this thread. A row's reply depth is the number of
        // `reply_to_id` hops from it up to a root (a message replying to nothing). Root post = depth 0, a
        // direct reply = depth 1, an N-deep reply chain = depth N.
        $parentOf = $this->getConnection()->table($table)
            ->where('thread_id', $this->getKey())
            ->pluck('reply_to_id', 'id')
            ->all();

        $deepest = 0;
        foreach (array_keys($parentOf) as $id) {
            $depth = 0;
            $cursor = $parentOf[$id] ?? null;
            $seen = [];
            // Follow the reply_to chain to its root, counting hops (cycle-guarded, and stopping if the chain
            // points outside this thread's loaded set).
            while ($cursor !== null && ! isset($seen[$cursor]) && array_key_exists($cursor, $parentOf)) {
                $depth++;
                $seen[$cursor] = true;
                $cursor = $parentOf[$cursor];
            }
            $deepest = max($deepest, $depth);
        }

        return $deepest;
    }

    // -------------------------------------------------------------------------
    // Derived facts — computed/cached accessors, NEVER stored schema fields
    // (charter 02 §4). They must never appear as columns or in ThreadData, so
    // they can never trigger a migration.
    // -------------------------------------------------------------------------

    /** Cache of derived counts within a request, so repeated accessor reads don't re-query. */
    protected array $derivedFactCache = [];

    /**
     * The number of messages in this thread — a DERIVED fact, computed (and per-instance cached), never
     * stored. Counts the messages relation by `thread_id`. The message model lands in a later ticket, so
     * this resolves the table by config and counts directly, staying a no-op-safe zero until it exists.
     */
    public function messageCount(): int
    {
        return $this->derivedFactCache['message_count'] ??= $this->countRelated(
            config('beam.threads.tables.messages', 'thread_messages'),
        );
    }

    /**
     * The number of participants in this thread — a DERIVED fact, computed/cached, never stored. The
     * participant model lands in a later ticket; this counts the participants table by `thread_id`.
     */
    public function participantCount(): int
    {
        return $this->derivedFactCache['participant_count'] ??= $this->countRelated(
            config('beam.threads.tables.participants', 'thread_participants'),
        );
    }

    /**
     * Last activity — a DERIVED fact: the newest message timestamp, or the thread's own `updated_at`
     * when there are no messages. Computed/cached, never stored.
     */
    public function lastActivityAt(): ?string
    {
        return $this->derivedFactCache['last_activity_at'] ??= $this->computeLastActivityAt();
    }

    /**
     * Count rows in a related table by `thread_id`, tolerating a table that does not exist yet (the
     * message/participant tables land in later tickets) — a missing table yields 0, so the derived fact
     * is always safe to read.
     */
    protected function countRelated(string $table): int
    {
        if ($this->getKey() === null) {
            return 0;
        }

        if (! $this->getConnection()->getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        return (int) $this->getConnection()->table($table)->where('thread_id', $this->getKey())->count();
    }

    protected function computeLastActivityAt(): ?string
    {
        $table = config('beam.threads.tables.messages', 'thread_messages');

        if ($this->getKey() !== null && $this->getConnection()->getSchemaBuilder()->hasTable($table)) {
            $latest = $this->getConnection()->table($table)
                ->where('thread_id', $this->getKey())
                ->max('created_at');

            if ($latest !== null) {
                return (string) $latest;
            }
        }

        return $this->updated_at?->toDateTimeString();
    }

    // -------------------------------------------------------------------------
    // ReconcilesPayloadOnRead hooks (migrate-on-read + the embed_session pin)
    // -------------------------------------------------------------------------

    /**
     * The bound reconciler — the container's {@see RecordReconciler}. In a beam host that is the host's
     * fully-wired schema-ladder adapter; in a headless beam app, beam-core's registry-latest default.
     */
    protected function payloadReconciler(): RecordReconciler
    {
        return app(RecordReconciler::class);
    }

    /**
     * The record type the reconciler resolves versions from — the STEM of `schema_ref` (a bare stem
     * as-is, a versioned `$id` stripped to its stem). Null (⇒ the whole concern no-ops for the row) when
     * the thread carries no binding.
     */
    protected function resolveRecordType(): ?string
    {
        $ref = $this->getAttribute('schema_ref');

        return is_string($ref) && $ref !== '' ? SchemaId::from($ref)->recordType() : null;
    }

    // -------------------------------------------------------------------------
    // Versionable — the embed_session frozen-config snapshot folds HERE
    // -------------------------------------------------------------------------

    /**
     * Freeze the thread's schema-typed config into a snapshot (the `embed_session` frozen-config fold,
     * PRD §2.3): the binding, the written-under id, the migration status, and the canonical payload. A
     * pinned session's Publish snapshots this; a read under {@see withPinnedSchemaVersion()} then renders
     * the frozen payload under its pinned schema version. Mirrors {@see BeamParticle::toVersionSnapshot()}.
     *
     * @return array<string, mixed>
     */
    public function toVersionSnapshot(): array
    {
        return [
            'schema_ref' => $this->getAttribute('schema_ref'),
            'schema_id' => $this->getAttribute('schema_id'),
            'migration_status' => $this->getAttribute('migration_status'),
            'payload' => $this->getAttribute('payload'),
            'meta' => $this->getAttribute('meta'),
        ];
    }

    /**
     * Apply a frozen config snapshot back onto the live thread (Versionable restore / rollback). Re-mirrors
     * the queryable axes off the restored payload so the columns follow the payload. Mirrors
     * {@see BeamParticle::restoreVersionSnapshot()}.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->forceFill([
            'schema_ref' => $snapshot['schema_ref'] ?? null,
            'schema_id' => $snapshot['schema_id'] ?? null,
            'migration_status' => $snapshot['migration_status'] ?? null,
            'payload' => $snapshot['payload'] ?? null,
            'meta' => $snapshot['meta'] ?? null,
        ]);

        $payload = (array) ($snapshot['payload'] ?? []);
        if (array_key_exists('kind', $payload)) {
            $this->setAttribute('kind', $payload['kind']);
        }
        if (array_key_exists('mode', $payload)) {
            $this->setAttribute('mode', $payload['mode']);
        }
        if (array_key_exists('max_depth', $payload)) {
            $this->setAttribute('max_depth', $payload['max_depth']);
        }

        $this->save();
    }
}
