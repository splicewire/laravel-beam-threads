<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;
use Splicewire\Beam\Threads\Models\Participant;

/**
 * The `beam_thread_participants` membership table (threads-substrate PRD §2.6, charter 03) — the first-class
 * multi-participant join behind {@see Participant}. A thread has N members (humans AND an AI agent); a
 * message's sole author is a participant (TH-04's message-lineage migration adds `participant_id`).
 *
 * UBIQUITOUS (central + every tenant): this ONE `shared/` migration is registered into BOTH the central
 * `migrate` pass and Stancl's `tenants:migrate` pass by {@see BeamThreadsServiceProvider::bootMigrations()},
 * so the table exists identically in the central schema and every tenant schema.
 *
 * NAME — the `beam_` PREFIX (transitional, config note): the legacy tower estate still owns the unprefixed
 * names until TH-07, so the new table is prefixed (`beam_thread_participants`) to coexist. Resolved from
 * `config('beam.threads.tables.participants')`; TH-07 renames it at cutover.
 *
 * AI-FREE by construction: `kind='agent'` is a category that binds no class here; the `assistant` morph is
 * bound by tower (TH-08). No AI column, driver, or vendor is named.
 *
 * COLUMN SHAPE (PRD §2.6):
 *   - `id` (uuid7 PK — HasUuids on the model produces a time-ordered uuid7)
 *   - `thread_id` (uuid FK → the threads table) — the aggregate parent
 *   - `kind` — `human|agent|visitor|system|external`, NOT NULL (always-set authoritative category)
 *   - `actor_type`/`actor_id` — nullable polymorphic morph under the enforced morph map (alias, not FQCN)
 *   - `display_name`/`handle`/`avatar_url` — nullable join-time SNAPSHOT (a departed/renamed actor still
 *     renders); `display_name` is REQUIRED when `actor_id` is null (system/unlinked-external) — enforced
 *     both in the model ({@see Participant::booted()}) AND by the DB partial CHECK below.
 *   - `role` — `owner|moderator|member|observer` (in-thread capability; a NEW axis separate from HasVisibility)
 *   - `status` — `invited|active|left|removed` (membership lifecycle, distinct from the actor's existence)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            // uuid7 primary key (the model's HasUuids produces a time-ordered uuid7).
            $table->uuid('id')->primary();

            // The aggregate parent — a participant is a member OF a thread. Indexed: participants are
            // listed by thread.
            $table->uuid('thread_id')->index();

            // The always-set authoritative category (indexed so "all agent participants" is one scan).
            $table->string('kind')->index();

            // The nullable polymorphic actor pointer (the enforced morph map stores an ALIAS in
            // `actor_type`). Composite index so a given actor's memberships resolve.
            $table->string('actor_type')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->index(['actor_type', 'actor_id']);

            // The join-time snapshot — a departed/renamed actor still renders. `display_name` is required
            // when there is no actor (enforced by the model + the partial CHECK below).
            $table->string('display_name')->nullable();
            $table->string('handle')->nullable();
            $table->string('avatar_url')->nullable();

            // In-thread capability (a NEW axis, separate from HasVisibility resource-access).
            $table->string('role')->nullable()->index();

            // Membership lifecycle.
            $table->string('status')->nullable()->index();

            $table->timestamps();

            $table->foreign('thread_id')
                ->references('id')
                ->on(config('beam.threads.tables.threads', Beam::table('threads')))
                ->cascadeOnDelete();
        });

        // DB-LEVEL backing of the actor-null snapshot invariant (PRD §2.6): a row with no actor MUST carry a
        // display_name. Postgres partial CHECK; the model enforces it too for a clean application error.
        $this->addActorNullDisplayNameCheck();
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    /**
     * Add the `display_name required when actor_id is null` CHECK. Guarded to Postgres (the app/tenant
     * driver); a no-op elsewhere so a sqlite test bootstrap does not choke.
     */
    protected function addActorNullDisplayNameCheck(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $table = $this->table();

        DB::statement(
            "ALTER TABLE \"{$table}\" ADD CONSTRAINT {$table}_actor_null_requires_display_name "
            .'CHECK (actor_id IS NOT NULL OR display_name IS NOT NULL)'
        );
    }

    protected function table(): string
    {
        return config('beam.threads.tables.participants', Beam::table('thread_participants'));
    }
};
