<?php

namespace Splicewire\Beam\Threads\Enums;

/**
 * The NEUTRAL-BASE segment kinds (threads-substrate PRD §2.4, ADR-0174 §3) — the closed vocabulary of
 * positional content a beam message admits at the free tier:
 *
 *  - `text`      — a run of message prose. Its `body` is plain text by default; a completed segment MAY
 *                  carry an optional blockdoc rich-text render inside `body` (blockdoc is DEMOTED to this
 *                  role only — it is NOT the content model; ADR-0174 §3).
 *  - `reference` — an inline positional POINTER-marker (a citation marker): an `id` + `label` that points
 *                  OUT to a folded `reference` RECORD in the message's `references` collection (ADR-0174 §4).
 *                  The marker is the positional half; the record is the out-of-band half. Splitting them
 *                  fixes chat-core's "floating tagalong" double-model (two roles collapsed into one).
 *
 * DELIBERATELY ABSENT — `tool_call` / `tool_result`: these are AI-PARTICIPANT-contributed schema
 * EXTENSIONS (ADR-0174 §3, PRD §2.4). They are NOT in this neutral-base vocabulary and NOT in the neutral
 * base schema; the AI participant attaches them via the participant seam (TH-08). A frontend chat-core may
 * bundle the full union as a VIEW superset, but the backend neutral schema stays AI-free by construction.
 *
 * The kind is a plain backed string enum (no `final`, house style) so a segment round-trips through the
 * JSON payload as its stable string token.
 */
enum SegmentKind: string
{
    /** A run of message prose (optionally a blockdoc rich-text render in its body). */
    case Text = 'text';

    /** An inline positional pointer-marker (citation marker) → a folded `references` record. */
    case Reference = 'reference';
}
