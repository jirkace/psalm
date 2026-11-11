---
name: routine-pr-conflicts
description: Use when running the recurring routine that checks the user's own open vimeo/psalm pull requests for merge conflicts with master or for a maintainer's request to rebase, or when asked to "check my PRs for conflicts", "resolve conflicts on my PRs", "rebase my PR", "zkontroluj konflikty v PR".
---

# Routine: merge conflicts in my open PRs

Goal: every open PR of the user (author `jirkace`) merges cleanly into `master`, and every
rebase a maintainer asked for is done.
Work in the main session, no subagents (see AGENTS.md).

## 1. List PRs with a conflict

**List limit:** the user has 300+ open PRs and `gh pr list` silently stops at `--limit`
(a run with `--limit 200` handled 200 of 323). Use `--limit 500` and compare the number of rows
with `gh api 'search/issues?q=repo:vimeo/psalm+is:pr+is:open+author:jirkace&per_page=1' --jq .total_count`;
if they differ, raise the limit before acting. Say the total in the report.

```bash
gh pr list --repo vimeo/psalm --author @me --state open --limit 500 \
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
2. Create a scratch worktree under `~/psalm-work/pr-<n>` on the PR branch. It needs its own
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

1. Scratch worktree `~/psalm-work/pr-<n>`; `git fetch origin master` and fetch the PR branch
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
   updates after a few seconds).
6. **Only after the push succeeded**, post a PR comment that mentions the maintainer who
   asked (a real `@login`, e.g. `@orklah`) and says the PR is rebased onto master, with the
   new head sha and what conflicted (one or two sentences). Never before the push, and
   never claim a push that was rejected. Do not resolve the maintainer's comment thread.

The user's standing instruction: whenever a maintainer asks for a rebase, always do it,
force-push (with the lease) and comment as in step 6, without asking again.

Force-pushing is allowed only here, only to the user's fork branch, only with the lease.

## 2c. Merging master into many PRs at once (clean merges only)

When a master fix has to reach many PRs (a baseline repair, say), skip worktrees: test with
`git merge-tree --write-tree origin/master <head>` (exit 0 = clean, otherwise leave the PR for
step 2), build the merge with `git commit-tree <tree> -p <head> -p origin/master -m "Merge master
into <branch>" ` (trailer `Co-Authored-By` only) and push it as a plain fast-forward
`git push git_origin_jirkace <sha>:refs/heads/<branch>`. Skip PRs whose head already contains the
fix (`git merge-base --is-ancestor <fix> <head>`). The pushes are slow (one per branch): run the
script in the background and start the CI watch only after it has finished.

## 3. Verify and report

- `gh pr view <n> --repo vimeo/psalm --json mergeable,mergeStateStatus` shows
  `MERGEABLE` (retry while `UNKNOWN`).
- Remove the scratch worktree.
- Report per PR: clean / resolved (what conflicted, how decided) / needs the user.

## 2c. Superseded by master: comment and close, automatically

Check this **before** resolving a conflict that touches source files: maintainers merge parallel fixes, and a
conflicting PR is often already fixed on master.

1. Update a scratch worktree of `origin/master` (own `vendor/`).
2. Copy the PR's test files over it and run each case the PR **added** (one case at a time, by `--filter
   '@caseName'`), or run the PR's snippets as a scratch project when it has no provider case.
3. Run the same procedure for a PR that is **not** on master as a control: its cases must fail, or the harness
   proves nothing.
4. The PR is superseded when every case it adds passes against master's `src` (an invalid-code case that no longer
   reports means the issue is gone as well). A case that fails only because master changed an expectation (e.g. a
   type that is now narrower) is not superseded: merge and update the expectation.
5. Superseded: post one English comment ("**Superseded by master**": what conflicts, which cases or snippets were
   run, against which master commit, what was not run), then **close the PR yourself** (`gh pr close`). The user
   authorised this for superseded PRs, in every run. Do not delete the fork branch. Skip PRs a maintainer already
   closed.
6. Not superseded but two PRs implement the same thing differently, or master took the opposite design decision
   (e.g. it skips what the PR evaluates): do not merge and do not close; comment a concrete question for the
   maintainers and list the PR in the report.

Never close a PR that adds behaviour master lacks, and never close one because it is old or red.

## Pitfalls learned resolving many conflicts

- **Provider conflicts:** after "keep both", check duplicate keys per provider and drop the copy
  that master already has (`DuplicateArrayKey` turned the `build` job red in ten PRs); hunks
  where git factored a shared tail out (`'php_version' => ...,` then `],`) need the tail
  between the two entries, not a second `],`. Verify every entry of both parents is still there,
  verbatim.
- **`git rebase` of a branch with merge commits** replays history and conflicts almost always;
  it works when the branch has one real commit (resolve, `rebase --continue`). Otherwise merge
  master instead and say so.
- **A merge can break master's tests:** run a few cases master added in the same file, not only
  the PR's own (a narrower inferred type changes master's expectation: update it when the new
  type is the correct one).
- **ComplexMethod after a merge:** extract the biggest repeated block (both branches of an
  if/else), not the newly added one; small extractions add edges (arguments) and raise the size.
  Prefer folding the new check into an existing helper. Never raise the limit or suppress.

## Stop and ask instead of guessing

- The conflict is semantic (both sides changed the same logic differently).
- The PR belongs to a fork you cannot push to (`maintainerCanModify` false).
- Resolving would need a workaround listed under "No workarounds" in AGENTS.md.
- The lease is rejected, or the branch has commits by someone else (a maintainer pushed to it).

After a push, CI restarts: the `routine-pr-ci` skill covers what follows.
