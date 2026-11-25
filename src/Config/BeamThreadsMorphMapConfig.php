<?php

namespace Splicewire\Beam\Threads\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * `beam.threads /morph_map` (generated with {@see BeamThreadsSection}).
 */
class BeamThreadsMorphMapConfig extends Data
{
    public function __construct(
        #[Title('User participant'), Description('The model class the `user` participant alias resolves to, for a first-class authenticated account participant.'), ConfigKey]
        public string $user,
        #[Title('Visitor participant'), Description('The model class the `visitor` participant alias resolves to, for an anonymous or pre-auth visitor.'), ConfigKey]
        public string $visitor,
        #[Title('System participant'), Description('The model class the `system` participant alias resolves to, for a non-human system or automation actor.'), ConfigKey]
        public string $system,
        #[Title('External participant'), Description('The model class the `external` participant alias resolves to, for a participant identified by an out-of-band external reference.'), ConfigKey]
        public string $external,
    ) {}
}
