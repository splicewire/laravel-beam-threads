<?php

namespace Splicewire\Beam\Threads\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Rushing\Versioning\Contracts\RecordReconciler;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Threads\Data\ThreadMessageData;
use Splicewire\Beam\Threads\Enums\SegmentKind;
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
        return config('beam.threads.tables.messages', 'beam_thread_messages');
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
