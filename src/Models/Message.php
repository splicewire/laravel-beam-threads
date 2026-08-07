<?php

namespace Splicewire\Beam\Threads\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RuntimeException;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Rushing\Versioning\Contracts\RecordReconciler;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Threads\Data\ThreadMessageData;
use Splicewire\Beam\Threads\Enums\SegmentKind;
use Splicewire\Beam\Threads\Turns\TurnService;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The generic MESSAGE particle (threads-substrate PRD §2.4, charter 02, ADR-0174) — one message in a
 * thread's uniform message series. A schema-typed, migrate-on-read particle whose content is an ordered
 * `Segment[]` (folded into `payload`), belonging to a {@see Thread} via `thread_id`.
 *
 * It composes TWO of beam-core's three particle disciplines — DELIBERATELY NOT the third
 * (mirrors {@see \Splicewire\Beam\Models\BeamSubmission}):
 *  - {@see PersistsBeamParticle} — the uuid7 particle skeleton + `payload`/`meta` casts; the write goes
 *    THROUGH beam-core's shared {@see ParticleWriter} (authorize → validate → persist → emit), never a fork.
 *  - {@see ReconcilesPayloadOnRead} — migrate-on-read forward as {@see ThreadMessageData} evolves (the
 *    citation/`references` refactor IS such a migration; it fires only on `schema_id` lag).
 *  - **NO `VersionableTrait`** — a message is IMMUTABLE CAPTURE (like a `BeamSubmission`), not an
 *    editable milestone-versioned doc. Edit/regen history is CONVERSATION-GRAPH LINEAGE
 *    (`parent_id`/`selected_child_id`, TH-04), NOT linear snapshot/restore. So there is no `head_version`
 *    column and no `toVersionSnapshot()`/`restoreVersionSnapshot()`.
 *
 * AUTHORSHIP + LINEAGE (TH-04, PRD §2.5) — the immutable rows form a TREE, not a mutable doc:
 *  - `participant_id` (NOT NULL) is the SOLE author. There is NO `user_id` — human/agent/system all resolve
 *    through {@see participant()}'s {@see Participant::actor()} morph, so the message never carries a second
 *    authorship source.
 *  - `parent_id` (self-FK) is the lineage EDGE. An EDIT and a REGENERATE each append a SIBLING (a new row
 *    sharing the same `parent_id`) — {@see editInto()} / {@see regenerateInto()} — so history is retained
 *    for free (the durable replacement for Versionable). `selected_child_id` (SERVER-DURABLE) picks the one
 *    active path; a root-walk following it ({@see linearConversation()}) yields the canonical LINEAR
 *    conversation. Default selection = the NEWEST sibling.
 *  - `reply_to_id` (self-FK, NEW) is the intentional reply-nesting axis (show-ALL, capped by the thread's
 *    `max_depth`) — DISTINCT from `parent_id` (one selects, one shows-all); a reply may not reuse
 *    `parent_id`'s edge. Both invariants are enforced on write in {@see booted()}.
 *
 * AI-FREE by construction (ADR-0174 §2 / 0175): NO `assistant_id`/`model`/`model_params`/`instructions_*`/
 * `max_tool_steps` — not columns, not payload keys. The AI participant attaches those at the tower tier via
 * the participant seam (TH-08); the neutral beam message payload carries none of it.
 *
 * SIDECAR, NOT FOLDED (ADR-0174 §2): media (Spatie MediaLibrary) + embeddings (pgvector) are wired as
 * `morphMany` relations against HOST-bound model classes ({@see media()} / {@see embeddings()}, resolved
 * from `config('beam.threads.sidecar.*')`) — they are stored OUTSIDE the JSON payload (file storage / vector
 * index) and SURFACED through the `references` projection. beam-threads is tier-clean/AI-free: it ships no
 * Spatie/pgvector dependency, so the concrete sidecar models are the host's to bind; unbound, the relations
 * resolve to an empty association (the seam exists regardless).
 *
 * NO stored `content` STRING column: {@see content()} is a DERIVED accessor coalescing the `Segment[]` text
 * (search/preview), never a stored source of truth.
 *
 * Not final — a host may extend it (add relations/columns, pin a connection) and repoint a swappable binding.
 */
class Message extends Model
{
    use PersistsBeamParticle;
    use ReconcilesPayloadOnRead;

    /**
     * The schema BINDING this particle is written under — the {@see ThreadMessageData} class FQCN. Binding
     * to the FQCN (not a bare stem) is what makes the host's `SchemaTargetResolver` recognise it as a SYSTEM
     * schema and project the current target live from the class (mirrors {@see Thread::SCHEMA_REF}).
     */
    public const SCHEMA_REF = ThreadMessageData::class;

    /**
     * The particle machinery columns + the `thread_id` aggregate edge. `payload`/`meta` come from
     * {@see PersistsBeamParticle}; the migrate-on-read columns (`schema_id`, `migration_status`) are
     * write-time-stamped, not mass-assigned here.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'schema_ref',
        'thread_id',
        // Authorship + conversation-graph lineage (TH-04, PRD §2.5). `participant_id` is the SOLE author
        // (NO `user_id` — no dual source of truth). The three self-references form the edit/regen tree
        // (`parent_id`/`selected_child_id`) and the intentional reply-nesting axis (`reply_to_id`).
        'participant_id',
        'parent_id',
        'selected_child_id',
        'reply_to_id',
        'payload',
        'meta',
    ];

    /**
     * The message table — the config table-prefix seam ({@see config('beam.threads.tables.messages')},
     * default `beam_thread_messages`). A property default cannot call config(), so it is resolved here,
     * mirroring how {@see Thread} resolves its prefixed table name.
     */
    public function getTable(): string
    {
        return config('beam.threads.tables.messages', Beam::table('thread_messages'));
    }

    /**
     * The stable morph alias — the durable polymorphic token this particle stores (permission-token prefix,
     * lineage/`morphTo` target). The durable TOKEN, not the FQCN, so a later class rename leaves stored rows
     * resolvable (mirrors {@see BeamParticle::getMorphClass()} / {@see Thread::getMorphClass()}).
     */
    public function getMorphClass(): string
    {
        return 'thread_message';
    }

    /** The JSON column holding the schema-typed {@see ThreadMessageData} payload. */
    public function payloadColumn(): string
    {
        return 'payload';
    }

    /**
     * The beam write-pipeline persist seam ({@see ParticleWriter}): the schema-shaped content (the
     * `content` Segment[] + `references`) IS the `payload` JSON column, so route it there — like the base
     * {@see BeamParticle} and {@see \Splicewire\Beam\Models\BeamSubmission}. The `schema_ref`/`thread_id`
     * bindings are set on the instance BEFORE the write and are preserved.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fillFromSchemaPayload(array $payload): void
    {
        $this->setAttribute($this->payloadColumn(), $payload);
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /** The thread this message belongs to (the sole aggregate parent; charter 02 §4). */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    /**
     * The SOLE author — a {@see Participant} of this thread (PRD §2.5). NOT NULL; there is no `user_id`
     * fallback. The author's species/actor resolves through {@see Participant::actor()}.
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    /** The lineage PARENT (self-FK) — the row this message is a sibling-variant under (edit/regen). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /** The lineage CHILDREN — all sibling-variants that share THIS message as their `parent_id`. */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /** The SERVER-DURABLE selected variant among {@see children()} — the one active-path successor. */
    public function selectedChild(): BelongsTo
    {
        return $this->belongsTo(static::class, 'selected_child_id');
    }

    /** The message this one is an intentional REPLY to (self-FK, show-all reply-nesting; distinct axis). */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(static::class, 'reply_to_id');
    }

    /** The intentional REPLIES to this message (show-all reply-tree children; capped by thread max_depth). */
    public function replies(): HasMany
    {
        return $this->hasMany(static::class, 'reply_to_id');
    }

    /**
     * SIDECAR media (Spatie MediaLibrary) — a `morphMany` against the HOST-bound media model
     * (`config('beam.threads.sidecar.media_model')`). Stored OUTSIDE the JSON payload (file storage),
     * surfaced through the `references` projection. Unbound (null config) ⇒ resolves to a placeholder so
     * the relation is an empty association, never a fatal — beam-threads ships no Spatie dependency.
     */
    public function media(): MorphMany
    {
        return $this->morphMany($this->sidecarModel('media_model'), 'model');
    }

    /**
     * SIDECAR embeddings (pgvector) — a `morphMany` against the HOST-bound embedding model
     * (`config('beam.threads.sidecar.embedding_model')`). Stored OUTSIDE the JSON payload (vector index),
     * surfaced through the `references` projection. Unbound (null config) ⇒ an empty association.
     */
    public function embeddings(): MorphMany
    {
        return $this->morphMany($this->sidecarModel('embedding_model'), 'embeddingable');
    }

    /**
     * Resolve a host-bound sidecar model class from config, falling back to the neutral placeholder
     * {@see SidecarNull} when unbound — so the morphMany seam is always constructible even in a headless
     * beam-threads install with no Spatie/pgvector host.
     *
     * @return class-string<Model>
     */
    protected function sidecarModel(string $key): string
    {
        $class = config("beam.threads.sidecar.$key");

        return is_string($class) && $class !== '' && class_exists($class) ? $class : SidecarNull::class;
    }

    // -------------------------------------------------------------------------
    // Lineage mechanism — sibling-on-edit/regen, selected-child default-newest,
    // the linear root-walk (PRD §2.5). Immutable rows; a new variant is a NEW row.
    // -------------------------------------------------------------------------

    /**
     * Append a SIBLING variant of this message (the shared edit/regen mechanism): a NEW immutable row under
     * the SAME lineage parent (`parent_id`), authored by `$participantId`, carrying `$payload`. The new
     * sibling becomes the SELECTED variant (default = newest sibling). Returns the persisted sibling.
     *
     * THE CONTENT WRITE GOES THROUGH beam-core's shared {@see ParticleWriter} — the SAME path as the initial
     * message write (Ruling A): the lineage bindings (`schema_ref`/`thread_id`/`participant_id`/`parent_id`)
     * are pre-set on the new instance, then the writer authorizes → VALIDATES `$payload` against the
     * {@see ThreadMessageData} schema → persists via {@see fillFromSchemaPayload()} (which touches ONLY the
     * `payload` column, leaving the pre-set lineage bindings intact) → emits ONE {@see BeamParticlePersisted}.
     * So a malformed variant payload is REJECTED by validation, an unauthorized participant is BLOCKED, and
     * one persist signal fires per variant. The originating row is NEVER mutated.
     *
     * The selection pointer is repointed AROUND the writer call, to the new (newest) sibling:
     *  - NON-root (has a `parent_id`): repoint the shared parent's `selected_child_id`.
     *  - ROOT (`parent_id` is null): root variants are siblings at the root with no parent row to hold the
     *    pointer, so repoint the THREAD's `selected_root_id` (Ruling B / {@see Thread::selectRoot()}).
     *
     * An EDIT and a REGENERATE are the SAME operation at the substrate — both fork a sibling; the only
     * difference (human-edited vs re-driven content) is WHO authored it and what payload it carries. So
     * {@see editInto()} / {@see regenerateInto()} both delegate here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function appendSibling(string $participantId, array $payload): static
    {
        $sibling = new static([
            'schema_ref' => static::SCHEMA_REF,
            'thread_id' => $this->getAttribute('thread_id'),
            'participant_id' => $participantId,
            // Siblings share a parent: the new variant's parent_id is the edited row's parent_id.
            'parent_id' => $this->getAttribute('parent_id'),
        ]);

        // Route the CONTENT through beam-core's write pipeline — authorize → validate → persist → emit —
        // exactly like the initial message write. fillFromSchemaPayload() writes only the payload column,
        // so the lineage bindings pre-set above are preserved through the write.
        app(ParticleWriter::class)->write($sibling, $payload);

        // Default selection = the newest sibling, repointed AROUND the writer:
        if ($this->getAttribute('parent_id') !== null) {
            // Non-root: the shared parent holds the selected-child pointer.
            static::whereKey($this->getAttribute('parent_id'))
                ->update(['selected_child_id' => $sibling->getKey()]);
        } else {
            // Root sibling: no parent row — the THREAD holds the selected-root pointer (Ruling B).
            Thread::whereKey($this->getAttribute('thread_id'))
                ->update(['selected_root_id' => $sibling->getKey()]);
        }

        return $sibling;
    }

    /**
     * Append a CHILD message that EXTENDS the conversation from this leaf (threads-substrate PRD §2.7,
     * ADR-0175 §5) — the turn-completion write. UNLIKE {@see appendSibling()} (a variant of the SAME
     * message under a shared parent), a child is the NEXT message in the linear path: its `parent_id` is
     * THIS message's key, and THIS message's `selected_child_id` is repointed at it so the new message
     * becomes the selected continuation ({@see linearConversation()} now walks through it).
     *
     * The CONTENT WRITE GOES THROUGH beam-core's shared {@see ParticleWriter} — the SAME path as
     * {@see appendSibling()} and the initial write (Ruling A): the lineage bindings
     * (`schema_ref`/`thread_id`/`participant_id`/`parent_id`) are pre-set, then the writer authorizes →
     * VALIDATES `$payload` against {@see ThreadMessageData} → persists via {@see fillFromSchemaPayload()}
     * (payload column only, bindings preserved) → emits ONE {@see BeamParticlePersisted}. The agent's reply
     * is NEVER raw-saved.
     *
     * A turn's completed content is authored by the AGENT participant (`$participantId`) — the substrate
     * ({@see TurnService}) owns this write; the driver persists nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    public function appendChild(string $participantId, array $payload): static
    {
        $child = new static([
            'schema_ref' => static::SCHEMA_REF,
            'thread_id' => $this->getAttribute('thread_id'),
            'participant_id' => $participantId,
            // A child EXTENDS the path: its parent is THIS leaf (not this leaf's parent).
            'parent_id' => $this->getKey(),
        ]);

        // Route the CONTENT through beam-core's write pipeline — authorize → validate → persist → emit —
        // exactly like appendSibling(). fillFromSchemaPayload() touches only the payload column, so the
        // pre-set lineage bindings survive the write.
        app(ParticleWriter::class)->write($child, $payload);

        // Repoint THIS leaf's selected child at the new message so it becomes the selected continuation.
        $this->forceFill(['selected_child_id' => $child->getKey()])->save();

        return $child;
    }

    // -------------------------------------------------------------------------
    // Failure-tolerant turn completion (create-then-advance) — PRD §2.7 gap fix
    //
    // A turn's reply is written in TWO phases instead of one atomic appendChild(), so a mid-stream
    // driver failure (LLM error/timeout/tool failure) cannot lose the partial reply or leave no record:
    //
    //   $child = $leaf->beginChild($agentId);   // empty streaming child, live immediately
    //   try   { ... accumulate segments ... $child->finalizeCompletion($segments); }
    //   catch { $child->failCompletion($segments, $e); throw; }
    //
    // The COMPLETION LIFECYCLE lives in the Message's `meta` JSON column (meta.completion_status ∈
    // {streaming, complete, failed}), NEVER in the content payload (ThreadMessageData is content-only:
    // `content: Segment[]` + `references`). meta is written EXPLICITLY (a forceFill save around the
    // ParticleWriter content write) — it is NOT part of fillFromSchemaPayload(), which touches only the
    // payload column — and is always MERGED so unrelated meta keys survive.
    // -------------------------------------------------------------------------

    /** The `meta` key holding the turn-completion lifecycle status ({@see beginChild()} et al). */
    public const COMPLETION_STATUS_META_KEY = 'completion_status';

    public const COMPLETION_STREAMING = 'streaming';

    public const COMPLETION_COMPLETE = 'complete';

    public const COMPLETION_FAILED = 'failed';

    /**
     * BEGIN a turn's reply — create the agent-authored CHILD message at turn START with EMPTY content
     * (`ThreadMessageData(content: [])`) and `meta.completion_status = 'streaming'`, so the row (and its id)
     * exists LIVE the instant the turn opens: the SSE layer can address it, and a mid-stream crash still
     * leaves a persisted record ({@see failCompletion()} fills its partial content). The empty content is
     * finalised later by {@see finalizeCompletion()} / {@see failCompletion()}.
     *
     * Like {@see appendChild()}: the content write rides beam-core's shared {@see ParticleWriter}
     * (authorize → validate → persist → emit) with the lineage bindings pre-set, and THIS leaf's
     * `selected_child_id` is repointed at the new child so it is the selected continuation immediately
     * (live streaming visibility — a `streaming` child IS part of the walked path; a `failed` one is NOT,
     * see {@see linearConversation()}). The `meta` status is written explicitly in the writer's
     * after-persist hook (so it lands in the SAME save as the content, not a fill-fighting second write).
     */
    public function beginChild(string $participantId): static
    {
        $child = new static([
            'schema_ref' => static::SCHEMA_REF,
            'thread_id' => $this->getAttribute('thread_id'),
            'participant_id' => $participantId,
            'parent_id' => $this->getKey(),
        ]);

        $payload = (new ThreadMessageData(content: []))->toArray();

        app(ParticleWriter::class)->write($child, $payload, after: function (Message $m): void {
            $m->mergeCompletionMeta(static::COMPLETION_STREAMING);
        });

        // Live visibility: repoint this leaf's selected child at the streaming reply immediately.
        $this->forceFill(['selected_child_id' => $child->getKey()])->save();

        return $child;
    }

    /**
     * FINALIZE a streaming reply on SUCCESS ({@see beginChild()}) — write the full `Segment[]` content
     * through the {@see ParticleWriter} and set `meta.completion_status = 'complete'` (merged). The child is
     * already the selected continuation (repointed at begin); the linear conversation now walks through a
     * clean, complete assistant reply.
     *
     * @param  Segment[]  $segments  the fully-accumulated reply content
     */
    public function finalizeCompletion(array $segments): static
    {
        $payload = (new ThreadMessageData(content: $segments))->toArray();

        app(ParticleWriter::class)->write($this, $payload, after: function (Message $m): void {
            $m->mergeCompletionMeta(static::COMPLETION_COMPLETE);
        });

        return $this;
    }

    /**
     * FAIL a streaming reply ({@see beginChild()}) — persist the segments accumulated SO FAR as the child's
     * content + set `meta.completion_status = 'failed'` and `meta.error` (the throwable's message + class —
     * NOT a full stack). The partial reply is preserved for audit; it is DELIBERATELY excluded from later
     * context assembly ({@see linearConversation()} stops at a `failed` node), so a retry extends a fresh
     * turn from the last SUCCESSFUL leaf rather than treating the broken partial as a clean assistant reply.
     *
     * @param  Segment[]  $segments  the reply content accumulated before the failure (may be empty)
     */
    public function failCompletion(array $segments, \Throwable $e): static
    {
        $payload = (new ThreadMessageData(content: $segments))->toArray();

        app(ParticleWriter::class)->write($this, $payload, after: function (Message $m) use ($e): void {
            $m->mergeCompletionMeta(static::COMPLETION_FAILED, [
                'error' => [
                    'message' => $e->getMessage(),
                    'class' => get_class($e),
                ],
            ]);
        });

        return $this;
    }

    /**
     * Merge the completion lifecycle status (and any extra keys) into the `meta` JSON column WITHOUT
     * clobbering other meta keys, then persist meta on THIS instance. Called from inside the
     * {@see ParticleWriter} after-persist hook so meta lands in the same save as the content write; a
     * standalone `forceFill(['meta' => ...])->save()` keeps meta off the payload seam
     * ({@see fillFromSchemaPayload()} touches only the payload column, never meta).
     *
     * @param  array<string, mixed>  $extra  additional meta keys to merge (e.g. an `error` record)
     */
    protected function mergeCompletionMeta(string $status, array $extra = []): void
    {
        $meta = (array) ($this->getAttribute('meta') ?? []);
        $meta[static::COMPLETION_STATUS_META_KEY] = $status;

        foreach ($extra as $key => $value) {
            $meta[$key] = $value;
        }

        $this->forceFill(['meta' => $meta])->save();
    }

    /**
     * EDIT this message — a human authoring a corrected variant. A sibling under the same parent (PRD §2.5:
     * "edit … creates a sibling"). Delegates to {@see appendSibling()}. Defaults the author to this message's
     * own participant when none is given (an in-place human edit keeps authorship).
     *
     * @param  array<string, mixed>  $payload
     */
    public function editInto(array $payload, ?string $participantId = null): static
    {
        return $this->appendSibling($participantId ?? $this->getAttribute('participant_id'), $payload);
    }

    /**
     * REGENERATE this message — a fresh variant re-driven for `$participantId` (typically the agent
     * participant). A sibling under the same parent (PRD §2.5: "regenerate … makes a sibling"). Delegates to
     * {@see appendSibling()}.
     *
     * @param  array<string, mixed>  $payload
     */
    public function regenerateInto(string $participantId, array $payload): static
    {
        return $this->appendSibling($participantId, $payload);
    }

    /**
     * Explicitly SELECT a variant among this message's children — repoint `selected_child_id` at
     * `$childId` (the ChatGPT/Claude `‹2/3›` switch). Server-durable, so the next assembled turn walks the
     * chosen path.
     */
    public function selectChild(string $childId): void
    {
        $this->forceFill(['selected_child_id' => $childId])->save();
    }

    /**
     * PRUNE this message from the conversation (TH-08 phase 5, Step C) — the substrate-native delete of a
     * turn. Removes this message AND its whole downstream lineage subtree (every descendant reachable via
     * `parent_id`), then repairs the SELECTION pointer that referenced it so the linear conversation stays
     * consistent ({@see linearConversation()} never dangles on a deleted id):
     *
     *  - NON-root (has a `parent_id`): the pointer lives on the shared parent's `selected_child_id`. If it
     *    pointed at this row, repoint it at a surviving SIBLING variant (newest) — or null when none remains.
     *  - ROOT (`parent_id` is null): the pointer lives on the THREAD's `selected_root_id`. If it pointed at
     *    this row, repoint it at a surviving root SIBLING (earliest) — or null when none remains.
     *
     * Immutable-row model: this is the ONE deliberate exception (a user deleting their own turn), scoped to
     * the row + its descendants. Runs in a transaction so a partial prune can't dangle a pointer.
     */
    public function pruneVariant(): void
    {
        $this->getConnection()->transaction(function (): void {
            $parentId = $this->getAttribute('parent_id');
            $threadId = $this->getAttribute('thread_id');
            $key = $this->getKey();

            // Collect this row + every descendant via the parent_id edge (a bounded walk over the thread's
            // messages), so the whole subtree under the deleted turn goes with it.
            $subtree = $this->collectSubtreeIds();

            if ($parentId !== null) {
                // Repoint the parent's selected child off this row onto a surviving sibling (newest), else null.
                $parent = static::query()->find($parentId);
                if ($parent !== null && $parent->getAttribute('selected_child_id') === $key) {
                    $replacement = static::query()
                        ->where('parent_id', $parentId)
                        ->whereNotIn('id', $subtree)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('id');
                    $parent->forceFill(['selected_child_id' => $replacement])->save();
                }
            } else {
                // Root: repoint the thread's selected root off this row onto a surviving root sibling, else null.
                $selectedRoot = Thread::query()->whereKey($threadId)->value('selected_root_id');
                if ($selectedRoot === $key) {
                    $replacement = static::query()
                        ->where('thread_id', $threadId)
                        ->whereNull('parent_id')
                        ->whereNotIn('id', $subtree)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->value('id');
                    Thread::query()->whereKey($threadId)->update(['selected_root_id' => $replacement]);
                }
            }

            static::query()->whereIn('id', $subtree)->delete();
        });
    }

    /**
     * The ids of this message and every descendant reachable via the `parent_id` lineage edge, within this
     * thread — the subtree a {@see pruneVariant()} removes. A bounded breadth-walk over the thread's rows.
     *
     * @return array<int, string>
     */
    protected function collectSubtreeIds(): array
    {
        $parentOf = static::query()
            ->where('thread_id', $this->getAttribute('thread_id'))
            ->pluck('parent_id', 'id')
            ->all();

        $ids = [$this->getKey()];
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $next = [];
            foreach ($parentOf as $id => $parent) {
                if (in_array($parent, $frontier, true) && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $next[] = $id;
                }
            }
            $frontier = $next;
        }

        return $ids;
    }

    /**
     * The canonical LINEAR conversation from this message as root — the root-walk following
     * `selected_child_id` (PRD §2.5 / §2.7: the AI turn is assembled from THIS one path). Follows each row's
     * SELECTED child until a leaf, yielding the one active path (never the full tree). Returns an ordered
     * Collection of messages, root first.
     *
     * ROOT-VARIANT AWARE (Ruling B): when called on a ROOT message (`parent_id` is null), the walk does NOT
     * blindly start at `$this` — it starts from the thread's SELECTED root variant (`thread.selected_root_id`,
     * the currently-selected first-message variant). If `selected_root_id` is null it falls back to the
     * sole/earliest root message. So switching `selected_root_id` switches which first-message variant (and
     * its whole downstream chain) IS the canonical conversation. A non-root `$this` walks from itself as
     * before.
     *
     * FAILED-COMPLETION AWARE (create-then-advance, PRD §2.7): a message whose `meta.completion_status` is
     * `'failed'` — a partial reply persisted for AUDIT when a turn crashed mid-stream ({@see failCompletion()})
     * — is NOT canonical conversation. The walk STOPS BEFORE it (the failed partial is neither included nor
     * walked past), so the failed record never poisons the next turn's assembled context as if it were a
     * clean assistant reply. The row stays in the tree for audit, but the linear conversation ends at the
     * last SUCCESSFUL leaf — a retry therefore extends a NEW turn from there, not from the broken partial. A
     * `'streaming'` child (a turn still in flight) IS included, so a live SSE caller sees it as it fills.
     *
     * @return Collection<int, static>
     */
    public function linearConversation(): Collection
    {
        $path = new Collection;
        $cursor = $this->getAttribute('parent_id') === null ? $this->resolveSelectedRoot() : $this;

        while ($cursor !== null) {
            // A failed partial is an audit record, not canonical conversation — stop before it so it never
            // enters the assembled context (a retry extends from the last successful leaf instead).
            if ($cursor->completionStatus() === static::COMPLETION_FAILED) {
                break;
            }

            $path->push($cursor);

            $next = $cursor->getAttribute('selected_child_id');
            $cursor = $next !== null ? static::query()->find($next) : null;
        }

        return $path;
    }

    /** The turn-completion lifecycle status from `meta` (null for a non-turn / legacy message). */
    public function completionStatus(): ?string
    {
        return ((array) ($this->getAttribute('meta') ?? []))[static::COMPLETION_STATUS_META_KEY] ?? null;
    }

    /**
     * Resolve the root message the linear walk should start from (Ruling B): the thread's selected root
     * variant (`thread.selected_root_id`), or — when unset — the sole/earliest root message of this thread
     * (`parent_id IS NULL`, oldest first), falling back to `$this` if neither resolves.
     */
    protected function resolveSelectedRoot(): static
    {
        $selectedRootId = Thread::query()
            ->whereKey($this->getAttribute('thread_id'))
            ->value('selected_root_id');

        if ($selectedRootId !== null) {
            $selected = static::query()->find($selectedRootId);
            if ($selected !== null) {
                return $selected;
            }
        }

        $earliest = static::query()
            ->where('thread_id', $this->getAttribute('thread_id'))
            ->whereNull('parent_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $earliest ?? $this;
    }

    /**
     * The show-ALL reply subtree under this message, capped by the thread's `max_depth` (PRD §2.5). Loads
     * {@see replies()} recursively down to (and no deeper than) `max_depth` levels below this message; a
     * null `max_depth` on the thread means unbounded. Depth 0 = this message; a reply at depth
     * `max_depth + 1` is NOT loaded (the cap). Returns a flat, depth-ordered Collection.
     *
     * @return Collection<int, static>
     */
    public function replyTree(?int $maxDepth = null): Collection
    {
        $cap = $maxDepth ?? optional($this->thread)->max_depth;

        $out = new Collection;
        $this->walkReplies($this, 0, $cap, $out);

        return $out;
    }

    /**
     * @param  Collection<int, static>  $out
     */
    protected function walkReplies(self $node, int $depth, ?int $cap, Collection $out): void
    {
        // Cap: do not descend past `cap` levels below the root (null cap ⇒ unbounded).
        if ($cap !== null && $depth >= $cap) {
            return;
        }

        foreach ($node->replies()->get() as $reply) {
            $out->push($reply);
            $this->walkReplies($reply, $depth + 1, $cap, $out);
        }
    }

    // -------------------------------------------------------------------------
    // Lineage invariants (enforced on write)
    // -------------------------------------------------------------------------

    /**
     * Enforce the two lineage invariants on every write (PRD §2.5):
     *  1. `reply_to_id` is a DISTINCT axis from `parent_id` — a reply may NOT reuse the lineage edge. They
     *     answer different questions (parent = which-variant-of-what; reply_to = which-message-am-I-answering).
     *     A row whose `reply_to_id` equals its `parent_id` collapses the two axes and is rejected.
     *  2. A message cannot reply to ITSELF (`reply_to_id` === own key) — no self-edge in the reply tree.
     */
    protected static function booted(): void
    {
        static::saving(function (Message $message): void {
            $replyTo = $message->getAttribute('reply_to_id');

            if ($replyTo === null) {
                return;
            }

            if ($replyTo === $message->getAttribute('parent_id')) {
                throw new RuntimeException(
                    'reply_to_id must be a DISTINCT axis from parent_id — a reply may not reuse the lineage '
                    .'edge (one selects a variant, one shows-all replies). (threads-substrate PRD §2.5)'
                );
            }

            if ($message->exists && $replyTo === $message->getKey()) {
                throw new RuntimeException('A message cannot reply to itself. (threads-substrate PRD §2.5)');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Derived projection — content:string (search/preview), NEVER stored
    // -------------------------------------------------------------------------

    /**
     * The DERIVED coalesced-text projection of the message (ADR-0174 §3 / PRD §2.4) — the single place that
     * flattens the ordered `Segment[]` to plain prose for search / preview / copy. Concatenates the `body`
     * of every `text` segment IN ORDER; non-text segments (reference markers, and any AI tool_call/tool_result
     * extension) contribute NO prose. This is an ACCESSOR — never a stored column, never a source of truth
     * (the `Segment[]` payload is canonical).
     */
    protected function content(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $segments = (array) (($this->getAttribute('payload')['content'] ?? []));

                $text = '';
                foreach ($segments as $segment) {
                    if (($segment['kind'] ?? null) === SegmentKind::Text->value) {
                        $text .= (string) ($segment['body'] ?? '');
                    }
                }

                return $text;
            },
        );
    }

    // -------------------------------------------------------------------------
    // ReconcilesPayloadOnRead hooks (migrate-on-read)
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
     * The record type the reconciler resolves versions from — the STEM of `schema_ref` (a bare stem as-is,
     * a versioned `$id` stripped to its stem). Null (⇒ the concern no-ops for the row) when the message
     * carries no binding. Mirrors {@see BeamSubmission::resolveRecordType()}.
     */
    protected function resolveRecordType(): ?string
    {
        $ref = $this->getAttribute('schema_ref');

        return is_string($ref) && $ref !== '' ? SchemaId::from($ref)->recordType() : null;
    }
}
