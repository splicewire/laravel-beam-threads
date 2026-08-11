<?php

use Splicewire\Beam\Beam;
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
    | Table names
    |--------------------------------------------------------------------------
    |
    | `messages`/`participants` route through {@see Beam::table()} like every other beam
    | particle (`beam_thread_messages` / `beam_thread_participants`) — the
    | `beam.core.table_prefix` knob repoints them, alongside every other beam table, for a
    | retrofit host. `threads` itself is the one exception: TH-08 phase 5 B3 renamed it to
    | the canonical UNPREFIXED `threads` (tower's {@see \Splicewire\Tower\Models\Thread}
    | already defaults to this), so its default here must match — not route through
    | Beam::table(). Still individually overridable via env.
    |
    */

    'tables' => [
        'threads' => env('BEAM_THREADS_TABLE', 'threads'),
        'messages' => env('BEAM_THREAD_MESSAGES_TABLE', Beam::table('thread_messages')),
        'participants' => env('BEAM_THREAD_PARTICIPANTS_TABLE', Beam::table('thread_participants')),
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
