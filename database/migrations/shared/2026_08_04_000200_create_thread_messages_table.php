<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;
use Splicewire\Beam\Threads\Data\ThreadMessageData;
use Splicewire\Beam\Threads\Models\Message;

/**
 * The `beam_thread_messages` particle table (threads-substrate PRD §2.4, charter 02, ADR-0174) — the
 * schema-typed, migrate-on-read message particle behind {@see Message}. A message belongs to a thread
 * (`thread_id` FK) and carries an ordered `Segment[]` content array + a keyed `references` collection,
 * both FOLDED into the `payload` JSON column (the schema-typed source of truth).
 *
 * UBIQUITOUS (central + every tenant): this ONE `shared/` migration is registered into BOTH the central
 * `migrate` pass (loadMigrationsFrom) and Stancl's `tenants:migrate` pass (a `--path` push) by
 * {@see BeamThreadsServiceProvider::bootMigrations()}, so `beam_thread_messages` exists identically in the
 * central schema and every tenant schema.
 *
 * NAME — the `beam_` PREFIX (transitional, PRD §2.4 / config note): the LEGACY tower `thread_messages`
 * table/model is still live until TH-07/08, so the new particle table is PREFIXED (`beam_thread_messages`)
 * to coexist without a duplicate-table collision — exactly as TH-02's `threads` was prefixed to
 * `beam_threads`. Resolved from `config('beam.threads.tables.messages')`; TH-07 renames it to the
 * canonical unprefixed `thread_messages` at cutover.
 *
 * COLUMN SHAPE mirrors the beam particle table (`beam_particles`) — the particle machinery columns — but
 * WITHOUT the versioning-history columns (`head_version`): a message is NOT Versionable (immutable capture,
 * like {@see BeamSubmission}; edit/regen history is conversation-graph lineage in
 * TH-04, not linear snapshots). It KEEPS `schema_ref`/`schema_id`/`migration_status` for migrate-on-read.
 *   - `id` (uuid7 PK — HasUuids on the model produces a time-ordered uuid7)
 *   - `thread_id` (uuid FK → the threads table) — the sole aggregate parent (charter: messages reference
 *     `thread_id` and hydrate as a relation; they are NOT folded into the thread payload)
 *   - `schema_ref`       — the declared schema BINDING (the {@see ThreadMessageData} FQCN)
 *   - `schema_id`        — the absolute `$id` the payload was written under (migrate-on-read version col)
 *   - `migration_status` — current | pending | failed
 *   - `payload` (json)   — the schema-typed ThreadMessageData (content Segment[] + references)
 *   - `meta` (json)      — schema-form-agnostic derived/annotation facts
 *   - source provenance/lifecycle-tier columns (PersistsBeamParticle's `source_tier`/`fetched_at`)
 *
 * NO `head_version` (NOT Versionable). NO AI-runtime columns (ADR-0174/0175): `assistant_id`/`model`/
 * `model_params`/`instructions_*`/`max_tool_steps` are ABSENT — they attach via the participant seam
 * (TH-08). NO bespoke `content` string column: `content:string` is a DERIVED accessor coalescing the
 * `Segment[]` text, never a stored source of truth.
 *
 * SIDECAR, NOT COLUMNS (ADR-0174 §2): media (Spatie MediaLibrary) + embeddings (pgvector `morphMany`)
 * stay sidecar — they physically cannot live in JSON (file storage / vector index) — surfaced through the
 * `references` projection but stored OUTSIDE this table's payload, in the host's own media/embedding tables.
 *
 * NO LINEAGE COLUMNS YET (TH-04): `participant_id`, `parent_id`, `selected_child_id`, `reply_to_id`,
 * `origin_message_id`, `role` are the message-lineage / authorship concern of a LATER ticket; this ticket
 * lands the neutral content particle only. `thread_id` is the one edge this ticket needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            // uuid7 primary key (the model's HasUuids produces a time-ordered uuid7).
            $table->uuid('id')->primary();

            // The sole aggregate parent — a message references its thread; it is NOT folded into the
            // thread payload. FK → the (prefixed) threads table. Indexed: messages are listed by thread.
            $table->uuid('thread_id')->index();

            // Particle machinery — the declared binding + migrate-on-read columns. NO `head_version`
            // (a message is NOT Versionable — immutable capture; lineage, not linear snapshots).
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();

            // The schema-typed content (Segment[] folded) + agnostic annotation facts.
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();

            // Source provenance + lifecycle tier (PersistsBeamParticle casts these; mirror the particle table).
            $table->string('source_tier')->default('local')->index();
            $table->timestamp('fetched_at')->nullable();

            $table->timestamps();

            $table->foreign('thread_id')
                ->references('id')
                ->on(config('beam.threads.tables.threads', 'threads'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('beam.threads.tables.messages', 'thread_messages');
    }
};
