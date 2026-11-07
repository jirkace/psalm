---
name: routine-pr-conflicts
description: Use when running the recurring routine that checks the user's own open vimeo/psalm pull requests for merge conflicts with master or for a maintainer's request to rebase, or when asked to "check my PRs for conflicts", "resolve conflicts on my PRs", "rebase my PR", "zkontroluj konflikty v PR".
---

# Routine: merge conflicts in my open PRs

Goal: every open PR of the user (author `jirkace`) merges cleanly into `master`, and every
rebase a maintainer asked for is done.
Work in the main session, no subagents (see AGENTS.md).

## 1. List PRs with a conflict

```bash
gh pr list --repo vimeo/psalm --author @me --state open --limit 100 \
  --json number,title,headRefName,headRefOid,mergeable,mergeStateStatus,isDraft,isCrossRepository
```

`mergeable` is computed lazily: `UNKNOWN` means GitHub has not finished, so re-query
after ~10 s (up to 3 times). Conflicting = `mergeable == "CONFLICTING"` /
`mergeStateStatus == "DIRTY"`. Skip everything else (`BEHIND` alone is not a conflict).

## 1b. Find open rebase requests

A rebase request is an issue comment or review comment on the PR that
- is by someone other than `jirkace` with `authorAssociation` `OWNER`, `MEMBER` or
  `COLLABORATOR` (e.g. `orklah`), and
- asks to rebase (`rebase`, `rebasuj`, "please rebase", "needs a rebase"), and
- is newer than the PR head commit's committer date (a rebase rewrites it, so a push after
  the comment means the request is done).

```bash
gh api repos/vimeo/psalm/issues/<n>/comments --jq '.[] | {user:.user.login, assoc:.author_association, at:.created_at, body:.body}'
gh api repos/vimeo/psalm/pulls/<n>/comments --jq '.[] | {user:.user.login, assoc:.author_association, at:.created_at, body:.body}'
git log -1 --format=%cI <head-sha>
```

Such a PR gets the **rebase procedure** below, whether or not it currently conflicts.

## 2. Resolve each conflicting PR (merge)

1. `git fetch origin master` and fetch the PR head from the fork remote
   (`git_origin_jirkace`, the user's fork; check with `git remote -v`).
2. Create a scratch worktree under `/tmp/psalm-pr-<n>` on the PR branch. It needs its own
   `vendor/` (`cp -R`, never a symlink) only if you run Psalm or a test in it.
3. `git merge origin/master` into the PR branch (a merge commit, not a rebase: no force
   push, review history stays intact). Resolve conflicts by understanding both sides,
   not by taking one side.
4. Big provider files (TaintTest, ...): after resolving, run the verification script from
   AGENTS.md ("Resolving merge conflicts in big provider files") — every 12-space
   `'name' => [ ... ]` entry of both parents must appear verbatim in the result.
5. `php -l` on changed PHP files; `vendor/bin/phpcs` on them and check the exit code.
6. Run only the PR's own new/changed test cases, one case at a time
   (`--filter '@caseName'`). Never whole files or suites; CI does that.
7. Commit the merge (no `Claude-Session:` trailer, per memory), push to the fork branch
   with a plain `git push` (never `--force`, never to `origin`; force is only for 2b).

## 2b. Rebase procedure (only when a maintainer asked for it)

Use a rebase *instead of* the merge from step 2 for these PRs; a maintainer asked for a
linear history, so a merge commit does not satisfy them.

1. Scratch worktree `/tmp/psalm-pr-<n>`; `git fetch origin master` and fetch the PR branch
   from the fork remote (`git_origin_jirkace`). Remember the fetched branch sha as `<old>`.
2. `git rebase origin/master`. Resolve each conflict by understanding both sides. In
   provider files keep both sides' entries (git factors the shared closing `}` / `],`
   lines out of the hunk, so add the missing one between the blocks).
3. Verify: no conflict markers, `php -l`, every test method / provider entry of both
   parents still present, `phpcs` rc on changed files. Run only the PR's own new test
   cases and one test case from the conflicting master side, one at a time.
   Use `php8.4` if the default `php` lacks `dom`/`simplexml`.
4. Push with a lease on the sha you fetched, never a plain `--force`:
   `git push --force-with-lease=<branch>:<old> git_origin_jirkace HEAD:refs/heads/<branch>`.
   If the lease is rejected (someone pushed meanwhile), stop and report; do not retry
   without re-fetching and re-checking what changed.
5. Confirm with `gh pr view <n> --repo vimeo/psalm --json headRefOid,mergeable` (the head
   updates after a few seconds). Do **not** reply to or resolve the maintainer's comment;
   put a proposed one-line reply in the report.

Force-pushing is allowed only here, only to the user's fork branch, only with the lease.

## 3. Verify and report

- `gh pr view <n> --repo vimeo/psalm --json mergeable,mergeStateStatus` shows
  `MERGEABLE` (retry while `UNKNOWN`).
- Remove the scratch worktree.
- Report per PR: clean / resolved (what conflicted, how decided) / needs the user.

## Stop and ask instead of guessing

- The conflict is semantic (both sides changed the same logic differently).
- The PR belongs to a fork you cannot push to (`maintainerCanModify` false).
- Resolving would need a workaround listed under "No workarounds" in AGENTS.md.
- The lease is rejected, or the branch has commits by someone else (a maintainer pushed to it).

After a push, CI restarts: the `routine-pr-ci` skill covers what follows.
