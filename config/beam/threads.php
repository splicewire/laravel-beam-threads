<?php

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
    | TRANSITIONAL DEFAULT (threads-substrate build): the legacy ChatBase owns the
    | unprefixed `threads` table (tower tenant migration create_chats_table) and stays
    | live until the AI-driver rebind (TH-08) and ChatBase retirement (TH-07). To let
    | the new particle coexist with the incumbent without a duplicate-table collision,
    | the substrate defaults to the `beam_`-prefixed names during the build. TH-07,
    | when it drops the legacy `threads` table, renames these to the canonical
    | unprefixed forms (`threads`/`thread_messages`/`thread_participants`) as the final
    | cutover. Overridable via env in the meantime.
    |
    */

    'tables' => [
        'threads' => env('BEAM_THREADS_TABLE', 'beam_threads'),
        'messages' => env('BEAM_THREAD_MESSAGES_TABLE', 'beam_thread_messages'),
        'participants' => env('BEAM_THREAD_PARTICIPANTS_TABLE', 'beam_thread_participants'),
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
        'user' => \Splicewire\Beam\Threads\Models\Participant\UserParticipant::class,
        'visitor' => \Splicewire\Beam\Threads\Models\Participant\VisitorParticipant::class,
        'system' => \Splicewire\Beam\Threads\Models\Participant\SystemParticipant::class,
        'external' => \Splicewire\Beam\Threads\Models\Participant\ExternalParticipant::class,
    ],

];
