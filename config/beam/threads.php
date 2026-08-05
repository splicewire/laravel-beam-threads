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
    | Register migrations
    |--------------------------------------------------------------------------
    |
    | When true, the package registers its ubiquitous `shared/` migration dir into
    | both the central `migrate` and the `tenants:migrate` passes. Turn off if a host
    | vendors the thread tables elsewhere. (No tables ship yet — this is the shell.)
    |
    */

    'register_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | The particle table-prefix seam. A host repoints these when it composes
    | beam-threads alongside other particles.
    |
    | CANONICAL NAMES (TH-07/TH-08 phase 5, Step B3): the legacy tower ChatBase `threads`
    | / `thread_messages` tables have been DROPPED and the substrate is the sole owner of
    | the conversation tables, so these now default to the canonical UNPREFIXED forms
    | (`threads` / `thread_messages` / `thread_participants`). The transitional `beam_`
    | prefix (which let the substrate coexist with the incumbent during the build) is
    | retired. Still overridable via env for a host that vendors the tables elsewhere.
    |
    */

    'tables' => [
        'threads' => env('BEAM_THREADS_TABLE', 'threads'),
        'messages' => env('BEAM_THREAD_MESSAGES_TABLE', 'thread_messages'),
        'participants' => env('BEAM_THREAD_PARTICIPANTS_TABLE', 'thread_participants'),
    ],

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
