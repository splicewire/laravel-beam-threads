<?php

namespace Splicewire\Beam\Threads;

use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Threads\Doctor\BeamThreadsMigrationsAudit;
use Splicewire\Beam\Threads\Models\Participant\ExternalParticipant;
use Splicewire\Beam\Threads\Models\Participant\SystemParticipant;
use Splicewire\Beam\Threads\Models\Participant\UserParticipant;
use Splicewire\Beam\Threads\Models\Participant\VisitorParticipant;

/**
 * The generic multi-participant threads particle provider (threads-substrate ticket TH-01).
 *
 * beam-threads is a beam-family package: it depends DOWN on beam-core (the schema-typed,
 * snapshot-versioned, migrate-on-read particle substrate) + the open data-schemas foundation,
 * and it must never reach UP onto the tower/satellite tiers that consume it. It is AI-FREE by
 * construction — a thread is a participant-agnostic conversation surface; no AI vendor, driver,
 * or model is referenced here.
 *
 * This provider establishes the three durable seams a thread particle needs from boot zero:
 *
 *  1. The participant MORPH MAP — the stable aliases a thread's participants/messages store on
 *     their `*_type` morph column, bound here so the concrete model class names can be renamed in
 *     later tickets without orphaning stored rows.
 *  2. The `threads`/`thread_messages`/`thread_participants` tables — UBIQUITOUS (central + every
 *     tenant), so they ship as PUBLISH-ONLY spatie/laravel-package-tools stubs (`runsMigrations`
 *     stays FALSE, the estate convention) registered via `->hasMigrations([...])` in
 *     {@see self::configurePackage()}, each declared under the SINGLE `shared/…` destination
 *     rather than a duplicated flat+tenant pair. beam-threads never `loadMigrationsFrom`'s its own
 *     vendor source; `vendor:publish --tag=beam-threads-migrations` re-stamps + sequences
 *     timestamped copies into the HOST's `database/migrations/shared/` at install time.
 *     beam-tenancy's `registerSharedMigrationsPath()` is what then runs that one directory in BOTH
 *     the central `migrate` pass and Stancl's tenant pass — beam-threads composes that mechanism
 *     purely by publishing into the same `shared/` destination it already registers, since the
 *     method itself is protected on a different provider (mirrors tower's own conversion, 92919bb).
 *  3. The `config/beam/threads.php` table-prefix + driver seam (beam-family convention: config ships
 *     under `config/beam/` and merges under the `beam.threads` key, like beam.taxonomy / beam.ux).
 */
class BeamThreadsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-threads')
            // Publish-only .stub migrations (NOT ->discoversMigrations(), which loads at runtime).
            // Each of the 3 particle tables is UBIQUITOUS (central + every tenant — "everything is
            // shared by default"), so each publishes to the SINGLE `shared/…` destination, not a
            // duplicated flat+tenant pair. beam-tenancy's registerSharedMigrationsPath() runs that
            // one host directory in both the central `migrate` pass and Stancl's tenant pass.
            // Declared order matters: `create_thread_messages_table` squashed in the (formerly
            // separate) authorship/lineage ALTER, whose `participant_id` column carries a real FK to
            // `thread_participants` — so participants must sort ahead of messages. package-tools'
            // generateMigrationName timestamps each entry a second apart in listed order.
            ->hasMigrations([
                'shared/create_threads_table',
                'shared/create_thread_participants_table',
                'shared/create_thread_messages_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam/threads.php', 'beam.threads');
    }

    public function packageBooted(): void
    {
        $this->bootParticipantMorphMap();
        $this->bootConfig();

        // beam-threads is itself an "operator" of the estate-wide publish-only stub migrations
        // convention — self-registers the doctor/operator check on ITS OWN migrations, same as every
        // other beam-* package registers it on theirs (guarded: a host predating the manifest still
        // boots beam-threads fine).
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-threads',
                BeamThreadsMigrationsAudit::class,
            );
        }
    }

    /**
     * Enforce the thread-participant morph map — the small, CLOSED vocabulary of participant kinds a
     * thread admits. Each alias binds to the intended future participant-model FQCN; the concrete
     * classes land in later tickets, so today these are the durable class-STRINGS the morph column
     * will store, pinned now so a later rename of the concrete model leaves stored rows resolvable.
     *
     * Mirrors how beam-core pins its own particle morph alias (see BeamServiceProvider) — the token is
     * the durable identity, the class is free to move. Registered ADDITIVELY (`Relation::morphMap`),
     * NEVER `enforceMorphMap`: a beam-composing host (splicewire-app) has MANY models on class-string
     * morphs (`Tenant`, `Assistant`, workflow subjects, …), and `enforceMorphMap` toggles GLOBAL strict
     * mode (`requireMorphMap`) that then rejects every one of them (`ClassMorphViolationException`) —
     * breaking tenant provisioning app-wide. The participant vocabulary stays honest by validation at the
     * write path, not by imposing global strict morphing on the whole host.
     *
     * DELIBERATELY ABSENT: the AI-driver participant alias. That kind is a tower-tier concern — the tower
     * binds it in threads-substrate ticket 08 — and beam-threads stays AI-free, so it is NOT registered
     * here. Adding it in this free-tier package would drag the AI vocabulary DOWN across the vendor seam.
     */
    protected function bootParticipantMorphMap(): void
    {
        Relation::morphMap([
            // A first-class authenticated account participant. Resolved from config so a host can point
            // this at its own user model; the placeholder default is the future beam-threads participant
            // concrete (lands in a later ticket).
            'user' => config('beam.threads.morph_map.user', UserParticipant::class),

            // An anonymous / pre-auth visitor participant (mirrors beam-embed's Visitor identity).
            'visitor' => config('beam.threads.morph_map.visitor', VisitorParticipant::class),

            // A non-human system/automation participant (notices, state transitions, integrations) — a
            // deterministic, non-AI actor.
            'system' => config('beam.threads.morph_map.system', SystemParticipant::class),

            // A participant identified by an out-of-band external reference (a foreign system's actor).
            'external' => config('beam.threads.morph_map.external', ExternalParticipant::class),

            // NOTE: the AI-driver participant alias is intentionally NOT bound here — the tower tier binds
            // it in ticket 08. beam-threads is AI-free.
        ]);
    }

    protected function bootConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam/threads.php' => $this->app->configPath('beam/threads.php'),
            ], 'beam-threads-config');
        }
    }
}
