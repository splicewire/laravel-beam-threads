<?php

namespace Splicewire\Beam\Threads\Models;

use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\Contracts\Versionable;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Threads\Data\ThreadData;
use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Enums\ThreadMode;
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
        'kind',
        'mode',
        'max_depth',
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
     * The thread table — the config table-prefix seam ({@see config('threads.tables.threads')}). A
     * property default cannot call config(), so it is resolved here, mirroring how beam particles resolve
     * their prefixed table name.
     */
    public function getTable(): string
    {
        return config('threads.tables.threads', 'threads');
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
            config('threads.tables.messages', 'thread_messages'),
        );
    }

    /**
     * The number of participants in this thread — a DERIVED fact, computed/cached, never stored. The
     * participant model lands in a later ticket; this counts the participants table by `thread_id`.
     */
    public function participantCount(): int
    {
        return $this->derivedFactCache['participant_count'] ??= $this->countRelated(
            config('threads.tables.participants', 'thread_participants'),
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
        $table = config('threads.tables.messages', 'thread_messages');

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
