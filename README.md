# splicewire/laravel-beam-threads

The generic multi-participant **threads** particle for the Beam family — a schema-typed,
snapshot-versioned conversation thread on beam-core. Participant-agnostic and **AI-free by
construction**: a thread is a passive conversation surface; no AI vendor, driver, or model is
referenced here.

Free-tier arm of the Beam family (ADR-0138 / ADR-0092). It depends **DOWN** on
`splicewire/laravel-beam` (the particle substrate) and `schemastud/laravel-data-schemas` (Data),
and it never reaches **up** onto the tower/satellite tiers that consume it.

## Status: shell

This is a scaffold (threads-substrate ticket TH-01). It ships **no models or tables yet** — those
land in later tickets. What boots today:

- **Participant morph map** — the closed vocabulary of participant kinds a thread admits
  (`user`, `visitor`, `system`, `external`), enforced via `Relation::enforceMorphMap`. The
  AI-driver participant alias is **intentionally absent** — the tower tier binds it (ticket 08).
- **`database/migrations/shared/`** — an (empty) ubiquitous migration dir wired into **both** the
  central `migrate` pass (`loadMigrationsFrom`) and the `tenants:migrate` pass (a `--path` push
  onto Stancl's tenancy migration parameters). A later ticket only drops files in.
- **`config/threads.php`** — the turn-driver seam (`null` by default) and the particle
  table-prefix map (`threads` / `thread_messages` / `thread_participants`).

## Install

```
composer require splicewire/laravel-beam-threads
```

The service provider auto-registers. Publish the config with:

```
php artisan vendor:publish --tag=beam-threads-config
```
