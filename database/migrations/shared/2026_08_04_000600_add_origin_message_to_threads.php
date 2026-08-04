<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;

/**
 * SPIN-OFF origin pointer (threads-substrate PRD §2.8, ADR-0176 §4, TH-06) — adds `origin_message_id` to
 * the `beam_threads` particle table.
 *
 * The "forum post → open a chat" spin-off spawns a NEW, independent thread that carries a soft pointer back
 * to the SOURCE message (in ANOTHER thread) it was spun off from. This is the ONLY thread-to-thread link in
 * the substrate — thread-to-thread parent-child nesting is deliberately NOT first-class (ADR-0176 §3). One
 * pointer, nothing more: the spun-off thread is otherwise fully independent (its own participants/messages),
 * and the source thread + source message are UNTOUCHED by the spin-off.
 *
 * UBIQUITOUS (central + every tenant) via {@see BeamThreadsServiceProvider::bootMigrations()} — runs AFTER
 * the earlier thread migrations (timestamp 000600 > 000100).
 *
 * Nullable uuid, indexed, NO DB foreign key — consistent with the other cross-row pointers on this substrate
 * (`selected_root_id`, and the message self-refs `parent_id`/`selected_child_id`/`reply_to_id`): it points at
 * a message row in the messages table and a hard FK would fight insert order across tables. Referential
 * consistency is a model invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->uuid('origin_message_id')->nullable()->after('selected_root_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('origin_message_id');
        });
    }

    protected function table(): string
    {
        return config('beam.threads.tables.threads', 'beam_threads');
    }
};
