---
name: routine-pr-ci
description: Use when running the recurring routine that checks whether CI is failing on the user's own open vimeo/psalm pull requests and fixes it, or when asked to "check CI on my PRs", "fix failing checks", "zkontroluj CI v mých PR".
---

# Routine: failing CI in my open PRs

Goal: every open PR of the user (author `jirkace`) has green required checks.
Work in the main session, no subagents. Never run the PHPUnit suite locally: CI is the
validator; reproduce only single test cases (AGENTS.md "Validating changes").

## 1. Find failing PRs

```bash
gh pr list --repo vimeo/psalm --author @me --state open --limit 100 --json number,headRefOid,isDraft
gh pr checks <n> --repo vimeo/psalm
```

Failing = any check with `fail`. `pending` is not a failure; leave it for the next run.
For run lookups use `gh run list --repo vimeo/psalm --commit <sha>` (`--branch` can be
stale). Make sure the run belongs to the PR's current head sha.

## 2. Diagnose each failure from the log

```bash
gh run view <run-id> --repo vimeo/psalm --log-failed | head -200
```

Classify before touching code:

| Symptom | Action |
|---|---|
| Merge conflict / PR not mergeable | skill `routine-pr-conflicts` first |
| Test failure caused by the PR | reproduce that single case locally on branch, fix the root cause, add tests for edge cases (AGENTS.md "Fixing bugs") |
| Test failure also on `master` (flaky or broken base) | confirm with the same case on master; report, do not "fix" in the PR |
| `label` check | add a `release:*` label (`gh pr edit <n> --add-label release:fix`) |
| "Check backward compatibility" | skill `plugin-api-bc` |
| Self-analysis / Psalm issues | one full `flock /tmp/psalm-self-analysis.lock timeout 300 ./psalm --no-cache --output-format=compact`, diff against master; no suppressions/baseline edits |
| phpcs / code style | fix per `phpcs` on the changed files, check exit code |
| Windows-only failure | tests must not hardcode `/` in paths; use `DIRECTORY_SEPARATOR` |
| Infra error (network, runner shutdown, rate limit) | `gh run rerun <run-id> --failed` once; if it repeats, report |

## 3. Fix, push, re-check

1. Work in a scratch worktree `/tmp/psalm-pr-<n>` on the PR branch (own `vendor/` copy).
2. Fix the root cause. No workarounds from AGENTS.md "No workarounds" (no suppress, no
   baseline, no fixture changes to dodge behaviour). If only a workaround is possible,
   stop and report.
3. Prove with single test cases (fails before, passes after if it is the PR's own test).
4. Commit, plain `git push` to the fork remote (`git_origin_jirkace`); never `origin`,
   never `--force` (a maintainer-requested rebase is handled by `routine-pr-conflicts`). No `Claude-Session:` trailer in commits.
5. Poll `gh pr checks <n>` until the new run finishes, or report it as pending.

## 4. Report

Per PR: green / pending / fixed (cause, commit) / failing on master too / needs the user.
