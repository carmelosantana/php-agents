# Agent instructions

The single source of instructions for coding agents in this repo. `CLAUDE.md` only points here.

## What this is

`carmelosantana/php-agents`: a PHP 8.4 library (agent loop, providers, tools, MCP client) on
Packagist. Runtime dependencies are `symfony/http-client` and `psr/log` only; adding one is a
decision for the maintainer, not a refactor.

## Commands

| Task | Command |
| --- | --- |
| Install | `composer install` |
| Tests (Pest) | `composer test` |
| Static analysis (PHPStan) | `composer analyse` |
| Both, as CI runs them | `./scripts/ci-test.sh` |

Both must pass before every commit.

## Rules

- TDD: write the failing test, see it fail, implement, see it pass, commit. Small commits.
- `declare(strict_types=1);` directly after `<?php` in every PHP file.
- Public API changes are additive: new parameters are trailing and optional, interfaces are not
  widened or narrowed (consumers implement `ProviderInterface` and extend `AbstractAgent`).
  Ask before any breaking change.
- Docs that show API calls are pinned by tests in `tests/Unit/Docs/`; update both together.
- Comments state what the code does now, never a rejected alternative.
- Decisions that shape the API go in `docs/adr/` as an ADR. Do not commit plans, specs, work
  ledgers, tracker references, emails, LAN addresses, local paths or tokens.
- Commits read as the maintainer's own: no AI attribution lines.
- GitHub Actions are pinned to full commit SHAs with the version in a comment.

## Releases

Tag `vX.Y.Z` on `main`; `.github/workflows/release.yml` tests, builds and publishes the GitHub
release, and Packagist picks up the tag. Only the maintainer tags.
