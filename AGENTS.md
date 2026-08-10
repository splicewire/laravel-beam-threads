> You are in **rushing/laravel-beam-threads** — the generic multi-participant threads particle for the Beam family.

A Laravel package providing a schema-typed, snapshot-versioned conversation thread on beam-core.
Participant-agnostic and AI-free by construction — a thread is a passive conversation surface; no
AI vendor, driver, or model is referenced here. Free-tier arm of the Beam family: depends down on
`splicewire/laravel-beam` (the particle substrate) and `schemastud/laravel-data-schemas` (Data),
and never reaches up onto the tower/satellite tiers that consume it.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
