<?php

namespace Splicewire\Beam\Threads\Transitions;

use RuntimeException;
use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * Guard (a) rejection (threads-substrate PRD §2.8, ADR-0176 §4, TH-06) — a re-mode was attempted on a
 * thread whose `kind` LOCKS it to its authored mode.
 *
 * An `embed_template` publishes a chat AS a drop-in whose authored mode IS the embed contract, and an
 * `embed_session` is a visitor session frozen under that template. Flipping the mode of either would
 * silently rewrite what a published/embedded surface renders as, so {@see Thread::reMode()}
 * REJECTS the transition on those kinds (an ordinary `interactive` thread re-modes freely). This is a hard
 * refusal, distinct from guard (b)'s allow-but-flag orphaning outcome.
 */
class ThreadModeLocked extends RuntimeException
{
    public function __construct(public ThreadKind $kind)
    {
        parent::__construct(sprintf(
            "A thread of kind '%s' is LOCKED to its authored mode — re-mode is rejected. Only an "
            .'interactive thread may be re-moded (an embed template/session mode is its published contract). '
            .'(threads-substrate PRD §2.8, ADR-0176 §4)',
            $kind->value,
        ));
    }
}
