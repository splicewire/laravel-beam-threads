<?php

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Threads\Config\BeamThreadsSection;

it('registers the beam.threads config section from the service provider', function () {
    $registered = app(ConfigSectionRegistry::class)->get('beam.threads');

    expect($registered)->not->toBeNull()
        ->and($registered->section)->toBe(BeamThreadsSection::class)
        ->and($registered->owner)->toBe('splicewire/laravel-beam-threads');
});

it('covers every beam.threads key and carries no draft copy', function () {
    expect(ConfigSectionCoverage::uncovered(BeamThreadsSection::class, config('beam.threads')))->toBe([])
        ->and(ConfigSectionCoverage::drafts(BeamThreadsSection::class))->toBe([]);
});

it('covers a host-supplied table-name override map', function () {
    // The package omits `tables`; a host adds it to override the Beam::tableFor seam.
    $values = array_merge(config('beam.threads'), [
        'tables' => ['threads' => 'legacy_threads'],
    ]);

    expect(ConfigSectionCoverage::uncovered(BeamThreadsSection::class, $values))->toBe([])
        ->and(app(AcceptanceGate::class)->accepts($values, app(ConfigSectionSchema::class)->for(BeamThreadsSection::class)))->toBeTrue();
});

it('validates the package default config against the section schema (the host-audit check)', function () {
    // The same check ConfigSectionCoverageAudit runs at boot: the package's own default config
    // must satisfy the generated section schema (AcceptanceGate -> opis). The omitted `tables`
    // key is optional because the section supplies an empty-map default.
    $schema = app(ConfigSectionSchema::class)->for(BeamThreadsSection::class);

    expect(app(AcceptanceGate::class)->accepts(config('beam.threads'), $schema))->toBeTrue();
});

it('defaults the table-name section map without shipping a placeholder', function () {
    $defaults = require __DIR__.'/../../config/beam/threads.php';
    $parameter = new ReflectionParameter([BeamThreadsSection::class, '__construct'], 'tables');

    expect($defaults)->not->toHaveKey('tables')
        ->and($parameter->getDefaultValue())->toBe([])
        ->and($parameter->allowsNull())->toBeFalse();
});
