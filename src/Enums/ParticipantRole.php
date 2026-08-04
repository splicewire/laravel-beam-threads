<?php

namespace Splicewire\Beam\Threads\Enums;

use Splicewire\Beam\Threads\Models\Participant;

/**
 * The IN-THREAD CAPABILITY axis (threads-substrate PRD §2.6, charter 03) — a NEW axis kept DELIBERATELY
 * SEPARATE from `rushing/permission-cascade`'s `HasVisibility`. The two layers answer different questions
 * and must not be conflated:
 *
 *   - `HasVisibility` / permission-cascade = RESOURCE ACCESS — "can you open/see this thread at all".
 *   - {@see ParticipantRole} (this)        = IN-THREAD CAPABILITY — "once you are in, what can you do".
 *
 * The capability ladder is monotonic (`owner ⊇ moderator ⊇ member ⊇ observer`):
 *   - `observer`  — READ-ONLY. Cannot post.
 *   - `member`    — can POST (and, host-configurable, trigger an agent turn).
 *   - `moderator` — member + can INVITE / REMOVE participants.
 *   - `owner`     — moderator + can CLOSE / archive the thread.
 *
 * The concrete gates live on the {@see Participant} model
 * (`canPost()` / `canModerate()` / `canClose()`) reading THIS enum — never routed through `HasVisibility`.
 * Plain backed string enum (no `final`, house style).
 */
enum ParticipantRole: string
{
    case Owner = 'owner';
    case Moderator = 'moderator';
    case Member = 'member';
    case Observer = 'observer';

    /**
     * The capability RANK — higher grants a superset of the capabilities below it. Drives the monotonic
     * `owner ⊇ moderator ⊇ member ⊇ observer` gate checks on the Participant model without a per-capability
     * match table.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Observer => 0,
            self::Member => 1,
            self::Moderator => 2,
            self::Owner => 3,
        };
    }

    /** Whether this role's rank is at least the given role's — the shared "role+" gate primitive. */
    public function atLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }

    /** Can POST a message — `member` and above (observer is read-only). */
    public function canPost(): bool
    {
        return $this->atLeast(self::Member);
    }

    /** Can INVITE / REMOVE participants — `moderator` and above. */
    public function canModerate(): bool
    {
        return $this->atLeast(self::Moderator);
    }

    /** Can CLOSE / archive the thread — `owner` only. */
    public function canClose(): bool
    {
        return $this->atLeast(self::Owner);
    }
}
