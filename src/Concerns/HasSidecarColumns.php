<?php

namespace Splicewire\Beam\Threads\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Splicewire\Beam\Threads\Enums\ParticipantKind;
use Splicewire\Beam\Threads\Enums\ParticipantRole;
use Splicewire\Beam\Threads\Enums\ParticipantStatus;
use Splicewire\Beam\Threads\Models\Participant;

/**
 * TRANSPARENT SIDECAR COLUMN FORWARDING (TH-07 header migration, phase 4).
 *
 * When the tower Thread header (and the beam-embed Embed alias) move onto the AI-free `beam_threads`
 * particle, the interactive-AI config and the embed/publish config no longer live on the conversation's own
 * table — they live in 1:1 sidecars (a host-bound `thread_headers` model on `thread_headers`, and beam-embed's
 * `EmbedSession` on `embed_sessions`), each keyed by `thread_id` = the particle id (companion-by-id). The
 * concrete sidecar model classes are HOST/consumer bound (never referenced here) so beam-threads stays
 * topology-clean — it depends DOWN only, never onto tower / beam-embed.
 *
 * This trait makes those moved columns keep behaving like native attributes so every existing call site
 * (`Thread::create([...flat...])`, `->update([...])`, `$thread->model`, `$thread->snapshot_config = …`)
 * works unchanged:
 *
 *  - {@see setAttribute()} — a sidecar column is BUFFERED (not written to `$this->attributes`, since the
 *    column does not exist on `beam_threads`) and flushed to its sidecar row AFTER the particle saves.
 *  - {@see getAttribute()} — a sidecar column reads from the buffer (read-after-write within the instance)
 *    else from the lazily-loaded sidecar relation, preserving the sidecar model's own casts
 *    (SchemalessAttributes / array).
 *  - the `saved` hook flushes each relation's buffer via `updateOrCreate(['thread_id' => id], $cols)`.
 *
 * The consuming model declares {@see sidecarColumnMap()} — `['relationMethod' => ['col', …]]` — mapping each
 * sidecar relation to the columns it owns. The relation must be a 1:1 (`hasOne`) keyed by `thread_id`.
 */
trait HasSidecarColumns
{
    /**
     * Buffered sidecar writes, grouped by relation: `[relationMethod => [col => value]]`. Flushed to the
     * sidecar rows on `saved`, then cleared.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $pendingSidecar = [];

    /**
     * The sidecar column map — `['relationMethod' => ['col', …]]`. Each relation is a 1:1 companion keyed by
     * `thread_id`; the listed columns are surfaced off it. The consuming model overrides this.
     *
     * @return array<string, array<int, string>>
     */
    public function sidecarColumnMap(): array
    {
        return [];
    }

    public static function bootHasSidecarColumns(): void
    {
        static::saved(function ($model): void {
            $model->flushSidecarColumns();
            $model->flushOwnerParticipant();
        });
    }

    /**
     * The virtual "owner column" translated into an owner {@see Participant}
     * instead of a real column — `user_id` for a conversation. Null disables the mechanism. Overridable so a
     * host can rename the virtual key.
     */
    protected function ownerParticipantColumn(): ?string
    {
        return 'user_id';
    }

    /** Buffered owner-participant intent: `null` = not set this cycle, `['id' => actorId|null]` when set. */
    protected ?array $pendingOwner = null;

    /** The relation that owns a given sidecar column, or null when the column is not sidecar-bound. */
    public function sidecarRelationFor(string $key): ?string
    {
        foreach ($this->sidecarColumnMap() as $relation => $columns) {
            if (in_array($key, $columns, true)) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * Buffer a sidecar column instead of writing it onto the (nonexistent) particle column; everything else
     * flows through the normal Eloquent path.
     */
    public function setAttribute($key, $value)
    {
        if ($key === $this->ownerParticipantColumn()) {
            // Translate the virtual owner column into owner-Participant intent — no real column is written.
            $this->pendingOwner = ['id' => $value];

            return $this;
        }

        $relation = $this->sidecarRelationFor($key);

        if ($relation !== null) {
            $this->pendingSidecar[$relation][$key] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Read a sidecar column off the buffer (read-after-write) or the loaded sidecar relation, preserving the
     * sidecar model's own cast for the column. Non-sidecar keys flow through the normal path.
     */
    public function getAttribute($key)
    {
        if ($key === $this->ownerParticipantColumn()) {
            // Read the owner's actor id off the buffer (read-after-write) or the owner Participant row.
            if ($this->pendingOwner !== null) {
                return $this->pendingOwner['id'];
            }

            return $this->ownerParticipant()?->actor_id;
        }

        $relation = $this->sidecarRelationFor($key);

        if ($relation !== null) {
            if (array_key_exists($relation, $this->pendingSidecar)
                && array_key_exists($key, $this->pendingSidecar[$relation])) {
                return $this->pendingSidecar[$relation][$key];
            }

            $companion = $this->getRelationValue($relation);

            return $companion?->getAttribute($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * The QUERY twin of {@see getAttribute()}'s sidecar forwarding: narrow to rows whose sidecar column is
     * one of `$values`.
     *
     * The read side is transparent and the query side is not, and that asymmetry is the trait's one sharp
     * edge (api-surface-coherence 97). `$thread->agent_id` resolves through the companion; `where('agent_id',
     * …)` is plain SQL against `beam_threads`, which has no such column, so it fatals with
     * `column "agent_id" does not exist`. Every caller that reaches for SQL therefore has to know the column
     * moved — unless it asks here instead, which is the point of this scope: the map stays the single owner
     * of *which* companion holds *which* column, so the next sidecar move has one call site, not one per
     * predicate.
     *
     * A column that is not sidecar-bound is a programming error and says so, rather than silently degrading
     * to a `whereHas` on a relation that does not hold it.
     *
     * @param  array<int, mixed>|string  $values
     */
    public function scopeWhereSidecarIn(Builder $query, string $column, array|string $values): void
    {
        $relation = $this->sidecarRelationFor($column);

        if ($relation === null) {
            throw new InvalidArgumentException(sprintf(
                '`%s` is not a sidecar column of %s — sidecarColumnMap() declares [%s].',
                $column,
                static::class,
                implode(', ', array_merge(...array_values($this->sidecarColumnMap()))) ?: 'none',
            ));
        }

        $query->whereHas($relation, function (Builder $companion) use ($column, $values): void {
            $companion->whereIn($column, Arr::wrap($values));
        });
    }

    /**
     * The owner {@see Participant} of this conversation — a `role=owner`, `actor_type=user` membership row.
     * Null when the conversation has no user owner (e.g. a visitor-owned embed session).
     */
    public function ownerParticipant(): ?Participant
    {
        if ($this->getKey() === null) {
            return null;
        }

        return Participant::query()
            ->where('thread_id', $this->getKey())
            ->where('role', ParticipantRole::Owner->value)
            ->where('actor_type', 'user')
            ->first();
    }

    /**
     * Reconcile the buffered owner intent into an owner {@see Participant} row. A non-null actor id
     * creates-or-updates the owner membership; a null intent (e.g. a visitor-owned session) leaves NO owner
     * participant (and removes any stray one). No-op when owner intent was not set this save cycle.
     */
    public function flushOwnerParticipant(): void
    {
        if ($this->pendingOwner === null) {
            return;
        }

        $actorId = $this->pendingOwner['id'];
        $this->pendingOwner = null;

        $ownerQuery = Participant::query()
            ->where('thread_id', $this->getKey())
            ->where('role', ParticipantRole::Owner->value)
            ->where('actor_type', 'user');

        if ($actorId === null) {
            // Visitor-owned / ownerless: ensure there is no lingering owner-User participant.
            $ownerQuery->delete();

            return;
        }

        Participant::query()->updateOrCreate(
            [
                'thread_id' => $this->getKey(),
                'role' => ParticipantRole::Owner->value,
                'actor_type' => 'user',
            ],
            [
                'kind' => ParticipantKind::Human->value,
                'actor_id' => (string) $actorId,
                'status' => ParticipantStatus::Active->value,
            ],
        );
    }

    public function flushSidecarColumns(): void
    {
        if ($this->pendingSidecar === []) {
            return;
        }

        $pending = $this->pendingSidecar;
        $this->pendingSidecar = [];

        foreach ($pending as $relation => $columns) {
            if ($columns === []) {
                continue;
            }

            $companion = $this->{$relation}()->updateOrCreate(
                ['thread_id' => $this->getKey()],
                $columns,
            );

            // Keep the just-written companion loaded so a subsequent read returns the fresh value.
            $this->setRelation($relation, $companion);
        }
    }
}
