<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;

/**
 * Message AUTHORSHIP + conversation-graph LINEAGE (threads-substrate PRD §2.5, charter 03) — adds the four
 * self/authorship references to the `beam_thread_messages` particle table. A SEPARATE migration from the
 * TH-03 message-create so the neutral content particle and the (participant-dependent) authorship/lineage
 * layer land in the order their tickets did; it runs after the participants table exists.
 *
 * UBIQUITOUS (central + every tenant) via {@see BeamThreadsServiceProvider::bootMigrations()}.
 *
 * The columns (all mode-invariant, all always stored):
 *  - `participant_id` NOT NULL — the SOLE authorship edge (PRD §2.5). `user_id` is DROPPED / never added:
 *    there is NO dual source of truth — human/agent/system all resolve via `participant.actor`. (This ticket
 *    asserts the message table carries no `user_id`.) FK → the participants table.
 *  - `parent_id` (self-FK, nullable) — LINEAGE tree edge (immutable rows, ONE parent, no merge). An edit AND
 *    a regenerate each create a SIBLING (a new row sharing this `parent_id`). Semantics: alternatives, show-ONE.
 *  - `selected_child_id` (self-FK, nullable) — the SERVER-DURABLE active-path pointer. A root-walk following
 *    `selected_child_id` yields the canonical LINEAR conversation; default = the newest sibling. Server-durable
 *    is mandatory: the AI turn is assembled server-side from one specific path.
 *  - `reply_to_id` (self-FK, nullable) — the NEW intentional reply-nesting edge (show-ALL, capped by the
 *    thread's `max_depth`). Distinct from `parent_id` (one selects, one shows-all); a reply may not reuse
 *    `parent_id`'s edge — enforced in the model.
 *
 * NO AI-runtime columns (ADR-0174/0175). The nullable self-FKs are declared WITHOUT DB-level foreign-key
 * constraints against their own table's rows for `selected_child_id` (it is repointed frequently and would
 * otherwise fight insert-order); `parent_id`/`reply_to_id` reference the same table and are left as plain
 * indexed uuid columns for the same reason (a self-referential FK to a not-yet-inserted sibling deadlocks
 * ordering). Referential integrity for the tree is a model invariant, not a DB constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            // Sole authorship — NOT NULL (no dual source of truth; user_id is intentionally never added).
            $table->uuid('participant_id')->after('thread_id')->index();

            // Lineage tree (edit/regen siblings) — self-references, indexed, no DB FK (self-referential
            // insert ordering + frequent repoint).
            $table->uuid('parent_id')->nullable()->after('participant_id')->index();
            $table->uuid('selected_child_id')->nullable()->after('parent_id')->index();

            // Intentional reply-nesting (show-all, depth-capped) — distinct axis from parent_id.
            $table->uuid('reply_to_id')->nullable()->after('selected_child_id')->index();

            // Authorship FK → participants (the one referential constraint that is safe — a message is
            // authored by an already-existing participant).
            $table->foreign('participant_id')
                ->references('id')
                ->on(config('beam.threads.tables.participants', Beam::table('thread_participants')))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropForeign(['participant_id']);
            $table->dropColumn(['participant_id', 'parent_id', 'selected_child_id', 'reply_to_id']);
        });
    }

    protected function table(): string
    {
        return config('beam.threads.tables.messages', Beam::table('thread_messages'));
    }
};
