<?php

namespace Splicewire\Beam\Threads\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Enums\ThreadMode;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The thread particle's schema-typed payload (threads-substrate PRD §2.3, charter 02 §4–5) — the thin
 * aggregate header a {@see \Splicewire\Beam\Threads\Models\Thread} carries in its `payload` JSON column
 * and edits/reads through beam-core's {@see ParticleWriter} / migrate-on-read.
 *
 * A thread is a THIN aggregate: messages are NOT folded in (they reference `thread_id` and hydrate as a
 * relation, on their own independent version stream — charter 02 §4). So `ThreadData` pins only the
 * config that identifies and shapes the thread:
 *
 *  - `kind` ({@see ThreadKind}) — provenance/hosting axis (interactive · embed_template · embed_session).
 *  - `mode` ({@see ThreadMode}) — the render pivot (chat · board · forum), ORTHOGONAL to kind.
 *  - `max_depth` — the reply-nesting cap (`1` flat · `N` capped · `null` unbounded). Seeded at creation
 *    from `mode` ({@see ThreadMode::defaultMaxDepth()}) but freely editable thereafter — mode never
 *    re-forces it (ADR-0176 §4).
 *  - `config` — a NEUTRAL, freeform bag (title, cosmetic/behavioral hints). Mode-INVARIANT and
 *    mode-DECOUPLED by construction: no mode-specific keys live here (ADR-0176 mode-invariance). No AI
 *    runtime lives here either — this is the free-tier, AI-free substrate.
 *  - `membership` — a pointer to the thread's participants (the participant set lands in a later ticket;
 *    this is the forward-declared seam, nullable until 04 fills it).
 *
 * DELIBERATELY ABSENT — `realm` (no key, no pin, no override): a thread stores NO realm anywhere
 * (ADR-0176 §2). Realm is the reused, ambient, unstored route axis the render layer reads via
 * `RealmRegistry::effective()` — never a stored field on the thread. Derived facts (message count,
 * last-activity, participant count) are ALSO absent — they are computed/cached model accessors, never
 * stored schema fields, so they never drive a migration (charter 02 §4).
 *
 * SCHEMA SHIPPING (the resolution mechanism, mirrored EXACTLY from tower's `SchemaIdentity` Data classes
 * — e.g. `ContentCellData`). `ThreadData` opts into versioned identity by implementing
 * {@see SchemaIdentity}: `schemaName()` = `threads/thread`, `schemaVersion()` = 1. A {@see Thread} binds
 * its `schema_ref` to this class's FQCN, so the host's `SchemaTargetResolver` (tower's
 * `TargetSchemaResolver`, bound in the app) recognises it as a SYSTEM schema class and PROJECTS the
 * current (v1) target from the live class via `JsonSchemaGenerator` — the absolute versioned `$id`
 * `<base_uri>/threads/thread/1`. No committed `.schema.json` is needed for the current version (the live
 * class IS the source of truth); `schema:freeze` would only be needed to pin an OLDER version once v2
 * lands. This is the same seam every tower composition Data class rides — beam-threads adds no new
 * mechanism.
 *
 * No `#[TypeScript]` here — typed-client codegen is a HOST concern, so the package forces no transformer
 * dependency on every consumer.
 */
class ThreadData extends Data implements SchemaIdentity
{
    /** The stable, path-style schema name — the stem of the absolute versioned `$id`. */
    public static function schemaName(): string
    {
        return 'threads/thread';
    }

    /** The author-declared schema version — v1 at TH-02; a later reshape bumps this + freezes v1. */
    public static function schemaVersion(): int
    {
        return 1;
    }

    /**
     * @param  ThreadKind  $kind  provenance/hosting axis
     * @param  ThreadMode  $mode  the render pivot (chat|board|forum)
     * @param  int|null  $max_depth  reply-nesting cap (1 flat, N capped, null unbounded); seeded from mode
     *                               at creation, freely editable after
     * @param  array<string, mixed>  $config  neutral freeform config bag (mode-decoupled, AI-free)
     * @param  string|null  $membership  pointer to the thread's participant set (forward seam, 04)
     */
    public function __construct(
        public ThreadKind $kind,
        public ThreadMode $mode,
        public ?int $max_depth = null,
        public array $config = [],
        public ?string $membership = null,
    ) {}
}
