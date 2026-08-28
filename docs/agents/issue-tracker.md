# Issue tracker: GitHub Issues

Issues and specs for this repo live as GitHub issues on `lisowiecw/filament-media-library`, reached with the `gh` CLI. Long-form primary sources (specs, research notes) stay as markdown in `.scratch/<feature-slug>/` and are linked from the issues that use them.

## Conventions

- One issue per ticket. Implementation tickets carry the `build` label; wayfinder decision tickets carry `wayfinder`.
- The spec stays on disk at `.scratch/<feature-slug>/spec.md`, as do research notes. Issues reference them by path.
- Triage state is a label, never a line in the body (see `triage-labels.md` for the role strings).
- Blocking is a native GitHub issue dependency, not prose. The body may restate it for readability, but the dependency is the source of truth.
- Comments and conversation history are issue comments.

## When a skill says "publish to the issue tracker"

```bash
gh issue create -t "<title>" -F <body-file>.md -l ready-for-agent,build
```

Write the body to a file first rather than passing `-b`, so markdown survives intact. Add dependencies afterwards, see Blocking below.

## When a skill says "fetch the relevant ticket"

```bash
gh issue view <number> --comments
```

The user will normally pass the issue number or URL directly.

## When a skill says "close out" or "mark resolved"

```bash
gh issue close <number> -r completed
```

Post the outcome as a comment before closing, so the decision survives on the issue rather than only in a commit message.

## Blocking

Add a dependency (issue `<n>` is blocked by issue `<b>`):

```bash
gh api repos/{owner}/{repo}/issues/<n>/dependencies/blocked_by \
  -F issue_id=$(gh api repos/{owner}/{repo}/issues/<b> --jq .id)
```

Note `-F` (typed) rather than `-f`: the endpoint wants an integer, and it wants the issue's numeric `id`, not its number. List what an issue is blocked by with `gh api repos/{owner}/{repo}/issues/<n>/dependencies/blocked_by`. An issue is unblocked when every issue in that list is closed.

## Wayfinding operations

Used by `/wayfinder`. The map is one issue; each decision ticket is its own issue linked from it.

- **Map**: an issue labelled `wayfinder:map`, holding the Notes / Decisions-so-far / Fog body.
- **Child ticket**: an issue labelled `wayfinder` plus a `type:` label (`type:research`, `type:prototype`, `type:grilling`, `type:task`), with the question in the body.
- **Blocking**: native dependencies, as above.
- **Frontier**: `gh issue list -l wayfinder --state open`, then drop any issue whose `blocked_by` list still holds an open issue, and any carrying the `claimed` label. Lowest issue number wins.
- **Claim**: `gh issue edit <n> --add-label claimed` before any work.
- **Resolve**: comment the answer under an `## Answer` heading, `gh issue edit <n> --remove-label claimed`, `gh issue close <n> -r completed`, then edit the map issue to append the gist plus `#<n>` to Decisions so far.
