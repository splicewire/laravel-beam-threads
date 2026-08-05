<?php

namespace Splicewire\Beam\Threads\Enums;

/**
 * The Thread discriminator (DIE-04 / ADR-0062): an ordinary `interactive` chat, an
 * `embed_template` (a chat published as a drop-in — its config IS the embed config),
 * or an `embed_session` (a visitor-owned session spawned from a template with a
 * frozen config snapshot).
 *
 * Lives on the beam-threads substrate (TH-07): the thread particle and the generic embed
 * subsystem both discriminate on it, so the enum sits with the thread substrate it belongs to.
 * The former `Splicewire\Beam\Enums\ThreadKind` (beam-core) was deleted in TH-07 with `ChatBase`.
 */
enum ThreadKind: string
{
    case Interactive = 'interactive';
    case EmbedTemplate = 'embed_template';
    case EmbedSession = 'embed_session';
}
