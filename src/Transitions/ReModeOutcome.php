<?php

namespace Splicewire\Beam\Threads\Transitions;

use Splicewire\Beam\Threads\Enums\ThreadMode;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * The return value of a re-mode-in-place ({@see Thread::reMode()}) —
 * threads-substrate PRD §2.8, ADR-0176 §4, TH-06.
 *
 * Re-mode is LOSSLESS by construction: it flips only the thread's `mode` render-hint (and recomputes the
 * mode-default `max_depth` render cap); it NEVER rewrites message rows (`reply_to_id`/`parent_id` and every
 * payload byte survive). So a re-mode always SUCCEEDS. This outcome is not a success/failure verdict — it is
 * a WARNING SURFACE for guard (b): a re-mode that TIGHTENS the render cap below the actual reply nesting
 * (e.g. `forum→chat`, where chat's `max_depth = 1` can't render `reply_to` chains deeper than 1) is ALLOWED,
 * because the data survives untouched in the rows — a flip-BACK (`chat→forum`) restores the render exactly —
 * but the caller should be told the tightened cap now HIDES deeper replies at render time.
 *
 * {@see $orphanedDepth} is null (⇒ {@see orphansNesting()} false) when nothing was hidden; when set it is the
 * DEEPEST reply-nesting level that the NEW cap will no longer render (the render layer can badge it). The data
 * is never touched, so this is advisory only — never a block.
 */
class ReModeOutcome
{
    public function __construct(
        public ThreadMode $from,
        public ThreadMode $to,
        public ?int $newMaxDepth,
        public ?int $orphanedDepth = null,
    ) {}

    /** True when the new render cap now hides reply nesting that the rows still hold (guard (b) flag). */
    public function orphansNesting(): bool
    {
        return $this->orphanedDepth !== null;
    }
}
