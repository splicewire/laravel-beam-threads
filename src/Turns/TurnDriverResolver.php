<?php

namespace Splicewire\Beam\Threads\Turns;

use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Splicewire\Beam\Threads\Contracts\ParticipantTurnDriver;

/**
 * Resolves the concrete {@see ParticipantTurnDriver} from `config('beam.threads.turn_driver')` (threads-
 * substrate PRD §2.7, ADR-0175 §4) — the retired `config('embed.*')` class-string idiom generalised. This
 * is the ONE place beam-threads reaches the concrete turn-taker, and it does so BLIND: it resolves a
 * class-string from the container and never autoloads or names tower.
 *
 * NULL is the free-tier default (`config/beam/threads.php` ships `turn_driver => null`): a passive thread
 * with NO automated turn-taking. {@see resolve()} returns null for it; the caller ({@see TurnService})
 * turns that into a no-op. {@see resolveOrFail()} is for the explicit-turn path where a missing driver is a
 * programmer error (a turn was invoked on a substrate that bound none).
 *
 * The config value may be a class-string (resolved from the container so the driver gets its own
 * dependencies injected) or an already-constructed instance (a test fake bound directly). Anything else is
 * a misconfiguration and throws.
 *
 * House style: NO `final`, NO `readonly`.
 */
class TurnDriverResolver
{
    public function __construct(private Container $container) {}

    /**
     * Resolve the configured driver, or NULL when none is bound (the passive free-tier default). A
     * class-string is made through the container; an instance is returned as-is.
     */
    public function resolve(): ?ParticipantTurnDriver
    {
        $configured = config('beam.threads.turn_driver');

        if ($configured === null) {
            return null;
        }

        if ($configured instanceof ParticipantTurnDriver) {
            return $configured;
        }

        if (is_string($configured) && $configured !== '') {
            $driver = $this->container->make($configured);

            if (! $driver instanceof ParticipantTurnDriver) {
                throw new RuntimeException(
                    "config('beam.threads.turn_driver') [$configured] must resolve to a ".ParticipantTurnDriver::class.'.'
                );
            }

            return $driver;
        }

        throw new RuntimeException(
            "config('beam.threads.turn_driver') must be null, a ".ParticipantTurnDriver::class
            .' class-string, or an instance.'
        );
    }

    /**
     * Resolve the configured driver or THROW — for the explicit-trigger path where a turn was invoked but no
     * driver is bound (a passive thread cannot take an explicit turn).
     */
    public function resolveOrFail(): ParticipantTurnDriver
    {
        $driver = $this->resolve();

        if ($driver === null) {
            throw new RuntimeException(
                'No turn driver is bound — config(\'beam.threads.turn_driver\') is null. A passive thread '
                .'has no automated turn-taking; bind a driver to take a turn.'
            );
        }

        return $driver;
    }
}
