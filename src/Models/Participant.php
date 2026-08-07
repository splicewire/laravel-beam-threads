<?php

namespace Splicewire\Beam\Threads\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Threads\Enums\ParticipantKind;
use Splicewire\Beam\Threads\Enums\ParticipantRole;
use Splicewire\Beam\Threads\Enums\ParticipantStatus;

/**
 * The first-class MEMBERSHIP model (threads-substrate PRD §2.6, charter 03) — the net-new crux of the
 * substrate: a thread is a JOIN of N participants (humans AND an AI agent) with authorship, membership,
 * role, and lifecycle. AI is ONE participant among humans; the AI-driver participant is only ever
 * REFERENCED through the (nullable) `actor` morph (bound by tower), never demoted into beam — so this model
 * is AI-FREE by construction.
 *
 * NOT a beam PARTICLE. Unlike {@see Thread} / {@see Message}, a participant carries no schema-typed
 * `payload`, no migrate-on-read, no versioning — it is a plain membership ROW (uuid7 PK via HasUuids). It
 * models WHO is in the thread; the messages they author are the particles.
 *
 * THREE ORTHOGONAL AXES (never collapsed):
 *  - `kind`   ({@see ParticipantKind})   — the always-set authoritative CATEGORY (`agent` binds no class).
 *  - `role`   ({@see ParticipantRole})   — IN-THREAD capability, a NEW axis kept SEPARATE from
 *                                          `HasVisibility` (cascade = resource-access; role = capability).
 *  - `status` ({@see ParticipantStatus}) — the membership LIFECYCLE, distinct from the actor's existence.
 *
 * ACTOR MORPH — nullable `morphTo` under the provider's ENFORCED morph map (aliases `user`/`visitor`/
 * `system`/`external` owned here; `assistant` bound by tower in TH-08). Null for `system`, optional for
 * `external`. When the actor IS null the `display_name` is REQUIRED (enforced in {@see booted()}), and
 * `display_name`/`handle`/`avatar_url` double as a JOIN-TIME SNAPSHOT so a departed or renamed actor still
 * renders (the snapshot is captured on the participant row, not read live off the actor).
 *
 * Not final — a host may extend it (add relations/columns, repoint a binding).
 */
class Participant extends Model
{
    use HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'thread_id',
        'kind',
        'actor_type',
        'actor_id',
        'display_name',
        'handle',
        'avatar_url',
        'role',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ParticipantKind::class,
            'role' => ParticipantRole::class,
            'status' => ParticipantStatus::class,
        ];
    }

    /**
     * The participants table — the config table-prefix seam
     * ({@see config('beam.threads.tables.participants')}, default `beam_thread_participants`). A property
     * default cannot call config(), so it is resolved here (mirrors {@see Thread::getTable()}).
     */
    public function getTable(): string
    {
        return config('beam.threads.tables.participants', Beam::table('thread_participants'));
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /** The thread this participant is a member of (the aggregate parent). */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    /**
     * The underlying ACTOR (nullable) — a polymorphic pointer under the provider's enforced morph map. The
     * stored `actor_type` is the durable ALIAS (`user`/`visitor`/`system`/`external`, or tower's
     * `assistant`), never a raw FQCN. Null for `system` / an unlinked `external`, in which case the row
     * renders off its `display_name` snapshot.
     */
    public function actor(): MorphTo
    {
        return $this->morphTo('actor');
    }

    // -------------------------------------------------------------------------
    // Actor-null snapshot enforcement (PRD §2.6: display_name required when actor is null)
    // -------------------------------------------------------------------------

    /**
     * Enforce the actor-null SNAPSHOT invariant on write (PRD §2.6): a participant with NO actor
     * (`actor_id` null — system/unlinked-external) MUST carry a `display_name`, because there is no actor
     * row to render off. This makes `display_name`/`handle`/`avatar_url` a durable join-time snapshot that
     * survives a departed or renamed actor. A DB-level partial CHECK backs this at the schema; enforcing it
     * here too gives a clear application error rather than a raw constraint violation.
     */
    protected static function booted(): void
    {
        static::saving(function (Participant $participant): void {
            $actorId = $participant->getAttribute('actor_id');
            $displayName = $participant->getAttribute('display_name');

            if ($actorId === null && ($displayName === null || $displayName === '')) {
                throw new RuntimeException(
                    'A participant with no actor (actor_id is null) requires a display_name — it is the '
                    .'join-time snapshot the row renders off. (threads-substrate PRD §2.6)'
                );
            }
        });
    }

    // -------------------------------------------------------------------------
    // Role capabilities — the IN-THREAD axis (NOT routed through HasVisibility)
    // -------------------------------------------------------------------------

    /**
     * Whether this participant may POST a message (`member` and above; observer is read-only). Delegates to
     * {@see ParticipantRole::canPost()} — the capability lives on the role enum, never on `HasVisibility`.
     */
    public function canPost(): bool
    {
        return $this->role instanceof ParticipantRole && $this->role->canPost();
    }

    /** Whether this participant may INVITE / REMOVE participants (`moderator` and above). */
    public function canModerate(): bool
    {
        return $this->role instanceof ParticipantRole && $this->role->canModerate();
    }

    /** Whether this participant may CLOSE / archive the thread (`owner` only). */
    public function canClose(): bool
    {
        return $this->role instanceof ParticipantRole && $this->role->canClose();
    }

    /** The messages authored by this participant (sole-authorship edge; PRD §2.5). */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'participant_id');
    }
}
