# Workflow: changes and releases

Two procedures, both enforced by configuration rather than by convention: main takes changes through a pull request, `matrix` must pass, and squash is the only merge method. Nothing here is advisory.

## Standing rules

- **main is the only permanent branch.** Every other branch is temporary and deletes itself on merge. Throwaway work worth keeping (prototypes, research) is an annotated tag, not a branch: `prototype/06-picker-workflow`, `research/svg-sanitizer` and the rest are tags, and the tickets that cite them use `git show <tag>:<path>`.
- **The PR body becomes the commit message.** Squash merges take the PR title as the subject and the body as the message, so the prose that explains a change is written in the PR, not in the branch's commits.
- **Never merge with `--admin`.** It bypasses `matrix` as well as the review requirement, which is the one protection main has.
- Commit messages carry no `Co-Authored-By` trailer.
- The pre-commit hook runs PHPStan, Pint, Pest and type coverage, so a commit that breaks them does not get made.

## A change or a fix

1. **Take the ticket.**

   ```bash
   gh issue list --label ready-for-agent
   gh issue view <number>
   ```

   An issue somebody else filed goes through `/triage` first. One that `/to-tickets` produced is already agent-ready and is not triaged again.

2. **Branch off current main.**

   ```bash
   git checkout main && git pull
   git checkout -b <short-slug>
   ```

3. **Build it.** `/implement <number>` drives `/tdd` one red-green slice at a time. Clear context between tickets: each one is self-contained.

4. **Review the diff before it leaves the machine.** `/code-review` reads it on both axes, Standards and Spec.

5. **Push and open the pull request.**

   ```bash
   git push -u origin <short-slug>
   gh pr create --title "<title>" --body "<why, not what>"
   ```

6. **Wait for the one required check.** `matrix` stands for all seven legs of the compatibility matrix plus the browser suite.

   ```bash
   gh pr checks --watch
   ```

7. **Merge, then clean up the local copy.** The remote branch deletes itself.

   ```bash
   gh pr merge <number> --squash        # --auto merges as soon as it is green
   git checkout main && git pull
   git branch -D <short-slug>
   ```

8. **Close the issue by hand.** Tick its boxes, then `gh issue close <number>`. A `Closes #N` trailer does not fire here.

## A release

1. **Confirm the commit you are about to tag is green.**

   ```bash
   git checkout main && git pull
   gh api repos/lisowiecw/filament-media-library/commits/$(git rev-parse HEAD)/check-runs \
     --jq '.check_runs[] | select(.name == "matrix") | .conclusion'
   ```

2. **Choose the number.** The package is pre-`1.0.0`: a minor carries features or any of the four breaking changes in [UPGRADING](../../UPGRADING.md), a patch carries fixes. A migration that only adds a nullable column, or backfills a value the package derives itself, is not a breaking change.

3. **Bump the `0.x` line in `UPGRADING.md`** and land it through the change procedure above. It is the only file edited by hand for a release.

4. **Take the notes out of the changelog.**

   ```bash
   awk '/^## \[Unreleased\]/{f=1;next} /^## \[v/{f=0} f' CHANGELOG.md > /tmp/notes.md
   ```

   Do not edit `CHANGELOG.md`: step 6 does that, and a hand edit collides with it.

5. **Tag and publish.**

   ```bash
   gh release create vX.Y.Z --target main --title vX.Y.Z --notes-file /tmp/notes.md
   ```

   `release-guard.yml` fires on the tag push and on the release, polls `matrix` on that exact commit for up to thirty minutes, and blocks the release unless it succeeded. A blocked release means deleting the tag, fixing the leg, and tagging again.

6. **Merge the changelog pull request.** `update-changelog.yml` opens `changelog/vX.Y.Z`, moving the published notes out of Unreleased into a dated section. It opens a PR rather than pushing, because main takes changes through a PR and a bypass would have to cover every workflow in the repository.

   ```bash
   gh pr list
   gh pr merge <number> --squash
   ```

   This is the last step of a release. Until it merges, the changelog on main still says Unreleased.

7. **Verify.**

   ```bash
   gh release view vX.Y.Z
   gh run list --limit 5
   ```
