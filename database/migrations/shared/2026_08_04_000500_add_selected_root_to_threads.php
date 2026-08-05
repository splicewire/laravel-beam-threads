<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;

/**
 * ROOT-message variant switching (threads-substrate PRD §2.5, TH-04 follow-up, Ruling B) — adds
 * `selected_root_id` to the `beam_threads` particle table.
 *
 * The edit/regen sibling mechanism repoints a shared PARENT's `selected_child_id` to pick the active
 * variant. But a ROOT message (`parent_id IS NULL`) has no parent row to hold that pointer, so root
 * variants (edits/regens of the FIRST message) are SIBLINGS at the root with nowhere on a message to
 * record which one is selected. `thread.selected_root_id` is that missing pointer: it names the currently
 * selected root variant (default = the NEWEST root sibling). `linearConversation()` starts its root-walk
 * from `selected_root_id`, so switching it switches which first-message variant (and its whole downstream
 * chain) is the canonical conversation.
 *
 * UBIQUITOUS (central + every tenant) via {@see BeamThreadsServiceProvider::bootMigrations()} — runs AFTER
 * the thread-create migration (timestamp 000500 > 000100).
 *
 * Nullable uuid, indexed, NO DB foreign key — consistent with the message self-ref pointers
 * (`parent_id`/`selected_child_id`/`reply_to_id`): it points into the messages table and is repointed
 * frequently, so a hard FK would fight insert order. Referential integrity is a model invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->uuid('selected_root_id')->nullable()->after('max_depth')->index();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('selected_root_id');
        });
    }

    protected function table(): string
    {
        return config('beam.threads.tables.threads', 'threads');
    }
};
