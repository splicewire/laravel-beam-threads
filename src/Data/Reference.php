<?php

namespace Splicewire\Beam\Threads\Data;

use Spatie\LaravelData\Data;

/**
 * One OUT-OF-BAND reference RECORD of a message (threads-substrate PRD §2.4, ADR-0174 §4) — an entry in
 * {@see ThreadMessageData::$references}, the general typed, KEYED collection folded into the message
 * `payload` (Axis B). A reference is a keyed association of the WHOLE message, NOT positioned in reading
 * order — the positional half is the inline {@see Segment::reference()} marker that points AT it by `id`.
 *
 * Splitting the positional MARKER (a `reference` {@see Segment}) from the out-of-band RECORD (this class)
 * is the fix for chat-core's "floating tagalong" double-model — two roles (positional marker vs referenced
 * record) had been collapsed into one. A citation is now: an inline pointer-segment (id + label) → a folded
 * `reference` record here.
 *
 * The `references` collection REPLACES bespoke per-kind top-level arrays (no more a `citations[]` here, a
 * `sources[]` there): every out-of-band association — `citation` records today, any future INJECTED kind
 * (and the surfaced projection of the SIDECAR media/embeddings, which stay stored outside the payload) —
 * rides this one typed collection. It IS the schema-extension injection point.
 *
 * FIELD SHAPE:
 *  - `id` (string)         — the stable key an inline `reference` {@see Segment} marker points at (`ref_id`).
 *  - `type` (string)       — the reference kind (`citation` in the neutral base; future injected kinds, and
 *                            `media` / `embedding` as the surfaced projection of sidecar-stored artifacts).
 *  - `label` (string|null) — a human-facing label.
 *  - `uri` (string|null)   — the target the reference points at (a source URL, a sidecar artifact locator).
 *  - `data` (array)        — a freeform typed bag for kind-specific fields (kept open so a new reference
 *                            kind needs no schema surgery — the extension injection point).
 *
 * DISAMBIGUATION (four distinct "reference" domains — ADR-0174 §Consequences, keep the names apart):
 *   1. message `references` — THIS: a message's out-of-band associations (citation records + injected kinds).
 *   2. particle `schema_ref` — the schema BINDING a particle is written under (a class FQCN / `$id`).
 *   3. json-reference (`$ref`) — JSON-Schema/JSON-Pointer document linking; a schema-authoring mechanism.
 *   4. the "reference-overlay" pattern — beam's provenance-in-the-owning-package persistence discipline.
 * These share the English word "reference" and NOTHING else; conflating them is a category error.
 */
class Reference extends Data
{
    /**
     * @param  string  $id  the stable key an inline `reference` Segment marker points at
     * @param  string  $type  the reference kind (`citation` base; `media`/`embedding` sidecar projection; future)
     * @param  string|null  $label  a human-facing label
     * @param  string|null  $uri  the target the reference points at (source URL / sidecar artifact locator)
     * @param  array<string, mixed>  $data  freeform typed bag for kind-specific fields (the extension seam)
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $label = null,
        public ?string $uri = null,
        public array $data = [],
    ) {}

    /** A `citation` reference record — the neutral-base out-of-band record an inline marker points at. */
    public static function citation(string $id, ?string $label = null, ?string $uri = null): self
    {
        return new self(id: $id, type: 'citation', label: $label, uri: $uri);
    }
}
