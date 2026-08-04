<?php

namespace Splicewire\Beam\Threads\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The NEUTRAL sidecar placeholder (threads-substrate PRD §2.4, ADR-0174 §2) — the default related model a
 * message's SIDECAR `morphMany` relations ({@see Message::media()} / {@see Message::embeddings()}) resolve
 * to when the host has NOT bound a concrete sidecar model via `config('beam.threads.sidecar.*')`.
 *
 * beam-threads is tier-clean + AI-free: it ships NO Spatie MediaLibrary / pgvector dependency and cannot
 * reach the tower `Embedding` model, so the concrete media/embedding models are the HOST's to bind. Until a
 * host binds them, the sidecar relation still needs a constructible related-model class — this is it: a bare
 * Eloquent model over a table that (in a headless beam-threads install) does not exist, so the relation is a
 * present-but-empty association. The SEAM exists regardless of the host; the sidecar artifacts, when a host
 * wires them, are stored in the host's own tables and surfaced through the message `references` projection.
 */
class SidecarNull extends Model
{
    protected $table = 'beam_thread_message_sidecar_null';
}
