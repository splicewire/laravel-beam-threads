<?php

namespace Splicewire\Beam\Threads\Enums;

/**
 * The MEMBERSHIP LIFECYCLE axis (threads-substrate PRD §2.6, charter 03) — the state of the JOIN, distinct
 * from the actor's own existence. An actor's account may live on after its participant is `removed`, and a
 * `left`/`removed` participant still renders off its join-time `display_name` snapshot:
 *
 *   - `invited` — asked to join, not yet participating.
 *   - `active`  — a live member (the default on join).
 *   - `left`    — voluntarily departed.
 *   - `removed` — moderator/owner-ejected.
 *
 * Orthogonal to {@see ParticipantKind} (what species) and {@see ParticipantRole} (what capability). Plain
 * backed string enum (no `final`, house style).
 */
enum ParticipantStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Left = 'left';
    case Removed = 'removed';
}
