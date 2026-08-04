<?php

namespace Splicewire\Beam\Threads\Data;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Threads\Enums\SegmentKind;

/**
 * One ordered CONTENT SEGMENT of a message (threads-substrate PRD §2.4, ADR-0174 §3) — an element of
 * {@see ThreadMessageData::$content}, everything with a position in reading order (Axis A).
 *
 * The content model is a `Segment[]` UNION — NOT blockdoc (blockdoc is finalized-document-only with zero
 * streaming/partial/delta notion; it is DEMOTED to the optional rich-text render inside a completed `text`
 * segment's `body`, never the content model itself). Streaming is a transport concern; the FINALISED array
 * is what the particle stores and migrate-on-read operates over.
 *
 * NEUTRAL-BASE kinds only ({@see SegmentKind}): `text` and `reference` (a positional citation-marker). The
 * AI kinds `tool_call` / `tool_result` are schema EXTENSIONS the AI participant contributes (TH-08) — they
 * are ABSENT from this neutral base and its schema.
 *
 * FIELD SHAPE (one flat shape across the base kinds, the discriminator carried by `kind`):
 *  - `kind` ({@see SegmentKind})           — the discriminator (`text` | `reference`).
 *  - `body` (string|null)                  — for `text`: the prose (plain, or an optional blockdoc render).
 *  - `ref_id` (string|null)                — for `reference`: the id of the folded `references` record this
 *                                            marker points AT (the inline half of a citation).
 *  - `label` (string|null)                 — for `reference`: the human-facing marker label (e.g. `[1]`).
 *
 * The unused fields for a given kind are simply null — a flat union keeps the JSON payload + the generated
 * schema simple, and migrate-on-read reshapes it uniformly. `kind` serializes to its backed string value.
 *
 * House style: plain mutable promoted params, NO `readonly`, NO `final` (a host may extend the vocabulary).
 */
class Segment extends Data
{
    /**
     * @param  SegmentKind  $kind  the segment discriminator (neutral base: text | reference)
     * @param  string|null  $body  the text prose (for a `text` segment; plain or an optional blockdoc render)
     * @param  string|null  $ref_id  the folded `references` record id this marker points at (for `reference`)
     * @param  string|null  $label  the inline marker label (for `reference`, e.g. `[1]`)
     */
    public function __construct(
        public SegmentKind $kind,
        public ?string $body = null,
        public ?string $ref_id = null,
        public ?string $label = null,
    ) {}

    /** A `text` content segment carrying prose (optionally a blockdoc rich-text render). */
    public static function text(string $body): self
    {
        return new self(kind: SegmentKind::Text, body: $body);
    }

    /**
     * A positional `reference` MARKER (citation marker) — the inline pointer half of a citation: it carries
     * the `ref_id` of the folded {@see Reference} record it points at plus a human-facing `label`.
     */
    public static function reference(string $refId, ?string $label = null): self
    {
        return new self(kind: SegmentKind::Reference, ref_id: $refId, label: $label);
    }
}
