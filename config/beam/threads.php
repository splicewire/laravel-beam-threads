<?php

use Splicewire\Beam\Threads\Models\Participant\ExternalParticipant;
use Splicewire\Beam\Threads\Models\Participant\SystemParticipant;
use Splicewire\Beam\Threads\Models\Participant\UserParticipant;
use Splicewire\Beam\Threads\Models\Participant\VisitorParticipant;

return [

    /*
    |--------------------------------------------------------------------------
    | Turn driver
    |--------------------------------------------------------------------------
    |
    | The strategy that advances a thread from one turn to the next. NULL is the
    | free-tier default: a thread is a passive, participant-driven surface with no
    | automated turn-taking. A host tier binds its own driver here. beam-threads
    | ships no AI driver — that is a tower-tier concern.
    |
    */

    'turn_driver' => null,

    /*
    |--------------------------------------------------------------------------
    | Table names — deliberately absent
    |--------------------------------------------------------------------------
    |
    | Prefixing is beam core's job: the models call `Beam::tableFor()` directly, which
    | resolves `beam.threads.tables.<stem>` if a host declares it and otherwise falls back
    | to the `beam.core.table_prefix` seam (`beam_threads` / `beam_thread_messages` /
    | `beam_thread_participants`). The keys are NOT shipped here.
    |
    | This file must not name the Beam facade at all (beam-facade tickets 03 + 17). A facade
    | call from a published `config/*.php` THROWS — config resolves before the container is
    | booted, and `config:cache` throws too. Worse, the static form this replaced was silently
    | ORDER-DEPENDENT: `beam/threads.php` sorts before `beam/core.php`, so `table_prefix` was
    | not yet loaded and the lookup fell through to a hardcoded `beam_` — a retrofit host
    | setting `''` got wrongly-prefixed thread tables with no error. Resolving in the models
    | (after boot) removes both failure modes.
    |
    | A host that wants a per-table override publishes this config and ADDS the key, e.g.
    | `'tables' => ['threads' => 'legacy_threads']` — a full table name, prefix included,
    | since it is overriding the seam rather than feeding it.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Participant morph map
    |--------------------------------------------------------------------------
    |
    | The concrete class each participant alias resolves to. The keys are the durable
    | morph tokens a thread's participants/messages store; the values are overridable so
    | a host can point (e.g.) `user` at its own account model. Defaults are the intended
    | future participant concretes (land in a later ticket). The AI-driver alias is
    | intentionally absent — the tower tier binds it.
    |
    */

    'morph_map' => [
        'user' => UserParticipant::class,
        'visitor' => VisitorParticipant::class,
        'system' => SystemParticipant::class,
        'external' => ExternalParticipant::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidecar models (media + embeddings)
    |--------------------------------------------------------------------------
    |
    | A message's media (Spatie MediaLibrary) and embeddings (pgvector) stay SIDECAR
    | (ADR-0174 §2): they physically cannot live in the JSON payload — file storage /
    | vector index — so they are stored OUTSIDE the payload in the host's own tables and
    | merely SURFACED through the message `references` projection.
    |
    | beam-threads is AI-free and tier-clean: it ships NO Spatie/pgvector dependency and
    | can't reach the tower `Embedding` model, so the concrete sidecar model classes are
    | HOST-bound here (the participant-morph-map pattern). Left NULL, the sidecar morphMany
    | relations resolve to an empty association — the seam exists, the host wires the models.
    |
    */

    'sidecar' => [
        'media_model' => env('BEAM_THREADS_MEDIA_MODEL'),
        'embedding_model' => env('BEAM_THREADS_EMBEDDING_MODEL'),
    ],

];
