---
name: routine-pr-conflicts
description: Use when running the recurring routine that checks the user's own open vimeo/psalm pull requests for merge conflicts with master, or when asked to "check my PRs for conflicts", "resolve conflicts on my PRs", "zkontroluj konflikty v PR".
---

# Routine: merge conflicts in my open PRs

Goal: every open PR of the user (author `jirkace`) merges cleanly into `master`.
Work in the main session, no subagents (see AGENTS.md).

## 1. List PRs with a conflict

```bash
gh pr list --repo vimeo/psalm --author @me --state open --limit 100 \
  --json number,title,headRefName,headRefOid,mergeable,mergeStateStatus,isDraft,isCrossRepository
```

`mergeable` is computed lazily: `UNKNOWN` means GitHub has not finished, so re-query
after ~10 s (up to 3 times). Conflicting = `mergeable == "CONFLICTING"` /
`mergeStateStatus == "DIRTY"`. Skip everything else (`BEHIND` alone is not a conflict).

## 2. Resolve each conflicting PR

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
   with a plain `git push` (never `--force`, never to `origin`).

## 3. Verify and report

- `gh pr view <n> --repo vimeo/psalm --json mergeable,mergeStateStatus` shows
  `MERGEABLE` (retry while `UNKNOWN`).
- Remove the scratch worktree.
- Report per PR: clean / resolved (what conflicted, how decided) / needs the user.

## Stop and ask instead of guessing

- The conflict is semantic (both sides changed the same logic differently).
- The PR belongs to a fork you cannot push to (`maintainerCanModify` false).
- Resolving would need a workaround listed under "No workarounds" in AGENTS.md.

After a push, CI restarts: the `routine-pr-ci` skill covers what follows.
