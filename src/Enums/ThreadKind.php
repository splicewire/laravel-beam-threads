<?php

namespace Splicewire\Beam\Threads\Enums;

/**
 * The thread provenance/hosting discriminator (the substrate successor to beam-core's
 * {@see \Splicewire\Beam\Enums\ThreadKind}, ADR-0176 §2): how a thread came to exist and how it is
 * hosted. `interactive` (an ordinary first-class thread), `embed_template` (a thread published as a
 * drop-in embed — its config IS the embed config), `embed_session` (a visitor-owned session spawned
 * from a template under a FROZEN config snapshot that folds into particle versioning — a pinned session
 * renders under its pinned schema version).
 *
 * `kind` is ORTHOGONAL to {@see ThreadMode} (`kind ⟂ mode`, ADR-0176 §2) — kind does not subsume mode:
 * an `interactive` thread can be `chat` or `forum`, an `embed_session` can be any mode. The two axes are
 * stored independently.
 *
 * Mirrors the beam-core {@see \Splicewire\Beam\Enums\ThreadKind} case set so the retired ChatBase's
 * embed distinction survives on the generic thread substrate.
 */
enum ThreadKind: string
{
    case Interactive = 'interactive';
    case EmbedTemplate = 'embed_template';
    case EmbedSession = 'embed_session';

    /** True iff this kind is a visitor-owned embed session pinned to a frozen config snapshot. */
    public function isEmbedSession(): bool
    {
        return $this === self::EmbedSession;
    }
}
