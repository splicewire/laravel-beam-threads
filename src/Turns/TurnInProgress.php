<?php

namespace Splicewire\Beam\Threads\Turns;

use RuntimeException;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * Thrown when a turn is invoked on a thread that ALREADY has an active turn (threads-substrate PRD §2.7,
 * ADR-0175 §6 — "one active turn per thread"). The substrate SERIALISES: the `selected_child_id` path is
 * linear, so two turns must not append leaves concurrently. The second caller is REFUSED with this — a host
 * may catch it to queue/retry.
 *
 * House style: NO `final`, NO `readonly`.
 */
class TurnInProgress extends RuntimeException
{
    public static function for(Thread $thread): self
    {
        return new self(
            "A turn is already active on thread [{$thread->getKey()}] — one active turn per thread "
            .'(threads-substrate PRD §2.7). The substrate serialises; retry when it completes.'
        );
    }
}
