# South Poll Membership & Classifieds Plugin — Knowledge Base

This folder (`app/public/temp/`) is the single source of truth for the custom-plugin build that replaces the
Classified Listing (RadiusTheme) plugin family on southpoll.com. **Nothing about this project should live only in
chat memory.** Every fact, decision, script, and log entry goes here.

Rule: before acting on anything "remembered", re-read the relevant file below. When a fact changes, update the file
and add a dated entry to `06-build-log.md`.

## What governs the build

1. **`plugin architecture.md`** — Carson's design document. Dictates what is built and how. It outranks everything else here.
2. `02-architecture-roadmap.md` — the technical design derived from it (schema, routing, roles, Stripe, cron, email, admin, phases).
   Items marked *Proposed* are open until answered in `07-open-questions.md` (D-xx).
3. `03-build-todo.md` — the step-by-step checklist we work through, phase by phase.
4. `01-proposal-requirements.md` — the commercial scope agreed with SPGCA (REQ ids); use it to check coverage, not to decide design.
5. `branding/brand-style-guide.md` — visual tokens the plugin must follow (kept separate from the build docs).
6. `research/` — **reference only**: how the current site and the old plugins work, and what data exists. Useful for migration and parity
   checks; never a guiding principle.

## Folder map

| Path | What it holds |
|---|---|
| `00-README.md` | This index and the working conventions. |
| `plugin architecture.md` | Carson's design document (the specification). |
| `02-architecture-roadmap.md` | Technical architecture derived from the spec: identity, data model, roles, routing, front-end stack, payments, cron, email, admin, analytics, migration, phases. |
| `03-build-todo.md` | Phase-by-phase checklist (T0.1 … T6.6) with decision gates. |
| `01-proposal-requirements.md` | The Ranch House Designs proposal distilled into numbered requirements (REQ-M1-01 …), inclusions, exclusions, timeline, payment terms. |
| `branding/brand-style-guide.md` | Colors, typography, layout, buttons, icons, logo assets, and the CSS token set the plugin must use, each value with its source. |
| `06-build-log.md` | Dated, append-only log of sessions, decisions, and changes. |
| `07-open-questions.md` | Discovery questions and decisions still needed from SPGCA / Ranch House Designs. |
| `proposal_extracted.txt` | Plain-text extraction of the proposal PDF (via pdftotext). |
| `research/` | Reference-only analysis of the current site: `02-site-architecture.md`, `03-classified-listing-analysis.md`, `04-data-inventory-and-migration.md`, generated `db-*-raw.md` reports. See `research/README.md`. |
| `scripts/` | Repeatable PHP/bash tooling (WordPress CLI bootstrap, reports, plugin toggle). See `scripts/README.md`. |

## Conventions

- Dates are absolute (YYYY-MM-DD). The initial survey was done 2026-10-18.
- Requirement IDs: `REQ-M<module>-<nn>` from `01-proposal-requirements.md`. Reference them in commits, log entries, and code comments.
- Open questions get `Q-<nn>` IDs in `07-open-questions.md`; when answered, record the answer in place and log it.
- Generated reports (`research/db-*.md`) are regenerated, never hand-edited.
- Secrets (Stripe keys, license keys, reCAPTCHA keys, DB password) are never written into these files. Reports mask them.
- Scripts and docs produced for this project go in this folder only. Plugin code will go in `wp-content/plugins/<plugin-slug>/` once the design document names the slug.

## Quick start for a new session

1. Read `06-build-log.md` (latest entries first) to see where things stand.
2. Read the design document (when present) and `07-open-questions.md` for unresolved decisions.
3. Consult `research/` only when touching existing data or checking parity with the old system.
4. Use `branding/brand-style-guide.md` for any front-end work.
5. Run `scripts/wp-run.sh ping.php` to confirm the local WordPress + MySQL are reachable before running any report.
