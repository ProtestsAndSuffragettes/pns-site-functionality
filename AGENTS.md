# AGENTS.md

## Contract

This internal plugin is standalone under a larger WordPress installation. Work
only in this repository unless the task explicitly expands scope. Its support
baseline is WordPress 7.0+ and PHP 8.4--8.5; keep the plugin header, Composer,
PHPCS, PHPStan, CI, and README aligned.

The RAN quality profile is `wordpress-plugin`. There are no frontend assets;
Node tooling checks the repository's documentation and configuration.

Use `composer check` and `pnpm check` from locked dependencies. `src/` is the
PSR-4 first-party source root. Draft Editor can edit its own and other users'
ordinary post drafts. Keep status, content-type, ownership, and deletion checks
on the server. Store no broad editing grants that survive plugin deactivation.

Release Please manages versions from `main`; do not hand-edit a generated
release, tag, or changelog. A GitHub release, archive, site deployment, and
publication are separate actions requiring explicit authorization.

## Provenance

The quality and release structure was adapted from RAN Starter commit
`283ee0177022375d7e4b59194d05834b98c3884f`. Retain the locked shared
`ran/coding-standards` and `@rocketsarenostalgic/quality-config` ancestry while
keeping this internal plugin's identity, source selection, support scope, and
asset-free profile local.
