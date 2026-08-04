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
    | The particle table-prefix seam. A host repoints these to prefixed names when it
    | composes beam-threads alongside other particles.
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
        'user' => \Splicewire\Beam\Threads\Models\Participant\UserParticipant::class,
        'visitor' => \Splicewire\Beam\Threads\Models\Participant\VisitorParticipant::class,
        'system' => \Splicewire\Beam\Threads\Models\Participant\SystemParticipant::class,
        'external' => \Splicewire\Beam\Threads\Models\Participant\ExternalParticipant::class,
    ],

];
