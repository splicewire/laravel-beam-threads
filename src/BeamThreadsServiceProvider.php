<?php

namespace Splicewire\Beam\Threads;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * The generic multi-participant threads particle provider (threads-substrate ticket TH-01).
 *
 * beam-threads is a beam-family package: it depends DOWN on beam-core (the schema-typed,
 * snapshot-versioned, migrate-on-read particle substrate) + the open data-schemas foundation,
 * and it must never reach UP onto the tower/satellite tiers that consume it. It is AI-FREE by
 * construction — a thread is a participant-agnostic conversation surface; no AI vendor, driver,
 * or model is referenced here.
 *
 * This is a SHELL: no models or tables ship yet (they land in later tickets). This provider
 * establishes the three durable seams a thread particle needs from boot zero:
 *
 *  1. The participant MORPH MAP — the stable aliases a thread's participants/messages store on
 *     their `*_type` morph column, bound here so the concrete model class names can be renamed in
 *     later tickets without orphaning stored rows.
 *  2. The `database/migrations/shared/` residency — registered into BOTH the central `migrate`
 *     pass (loadMigrationsFrom) and the Stancl `tenants:migrate` pass (a `--path` push onto the
 *     tenancy migration parameters), because a thread's tables are ubiquitous (central + every
 *     tenant). Empty for now; the mechanism is wired so a later ticket only drops files in.
 *  3. The `config/beam/threads.php` table-prefix + driver seam (beam-family convention: config ships
 *     under `config/beam/` and merges under the `beam.threads` key, like beam.taxonomy / beam.ux).
 */
class BeamThreadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam/threads.php', 'beam.threads');
    }

    public function boot(): void
    {
        $this->bootParticipantMorphMap();
        $this->bootConfig();
        $this->bootMigrations();
    }

    /**
     * Enforce the thread-participant morph map — the small, CLOSED vocabulary of participant kinds a
     * thread admits. Each alias binds to the intended future participant-model FQCN; the concrete
     * classes land in later tickets, so today these are the durable class-STRINGS the morph column
     * will store, pinned now so a later rename of the concrete model leaves stored rows resolvable.
     *
     * Mirrors how beam-core pins its own particle morph alias (see BeamServiceProvider) — the token is
     * the durable identity, the class is free to move. beam-core registers ADDITIVELY (morphMap) because
     * a rich host has many class-string morphs; here the vocabulary is beam-threads-OWNED and closed, so
     * it is enforced (enforceMorphMap) to keep the participant set honest.
     *
     * DELIBERATELY ABSENT: the AI-driver participant alias. That kind is a tower-tier concern — the tower
     * binds it in threads-substrate ticket 08 — and beam-threads stays AI-free, so it is NOT registered
     * here. Adding it in this free-tier package would drag the AI vocabulary DOWN across the vendor seam.
     */
    protected function bootParticipantMorphMap(): void
    {
        Relation::enforceMorphMap([
            // A first-class authenticated account participant. Resolved from config so a host can point
            // this at its own user model; the placeholder default is the future beam-threads participant
            // concrete (lands in a later ticket).
            'user' => config('beam.threads.morph_map.user', \Splicewire\Beam\Threads\Models\Participant\UserParticipant::class),

            // An anonymous / pre-auth visitor participant (mirrors beam-embed's Visitor identity).
            'visitor' => config('beam.threads.morph_map.visitor', \Splicewire\Beam\Threads\Models\Participant\VisitorParticipant::class),

            // A non-human system/automation participant (notices, state transitions, integrations) — a
            // deterministic, non-AI actor.
            'system' => config('beam.threads.morph_map.system', \Splicewire\Beam\Threads\Models\Participant\SystemParticipant::class),

            // A participant identified by an out-of-band external reference (a foreign system's actor).
            'external' => config('beam.threads.morph_map.external', \Splicewire\Beam\Threads\Models\Participant\ExternalParticipant::class),

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

    /**
     * Register the ubiquitous `shared/` migration dir into BOTH migration passes.
     *
     * A thread's tables live in BOTH the central and every tenant schema, so the ONE `shared/` dir is
     * registered via BOTH mechanisms: `loadMigrationsFrom()` so the central `migrate` pass auto-discovers
     * it, and a `--path` push onto Stancl's `tenancy.migration_parameters` so the `tenants:migrate` pass
     * (which does NOT auto-discover) runs the same dir. Same source dir feeds both, so the shape is
     * identical central + tenant. (Mirrors the beam-taxonomy shared/ residency idiom.)
     *
     * The dir is EMPTY today (a shell — no tables ship yet). The wiring is established so a later ticket
     * that homes the thread tables only drops migration files into `shared/`; both passes pick them up
     * with no further provider edit. Gated by `config('beam.threads.register_migrations', true)` so a host that
     * vendors the tables elsewhere can turn it off.
     */
    protected function bootMigrations(): void
    {
        if (! config('beam.threads.register_migrations', true)) {
            return;
        }

        $sharedDir = realpath(__DIR__.'/../database/migrations/shared')
            ?: __DIR__.'/../database/migrations/shared';

        // Central estate — auto-discovered by `migrate`.
        $this->loadMigrationsFrom($sharedDir);

        // Tenant estate — pushed onto Stancl's `--path` array (no auto-discovery). Same dir, so the
        // table shape is identical central + tenant.
        $paths = config('tenancy.migration_parameters.--path', []);

        if (! in_array($sharedDir, $paths, true)) {
            config()->push('tenancy.migration_parameters.--path', $sharedDir);
        }
    }
}
