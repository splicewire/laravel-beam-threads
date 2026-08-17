<?php

use Splicewire\Beam\Threads\Contracts\ParticipantTurnDriver;
use Splicewire\Beam\Threads\Tests\Fakes\FakeTurnDriver;
use Splicewire\Beam\Threads\Turns\TurnDriverResolver;

/**
 * The resolver is the ONE place beam-threads reaches a concrete turn-taker, and it does so BLIND — it
 * makes whatever class-string the config names. That blindness is what keeps the package AI-free, and
 * it is also what makes the type check load-bearing: without the `instanceof` guard, a misconfigured
 * `turn_driver` would be `make()`d and then called with `takeTurn()` on an object that has no such
 * method, surfacing as a fatal error deep inside a streaming response rather than as a config error at
 * the seam.
 *
 * The other half is the NULL default. `null` is not "unconfigured" — it is the free-tier PASSIVE
 * posture, and {@see TurnDriverResolver::resolve()} must return null for it rather than throw, because
 * {@see \Splicewire\Beam\Threads\Turns\TurnService::onHumanMessage()} calls it on every ordinary human
 * message. A resolver that threw on null would take out plain messaging on every bare beam site.
 */
beforeEach(function () {
    $this->resolver = new TurnDriverResolver($this->app);
});

it('treats a null config as the passive free-tier default, not an error', function () {
    config()->set('beam.threads.turn_driver', null);

    expect($this->resolver->resolve())->toBeNull();
});

it('fails loudly on the explicit-turn path when no driver is bound', function () {
    // The asymmetry is deliberate: an auto-trigger no-ops, but an explicitly invoked turn on a
    // driverless substrate is a programmer error and must say so.
    config()->set('beam.threads.turn_driver', null);

    expect(fn () => $this->resolver->resolveOrFail())
        ->toThrow(RuntimeException::class, 'No turn driver is bound');
});

it('returns an already-constructed driver instance untouched', function () {
    $driver = new FakeTurnDriver;
    config()->set('beam.threads.turn_driver', $driver);

    expect($this->resolver->resolve())->toBe($driver);
    expect($this->resolver->resolveOrFail())->toBe($driver);
});

it('resolves a class-string THROUGH the container so the driver gets its own dependencies', function () {
    // Not `new $class` — the tower driver needs constructor injection. A regression to direct
    // instantiation would work for a dependency-free driver and fail for every real one.
    $driver = new FakeTurnDriver;
    $this->app->instance(FakeTurnDriver::class, $driver);
    config()->set('beam.threads.turn_driver', FakeTurnDriver::class);

    expect($this->resolver->resolve())->toBe($driver);
});

it('refuses a class-string that is not a turn driver, naming the class', function () {
    config()->set('beam.threads.turn_driver', stdClass::class);

    expect(fn () => $this->resolver->resolve())
        ->toThrow(RuntimeException::class, ParticipantTurnDriver::class);
});

it('refuses a config value that is neither null, a class-string, nor an instance', function () {
    // e.g. an env var read as `true`, or an array left over from a different config shape.
    foreach ([true, 42, ['a'], ''] as $bad) {
        config()->set('beam.threads.turn_driver', $bad);

        expect(fn () => $this->resolver->resolve())->toThrow(RuntimeException::class);
    }
});

it('ships passive by default — the package config binds no driver', function () {
    // The free-tier posture as SHIPPED. If the published config ever gained a default driver, every
    // bare beam site would start attempting turns.
    expect(config('beam.threads.turn_driver'))->toBeNull();
    expect((new TurnDriverResolver($this->app))->resolve())->toBeNull();
});
