<?php

namespace Splicewire\Beam\Threads\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Rushing\PermissionCascade\Concerns\HasUserId;
use Rushing\PermissionCascade\Concerns\HasVisibility;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Spatie\ModelFlags\Models\Concerns\HasFlags;
use Spatie\ModelStatus\HasStatuses;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Models\Message;

/**
 * The generic conversation particle (recohere T02, TH-07): the verified-generic `threads` machinery —
 * UUIDs, ownership (HasUserId), visibility cascade, flags, spatie model-status, and the versionable
 * snapshot/restore of the conversation config — factored into a TRAIT so both the tower
 * `Splicewire\Tower\Models\Thread` and the beam-embed `Splicewire\Beam\Embed\Models\Embed`
 * `use` it directly.
 *
 * TH-07 deleted the former beam-core `ChatBase` base class in favour of this shared member set. A trait is
 * not a base class (it leaves no ThreadBase successor), and it lives on the beam-threads substrate — the
 * home of the thread machinery and of {@see ThreadKind} (also moved here in TH-07) — so beam-core stays
 * free of the thread substrate (no beam-core ⇄ beam-threads cycle). Both consumers already depend DOWN on
 * beam-threads (tower requires it; beam-embed gains the require in TH-07), so the `use` is a legal
 * down-edge.
 *
 * The consuming model still declares `implements Conversation, Versionable` itself and supplies its own
 * `$table`; this trait carries the shared columns, casts, traits, scopes, relations, the kind predicates,
 * and the versionable snapshot/restore pair.
 *
 * The trait deliberately does NOT carry HasTags: `Splicewire\Beam\Taxonomy` depends DOWN on beam-core,
 * so pulling HasTags down here would form a require cycle. Tagging therefore stays a tower-Thread concern.
 *
 * TH-07 header migration (phase 3): the embed/publish-specific members (the embed columns,
 * `visitor()`/`publishedFrom()`, the template/session predicates, `scopeSessions`) were split OUT into the
 * sibling {@see EmbedParticle} trait so this trait stays the generic thread machinery only. An embed-capable
 * consumer (tower Thread / beam-embed Embed) `use`s BOTH.
 *
 * TH-08 message-layer cutover: {@see messages()} now points at the substrate {@see Message} particle
 * directly (its OWN package, a legal reference) — the former `config('embed.message_model')` seam onto the
 * legacy tower `ThreadMessage`/`thread_messages` read path is retired; the substrate is the one message store.
 *
 * @property-read Carbon|null $created_at
 */
trait ConversationParticle
{
    use HasFlags;
    use HasStatuses;
    use HasUserId;
    use HasUuids;
    use HasVisibility;
    use VersionableTrait;

    private static $whiteListFilter = ['*'];

    /**
     * Eloquent auto-calls `initialize{TraitName}()` on every model boot. The trait sets the shared
     * conversation columns/casts HERE rather than as trait properties: a trait property whose default
     * differs from the base {@see Model}'s own `$fillable`/`$casts` default
     * is a fatal "same property … definition differs" composition error. Merging at init sidesteps that
     * and lets a consuming model ADD its own fillable/casts on top (tower Thread, beam-embed Embed).
     */
    public function initializeConversationParticle(): void
    {
        // The generic mass-assignable columns shared by every conversation kind (interactive / embed
        // template / embed session). Knowledge-grounding columns are set through Thread's own traits; the
        // embed/publish columns moved to {@see EmbedParticle} (TH-07 header migration phase 3).
        $this->mergeFillable([
            'assistant_id',
            'title',
            'model',
            'max_tool_steps',
            'model_params',
            'instructions_provider',
            'instructions_text',
            'instructions_schemas',
            'tools',
            'user_id',
            'visibility',
            'kind',
            'created_at',
            'updated_at',
        ]);

        $this->mergeCasts([
            'model_params' => SchemalessAttributes::class,
            'tools' => SchemalessAttributes::class,
            'instructions_schemas' => SchemalessAttributes::class,
            'kind' => ThreadKind::class,
        ]);
    }

    public function scopeWithModelParams(): Builder
    {
        return $this->model_params->modelScope();
    }

    public function scopeWithTools(): Builder
    {
        return $this->tools->modelScope();
    }

    public function scopeWithInstructionsSchemas(): Builder
    {
        return $this->instructions_schemas->modelScope();
    }

    public function messages(): HasMany
    {
        // TH-08 message-layer cutover: a conversation's messages ARE the substrate {@see Message} particle
        // (`beam_thread_messages`) — the ONE message store. The former `config('embed.message_model')` seam
        // (which pointed at the legacy tower `ThreadMessage`/`thread_messages` read path) is retired: the
        // hybrid already WRITES substrate messages (TurnService owns the write), and the read now goes to the
        // same place. beam-threads references its OWN Message model (same package — no topology edge).
        // Explicit FK: the column is `thread_id`. Substrate messages are time-ordered by `created_at`.
        return $this->hasMany(Message::class, 'thread_id')->orderBy('created_at');
    }

    // -------------------------------------------------------------------------
    // Versionable (embed-instruction staging — ticket 13)
    //
    // A published embed's visitor-facing config is the *published* version, not the live working
    // row: editing an embed stages the change on the working row (HEAD lags), and a deliberate
    // Publish snapshots it (HEAD advances). The spawner reads HEAD, so a visitor never sees an
    // unpublished edit. Versions are keyed by getMorphClass() — always the base `thread` morph here.
    // -------------------------------------------------------------------------

    /**
     * Freeze the conversation config that publishes DELIBERATELY (ticket 13, payload width W3): the
     * instructions triplet + model/params/tool-budget/tools + title/assistant. Operational policy
     * (`enabled` kill-switch, `allowed_origins`, wallet, launcher cosmetics) is DELIBERATELY absent —
     * it stays live so safety controls apply instantly, never gated behind a re-publish. The leading
     * `_hash` lets a reader answer "has the working copy diverged from HEAD?" without diffing.
     *
     * @return array<string, mixed>
     */
    public function toVersionSnapshot(): array
    {
        $content = [
            'assistant_id' => $this->assistant_id,
            'title' => $this->title,
            'model' => $this->model,
            'max_tool_steps' => $this->max_tool_steps,
            'model_params' => $this->model_params?->toArray(),
            'instructions_provider' => $this->instructions_provider,
            'instructions_text' => $this->instructions_text,
            'instructions_schemas' => $this->instructions_schemas?->toArray(),
            'tools' => $this->tools?->toArray(),
        ];

        return ['_hash' => md5((string) json_encode($content)), ...$content];
    }

    /**
     * Apply a frozen conversation-config snapshot back onto this thread via the normal write path.
     * Only the staged fields are touched; operational policy is never in the snapshot, so it is left
     * exactly as-is. Used by the version store's pointer-move restore (rollback).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->fill([
            'assistant_id' => $snapshot['assistant_id'] ?? null,
            'title' => $snapshot['title'] ?? null,
            'model' => $snapshot['model'] ?? null,
            'max_tool_steps' => $snapshot['max_tool_steps'] ?? null,
            'model_params' => $snapshot['model_params'] ?? null,
            'instructions_provider' => $snapshot['instructions_provider'] ?? null,
            'instructions_text' => $snapshot['instructions_text'] ?? null,
            'instructions_schemas' => $snapshot['instructions_schemas'] ?? null,
            'tools' => $snapshot['tools'] ?? null,
        ])->save();
    }
}
