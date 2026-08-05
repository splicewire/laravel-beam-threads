<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Threads\Concerns\ConversationParticle;

/**
 * Add the `visibility` column to the `beam_threads` particle (TH-07 header migration, phase 4, Fork 1b).
 *
 * `visibility` is the `rushing/permission-cascade` HasVisibility resource-ACL tier — read at the WHERE level
 * by `BaseModelPolicy::scopeForUser` (`whereIn("{table}.visibility", $tiers)`), so it CANNOT be an accessor
 * off a sidecar: it must be a real, queryable column on the table the conversation model queries. When the
 * tower Thread header moves onto this particle (its `$table` repoints to `beam_threads`), that queried table
 * IS `beam_threads` — so the column lives here, alongside `title`/`slug`. `HasVisibility` stays on the shared
 * {@see ConversationParticle} trait; this migration just gives it its column.
 *
 * An add-migration (not a create edit) because phase 1's create-migration already ran on live dev DBs.
 * UBIQUITOUS (central + tenant) like the create-migration it extends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('visibility')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }

    protected function table(): string
    {
        return config('beam.threads.tables.threads', 'threads');
    }
};
