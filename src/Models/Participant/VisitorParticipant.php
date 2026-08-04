<?php

namespace Splicewire\Beam\Threads\Models\Participant;

use Illuminate\Database\Eloquent\Model;

/**
 * The DEFAULT actor-model a participant's `visitor` morph alias resolves to (threads-substrate PRD §2.6,
 * charter 03) — the placeholder concrete for an anonymous / pre-auth EMBED VISITOR participant (mirrors
 * beam-embed's Visitor identity). The embed-session visitor resolves as a participant of `kind='visitor'`
 * whose actor morph points HERE.
 *
 * The stored `actor_type` holds the durable alias `visitor` (not this FQCN); a host repoints the alias at
 * its own visitor-identity model via `config('beam.threads.morph_map.visitor')`. A plain Eloquent model.
 */
class VisitorParticipant extends Model
{
    protected $table = 'visitors';
}
