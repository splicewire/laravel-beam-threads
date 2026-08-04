<?php

namespace Splicewire\Beam\Threads\Enums;

/**
 * The AUTHORITATIVE participant category (threads-substrate PRD §2.6, charter 03) — the always-set,
 * indexed column that answers "what species of member is this" independently of the (nullable) morph.
 * `kind` is NEVER null (a morph can be), so "all agent participants" is a single indexed column scan and
 * a system/external row with no actor still declares what it is.
 *
 *  - `human`    — a first-class person, resolved through the `user` morph (an authenticated account).
 *  - `agent`    — the AI-driver participant CATEGORY. It binds NO class here (that keeps beam-threads
 *                 AI-free): the `assistant` morph alias is bound by the tower tier (TH-08), and only there
 *                 does an `agent` row gain a concrete actor. Present as a category so the substrate can
 *                 model an AI member's membership/authorship without ever naming an AI vendor.
 *  - `visitor`  — an anonymous / pre-auth embed visitor (mirrors beam-embed's Visitor identity); the
 *                 embed-session visitor resolves as a participant of THIS kind (morph → the visitor).
 *  - `system`   — a deterministic non-human automation actor (notices, state transitions) with NO actor
 *                 row — it renders off the join-time `display_name` snapshot.
 *  - `external` — a member identified by an out-of-band foreign-system reference; the actor is optional.
 *
 * Orthogonal to {@see ParticipantRole} (category ⟂ in-thread capability) and to the actor morph (which
 * kind is authoritative, the morph is a resolution detail that may be null). Plain backed string enum
 * (no `final`, house style) so it round-trips through the column as its stable token.
 */
enum ParticipantKind: string
{
    case Human = 'human';
    case Agent = 'agent';
    case Visitor = 'visitor';
    case System = 'system';
    case External = 'external';
}
