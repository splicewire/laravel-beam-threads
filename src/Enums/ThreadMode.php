<?php

namespace Splicewire\Beam\Threads\Enums;

/**
 * The thread RENDER/interaction pivot (ADR-0176): the single stored hint for how one uniform message
 * series is presented. `chat` (live, streaming turn-taking), `board` (async posting, shallow nesting),
 * `forum` (async threaded discussion, unbounded nesting).
 *
 * Mode is a stored render-HINT, NOT a storage fork — the persisted shape is identical across all three
 * (ADR-0176's mode-invariance). Temporality (live vs async) is emergent UX the render layer derives from
 * this value; it is never itself stored, and there is no per-mode table. `mode` is orthogonal to
 * {@see ThreadKind} in this same namespace (`kind ⟂ mode`).
 *
 * At creation, `mode` SEEDS a default {@see defaultMaxDepth()} — a starting value only, never a binding
 * constraint: `max_depth` stays freely editable afterward and mode never re-forces it.
 */
enum ThreadMode: string
{
    case Chat = 'chat';
    case Board = 'board';
    case Forum = 'forum';

    /**
     * The creation-time DEFAULT reply-nesting cap this mode seeds (ADR-0176 §4): `chat` is flat (1),
     * `board` is shallow (2), `forum` is unbounded (null). Applied ONLY when a thread is created without
     * an explicit `max_depth`; it never re-applies on a later edit, so `max_depth` is freely editable and
     * a change is never overwritten by mode.
     */
    public function defaultMaxDepth(): ?int
    {
        return match ($this) {
            self::Chat => 1,
            self::Board => 2,
            self::Forum => null,
        };
    }
}
