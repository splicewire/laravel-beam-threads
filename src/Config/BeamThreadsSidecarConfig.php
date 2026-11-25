<?php

namespace Splicewire\Beam\Threads\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * `beam.threads /sidecar` (generated with {@see BeamThreadsSection}).
 */
class BeamThreadsSidecarConfig extends Data
{
    public function __construct(
        #[Title('Media model'), Description('The host model class that stores a message\'s media attachments (Spatie MediaLibrary). Left null, the media relation resolves to an empty association.'), ConfigKey(env: 'BEAM_THREADS_MEDIA_MODEL')]
        public ?string $media_model,
        #[Title('Embedding model'), Description('The host model class that stores a message\'s vector embeddings (pgvector). Left null, the embedding relation resolves to an empty association.'), ConfigKey(env: 'BEAM_THREADS_EMBEDDING_MODEL')]
        public ?string $embedding_model,
    ) {}
}
