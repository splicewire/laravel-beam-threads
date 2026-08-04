<?php

namespace Splicewire\Beam\Threads\Models\Participant;

use Illuminate\Database\Eloquent\Model;

/**
 * The DEFAULT actor-model a participant's `user` morph alias resolves to (threads-substrate PRD §2.6,
 * charter 03) — the placeholder concrete for a first-class authenticated ACCOUNT participant.
 *
 * The morph MAP is what a stored `actor_type` discriminator holds (the alias `user`, not this FQCN — the
 * durable token, so a rename never orphans a row), and a HOST overrides where the alias points via
 * `config('beam.threads.morph_map.user')` — pointing it at its own `User` model. beam-threads ships this
 * neutral placeholder so the morph seam is constructible in a headless install with no host account model.
 *
 * A plain Eloquent model (no `final`, house style) — a host repoints the alias rather than extending this.
 */
class UserParticipant extends Model
{
    protected $table = 'users';
}
