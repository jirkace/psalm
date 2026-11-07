---
name: routine-pr-comments
description: Use when running the recurring routine that checks the user's own open vimeo/psalm pull requests for new comments or review threads from Bugbot or from maintainers, or when asked to "check PR comments", "check bugbot", "zkontroluj komentáře v mých PR".
---

# Routine: Bugbot and maintainer comments in my open PRs

Goal: no unresolved bot finding and no unanswered maintainer comment on the user's open
PRs (author `jirkace`). Work in the main session, no subagents.

## 1. Collect unresolved feedback

```bash
gh pr list --repo vimeo/psalm --author @me --state open --limit 100 --json number,title,headRefOid
```

For each PR, review threads (resolved state is only available via GraphQL):

```bash
gh api graphql -f query='
query($n:Int!){repository(owner:"vimeo",name:"psalm"){pullRequest(number:$n){
  reviewThreads(first:100){nodes{id isResolved isOutdated path line
    comments(first:20){nodes{author{login} body createdAt url}}}}
  reviews(first:50){nodes{author{login} state body submittedAt}}
  comments(first:100){nodes{author{login} body createdAt url}}}}}' -F n=<n>
```

Relevant items:
- unresolved threads (`isResolved == false`), including outdated ones;
- bot findings: authors like `cursor` / `cursor[bot]` (Bugbot), `coderabbitai`, other `[bot]`;
- maintainer comments or `CHANGES_REQUESTED` reviews: any human author other than
  `jirkace`, and issue-level comments newer than the user's last reply/commit;
- ignore the user's own comments and pure CI/status bots (codecov, labeler).

Dedupe against the PR head: a finding on an outdated line may already be fixed.

## 2. Handle Bugbot / bot findings (auto-fix)

1. Read the finding and the code at the PR head; verify it is real (reproduce with a
   single test case when it is behavioural).
2. Fix the root cause in a scratch worktree `/tmp/psalm-pr-<n>`, mirroring sibling guards
   and other call sites of the same mistake; add a test case for the finding that fails
   before and passes after.
3. Single test cases only, never suites. Commit, plain `git push` to the fork remote
   (`git_origin_jirkace`), never `origin`, never `--force` (only the rebase procedure may force, with a lease). No `Claude-Session:` trailer.
4. If the finding is wrong, do not change code; say why in the report.
5. After the push, resolve the bot thread (GraphQL `resolveReviewThread`) only when it
   is fixed or clearly a false positive.

## 3. Handle maintainer comments (conservative)

- A request to rebase: do it with the rebase procedure of skill `routine-pr-conflicts`
  (section 2b, includes `--force-with-lease`), even when the PR has no conflict.
- A concrete, unambiguous change request (rename, add a test, fix a typo): implement it,
  push as above, and put a proposed one-line reply in the report.
- Questions, design disagreements, or anything needing a decision: do not act and do not
  reply. Quote the comment with its URL in the report for the user.
- Never post replies, resolve human threads, or request re-review without the user's
  go-ahead; those are outward-facing.

## 4. Report

Per PR: nothing new / bot findings fixed (commit, thread) / bot finding rejected (why) /
maintainer comment: done or needs the user (quote + URL). After pushes, CI restarts:
`routine-pr-ci` covers what follows.
