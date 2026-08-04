<?php

namespace Splicewire\Beam\Threads\Models\Participant;

use Illuminate\Database\Eloquent\Model;

/**
 * The DEFAULT actor-model a participant's `external` morph alias resolves to (threads-substrate PRD §2.6,
 * charter 03) — the placeholder concrete for a member identified by an OUT-OF-BAND external reference (a
 * foreign system's actor, e.g. a webhook counterparty). The actor morph is OPTIONAL for this kind: an
 * `external` participant with no bound actor renders off its required join-time `display_name` snapshot.
 *
 * The stored `actor_type` holds the durable alias `external`; a host repoints it via
 * `config('beam.threads.morph_map.external')`. A plain Eloquent model.
 */
class ExternalParticipant extends Model
{
    protected $table = 'external_actors';
}
