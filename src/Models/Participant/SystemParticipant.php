<?php

namespace Splicewire\Beam\Threads\Models\Participant;

use Illuminate\Database\Eloquent\Model;

/**
 * The DEFAULT actor-model a participant's `system` morph alias resolves to (threads-substrate PRD §2.6,
 * charter 03) — the placeholder concrete for a deterministic, NON-AI system/automation actor (notices,
 * state transitions, integrations).
 *
 * In practice a `system` participant usually carries NO actor row at all (a null morph) and renders off its
 * required join-time `display_name` snapshot; this placeholder exists so the enforced morph map has a
 * constructible target for the `system` alias when a host DOES bind a concrete automation actor. A plain
 * Eloquent model repointed via `config('beam.threads.morph_map.system')`.
 */
class SystemParticipant extends Model
{
    protected $table = 'system_actors';
}
