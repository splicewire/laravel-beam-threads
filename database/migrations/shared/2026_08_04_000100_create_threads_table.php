<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Threads\BeamThreadsServiceProvider;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * The `threads` particle table (threads-substrate PRD §2.3, charter 02) — the thin, schema-typed,
 * snapshot-versioned aggregate header behind {@see Thread}.
 *
 * UBIQUITOUS (central + every tenant): this ONE `shared/` migration is registered into BOTH the central
 * `migrate` pass (loadMigrationsFrom) and Stancl's `tenants:migrate` pass (a `--path` push) by
 * {@see BeamThreadsServiceProvider::bootMigrations()}, so `threads` exists
 * identically in the central schema and every tenant schema.
 *
 * COLUMN SHAPE mirrors the beam particle table (`beam_particles`) — the particle machinery columns:
 *   - `id` (uuid7 PK — HasUuids on the model produces a time-ordered uuid7)
 *   - `schema_ref`       — the declared schema BINDING (bare stem `ThreadData` or a versioned `$id`)
 *   - `schema_id`        — the absolute `$id` the payload was written under (migrate-on-read version col)
 *   - `migration_status` — current | pending | failed
 *   - `head_version`     — the Versionable snapshot HEAD pin (the embed_session frozen-config fold)
 *   - `payload` (json)   — the schema-typed ThreadData
 *   - `meta` (json)      — schema-form-agnostic derived/annotation facts
 *   - source provenance/lifecycle-tier columns (PersistsBeamParticle's `source_tier`/`fetched_at`)
 *
 * PLUS the thin queryable PROJECTION of the payload's structural axes — a thread is listed/filtered by
 * these without unpacking JSON (the payload stays canonical, these mirror it):
 *   - `kind`      — provenance/hosting (interactive | embed_template | embed_session)
 *   - `mode`      — the render pivot (chat | board | forum)
 *   - `max_depth` — the reply-nesting cap (nullable: 1 flat, N capped, null unbounded)
 *
 * DERIVED FACTS ARE NOT COLUMNS (charter 02 §4): message count, participant count, and last-activity are
 * computed/cached model accessors — never stored — so they can never trigger a migration.
 *
 * NO REALM COLUMN (ADR-0176 §2): a thread stores no realm/pin/override; realm is the ambient, unstored,
 * read-only route axis the render layer reads via `RealmRegistry::effective()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            // uuid7 primary key (the model's HasUuids produces a time-ordered uuid7).
            $table->uuid('id')->primary();

            // Particle machinery — the declared binding + migrate-on-read/versioning columns.
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();

            // Source provenance + lifecycle tier (PersistsBeamParticle casts these; mirror the particle table).
            $table->string('source_tier')->default('local')->index();
            $table->timestamp('fetched_at')->nullable();

            // Human-facing header identity (TH-07 header migration): the tower Thread's `title` moves onto the
            // particle, and a `slug` is generated from it via spatie/laravel-sluggable's HasSlug on the model.
            $table->string('title')->nullable();
            $table->string('slug')->nullable()->index();

            // Queryable projection of the payload's structural axes (mode-invariant; NO realm).
            $table->string('kind')->nullable()->index();
            $table->string('mode')->nullable()->index();
            $table->integer('max_depth')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('beam.threads.tables.threads', Beam::table('threads'));
    }
};
