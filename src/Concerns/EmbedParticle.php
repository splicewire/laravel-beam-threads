<?php

namespace Splicewire\Beam\Threads\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Embed\Models\Visitor;
use Splicewire\Beam\Threads\Enums\ThreadKind;

/**
 * The EMBED facet of a conversation (TH-07 header migration, phase 3) — the embed/publish-specific members
 * split OUT of {@see ConversationParticle} so the shared trait stays the generic thread machinery only.
 *
 * A conversation that can be PUBLISHED as an embed (or SPAWNED as a visitor session) `use`s this on top of
 * {@see ConversationParticle}: both the tower `Splicewire\Tower\Models\Thread` (its embed sessions are tower
 * Threads) and the beam-embed `Splicewire\Beam\Embed\Models\Embed` (the published-template alias) compose it.
 *
 * It carries the embed columns (`snapshot_config` / `visitor_id` / `embed_policy` / `published_from_id` /
 * `template_version` / `retention_days` / `session_status`), the `visitor()`/`publishedFrom()` relations, the
 * template/session kind predicates, and the sessions scope. Phase 4 re-surfaces these columns from the
 * `embed_sessions` sidecar so the conversation header can live on the AI-free `beam_threads` particle.
 *
 * @property-read ThreadKind|null $kind
 */
trait EmbedParticle
{
    /**
     * Eloquent auto-calls `initialize{TraitName}()` on every model boot — merge the embed columns/casts HERE
     * (a trait property whose default differs from the base Model's is a fatal composition error). Additive
     * on top of {@see ConversationParticle::initializeConversationParticle()}.
     */
    public function initializeEmbedParticle(): void
    {
        $this->mergeFillable([
            'visitor_id',
            'published_from_id',
            'snapshot_config',
            'template_version',
            'embed_policy',
            'retention_days',
            'session_status',
        ]);

        $this->mergeCasts([
            'snapshot_config' => 'array',
            'embed_policy' => 'array',
        ]);
    }

    // -------------------------------------------------------------------------
    // Embed (DIE-04)
    // -------------------------------------------------------------------------

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    /** The template this session was spawned from (self-FK, sessions only). */
    public function publishedFrom(): BelongsTo
    {
        return $this->belongsTo(static::class, 'published_from_id');
    }

    public function isPublished(): bool
    {
        return $this->kind === ThreadKind::EmbedTemplate;
    }

    public function isSession(): bool
    {
        return $this->kind === ThreadKind::EmbedSession;
    }

    /**
     * Sessions review query: `kind = embed_session` (optionally under a template). `published_from_id` moved
     * to the `embed_sessions` sidecar (TH-07 header migration phase 4), so it is filtered via the
     * `embedSession` companion relation.
     */
    public function scopeSessions(Builder $query, ?string $publishedFromId = null): Builder
    {
        $query->where('kind', ThreadKind::EmbedSession->value);

        if ($publishedFromId !== null) {
            $query->whereHas('embedSession', fn ($q) => $q->where('published_from_id', $publishedFromId));
        }

        return $query;
    }
}
