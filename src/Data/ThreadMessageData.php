<?php

namespace Splicewire\Beam\Threads\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Threads\Models\Message;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The message particle's schema-typed payload (threads-substrate PRD §2.4, charter 02 §1–3, ADR-0174) —
 * the content a {@see Message} carries in its `payload` JSON column and
 * reads through beam-core's {@see ParticleWriter} / migrate-on-read. The persistence IS the typed Data.
 *
 * TWO-AXIS message model (ADR-0174 §4):
 *
 *  - **Axis A — `content: Segment[]`** ({@see Segment}) — the ordered content, everything with a POSITION
 *    in reading order: `text` runs and inline `reference` markers. The content model is a `Segment[]`
 *    UNION, NOT blockdoc (blockdoc is finalized-doc-only, zero streaming notion — DEMOTED to the optional
 *    rich-text render inside a completed text segment's `body`). The NEUTRAL BASE defines only `text` +
 *    `reference` markers; `tool_call`/`tool_result` are AI-participant schema EXTENSIONS (TH-08), ABSENT
 *    from this base and its generated schema. Folded into `payload`.
 *
 *  - **Axis B — `references: Reference[]`** ({@see Reference}) — a general typed, KEYED collection of the
 *    whole message's OUT-OF-BAND associations: `citation` records today, any future injected kind, plus the
 *    surfaced PROJECTION of the sidecar-stored media/embeddings. REPLACES bespoke per-kind top-level arrays
 *    and IS the schema-extension injection point. A citation = an inline `reference` {@see Segment} marker
 *    (id + label) → a folded `Reference` record here. Folded into `payload`.
 *
 * NO AI-RUNTIME FIELDS (ADR-0174 §2 / 0175): `assistant_id`, `model`, `model_params`, `instructions_*`,
 * `max_tool_steps` are NOT here — the beam message payload is AI-FREE by construction; they attach via the
 * participant seam (TH-08).
 *
 * NO stored `content: string` — plain coalesced text is a DERIVED accessor on the {@see Message}
 * model ({@see Message::content()}), a search/preview projection, never a
 * stored source of truth.
 *
 * MEDIA + EMBEDDINGS ARE NOT HERE — they are SIDECAR (Spatie MediaLibrary files, pgvector `morphMany`): they
 * physically cannot live in JSON, so they are stored OUTSIDE this payload in the host's own tables and merely
 * SURFACED through the `references` projection (uniform seam, split storage; ADR-0174 §2).
 *
 * SCHEMA SHIPPING (mirrors {@see ThreadData} exactly): `ThreadMessageData` opts into versioned identity via
 * {@see SchemaIdentity} — `schemaName()` = `threads/message`, `schemaVersion()` = 1. A {@see Message}
 * binds its `schema_ref` to this class's FQCN, so the host's `SchemaTargetResolver` recognises it as a SYSTEM
 * schema class and PROJECTS the current (v1) target live from the class (`$id` = `<base_uri>/threads/message/1`).
 * migrate-on-read reconciles a lagging payload forward as this class evolves (the citation/`references`
 * refactor IS such a migration). No `#[TypeScript]` — typed-client codegen is a HOST concern.
 */
class ThreadMessageData extends Data implements SchemaIdentity
{
    /**
     * @param  Segment[]  $content  the ordered content segments (Axis A: text + inline reference markers)
     * @param  Reference[]  $references  the out-of-band, keyed reference records (Axis B: citations + injected)
     */
    public function __construct(
        /** @var Segment[] */
        #[DataCollectionOf(Segment::class)]
        public array $content = [],
        /** @var Reference[] */
        #[DataCollectionOf(Reference::class)]
        public array $references = [],
    ) {}

    /** The stable, path-style schema name — the stem of the absolute versioned `$id`. */
    public static function schemaName(): string
    {
        return 'threads/message';
    }

    /** The author-declared schema version — v1 at TH-03; a later reshape bumps this + freezes v1. */
    public static function schemaVersion(): int
    {
        return 1;
    }
}
