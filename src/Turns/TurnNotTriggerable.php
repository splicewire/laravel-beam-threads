<?php

namespace Splicewire\Beam\Threads\Turns;

use RuntimeException;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * Thrown when a turn cannot be triggered for a participant under the substrate's trigger policy (threads-
 * substrate PRD §2.7, ADR-0175 §6). Two refusals share this type:
 *  - the target participant is not a drivable AGENT of the thread; or
 *  - the configured driver cannot take a turn for it ({@see ParticipantTurnDriver::canTakeTurn()} is false —
 *    the capability gate).
 *
 * Distinct from the multi-agent addressing rule, which the substrate handles by REQUIRING an explicit
 * target rather than throwing on auto-trigger (see {@see TurnService::onHumanMessage()}).
 *
 * House style: NO `final`, NO `readonly`.
 */
class TurnNotTriggerable extends RuntimeException
{
    public static function notAnAgent(Participant $participant): self
    {
        return new self(
            "Participant [{$participant->getKey()}] is not a drivable agent of this thread — a turn is "
            .'invoked only FOR a named agent participant (threads-substrate PRD §2.7).'
        );
    }

    public static function driverRefused(Participant $agent): self
    {
        return new self(
            "The configured turn driver cannot take a turn for agent participant [{$agent->getKey()}] "
            .'(canTakeTurn() is false — the capability gate; threads-substrate PRD §2.7).'
        );
    }

    public static function noAgents(Thread $thread): self
    {
        return new self(
            "Thread [{$thread->getKey()}] has no agent participant to auto-trigger a turn for "
            .'(threads-substrate PRD §2.7).'
        );
    }

    public static function ambiguousAgent(Thread $thread): self
    {
        return new self(
            "Thread [{$thread->getKey()}] has MULTIPLE agent participants — a turn needs explicit addressing "
            .'(name the participant); no implicit fan-out (threads-substrate PRD §2.7, ADR-0175 §6).'
        );
    }
}
