No em-dashes anywhere in this repo's prose (SKILL.md files, docs, README.md, CHANGELOG.md, ADRs, changesets, code comments). Where a sentence reaches for one, rewrite it instead with a comma, colon, period, parentheses, or a conjunction, whichever the sentence actually wants; never do a blind character substitution.

When reporting information to me, be extremely concise and sacrifice grammar for the sake of concision.

## Agent skills

### Issue tracker

Issues live as GitHub issues on `lisowiecw/filament-media-library`, reached with `gh`. Specs and research notes stay as markdown under `.scratch/<feature-slug>/`. See `docs/agents/issue-tracker.md`.

### Triage labels

Use the canonical labels `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, and `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

This is a single-context repo with root `CONTEXT.md` and `docs/adr/`. See `docs/agents/domain.md`.
