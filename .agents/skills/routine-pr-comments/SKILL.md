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
2. Fix the root cause in a scratch worktree `~/psalm-work/pr-<n>`, mirroring sibling guards
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

## 5. Learn from the comments (standing instruction of the user)

Every maintainer or reviewer comment on any of the user's PRs, handled or not, is feedback on
how the PRs are written. After handling a batch, add each lesson that applies beyond the one
PR to the list below (one line, the rule first, the case in brackets), and drop or merge
entries that turn out wrong or duplicate. Do this without being asked again, also when the
user only pastes comment links. Also check the descriptions of the PRs you touched: no AI
attribution line (`🤖 Generated with ...`, session link), title and text match the final behaviour.

Lessons so far (maintainers `orklah`, `alies-dev`; Nov 2026):

- **The perf report is the reviewers' test suite.** They read its issue diff by (type, file,
  message) for symfony, laravel and doctrine. Before pushing, ask which real code shape a
  change turns into a new diagnostic. A new message on an old issue is also "new" there, so
  compare with master before calling something a regression or not (#12675 `LokaliseProvider`).
- **When a reconciler or check turns something into "impossible", be conservative.** Only
  code Psalm analyses proves a fact: stubs, reflected builtin classes and
  `MethodExistenceProvider` can be wrong at runtime (`method_exists($memcached,
  'setSaslAuthData')`, #12730).
- **Read the whole issue thread for a maintainer's design verdict before designing**, and
  re-read it when a reviewer says the fix contradicts it (weirdan in #9536: `@psalm-type`
  must not capture class templates, so report `InvalidDocblock` with and without a
  namespace, #12734). A fix that makes the error vanish may be the wrong fix.
- **A new atomic type in a union has to be handled where unions are consumed:** an extra
  member kept in a union (the class itself next to its `@psalm-inheritors`) skips branches
  that assumed it was absent (direct hit hides subtype elimination, #12713); an emptied
  union must become `never` explicitly or be pinned by a test (#12718).
- **Compare types by `getId()`, not by location or object identity**, when deciding that two
  checks are redundant (docblock vs native type, #12703).
- **Per-node state in the AST survives re-analysis** (traits are analysed once per using
  class, loops and templates re-run nodes): reset attributes you set at the start of the
  analyzer that owns the node (#12753).
- **Never overwrite a type that two consumers need.** If template inference needs the
  callable form and the containment check needs the original, keep the original (node
  attribute) and fall back to it in the check (#12755). Params, templates and the return type
  of a closure must come from the same method (#13108).
- **A stricter check goes behind a flag and its own issue type, not `return false`.**
  When it flags a common working pattern, use a flag on
  `TypeComparisonResult` and its own issue type, so it does not change overload resolution or
  conditional return types (#13290, orklah and alies-dev).
- **Fix the sibling sites in the same PR when it is cheap** (dynamic-name first-class
  callables had the same bug as the plain ones, #13108); otherwise name the follow-up.
- **Tests:** pin the exact result (`'$a===' => 'list<int>'`, `'$f===' => 'Closure(...)'`,
  `error_message` with `file:line:col`); a case that passes on the base is a guard and the
  comment says so; put it where its siblings live (`ClosureTest` for first-class
  callables); leave out `php_version`/`strict_types` that are not needed; touch no unrelated
  whitespace; test the shapes a reviewer lists (both orders, two classes using one trait,
  `array-key`/`scalar` members, not only the reported one).
- **Say what you could not reproduce.** If a requested edge case shows no observable
  difference (#12753), add the fix and a guard test and write plainly that it is a guard.
- **Compare against the PR's own base.** For a perf-report regression run the pinned project
  on the merge-base commit (or the parent the last merge brought in), never on the main
  checkout, which may be behind master (a dozen unrelated lines drifted in once). Run the
  whole corpus: a file-subset run misses classes that other files declare, and traits are
  analysed through their users. Lines that differ between two runs of the same code (trait
  re-analysis order, the vendor tree) are noise; say so instead of chasing them.
- **A combine or merge of types has to ignore `mixed`.** Joining a new part with an existing
  `mixed` makes everything mixed and cascades into new diagnostics (enum `->value` next to a
  mixed receiver part, #12744); the part that is mixed already has its own diagnostic.
- **Synthetic nodes need a marker.** When the analyzer builds a fake call (the constructor
  call of the property initialization check), a new rule for real calls must be told it
  isn't one, or it silently stops the analysis (`PropertyNotSetInConstructor`, #12767).
- **A template belongs to the type that declares it.** The template of a `class-string-map`
  is the map's, not the call's: don't bind it with the call's (#12818); a method's closure
  takes params, templates and return type from one method (#13108).
- **Narrowing to a generic class keeps its type arguments.** `iterable<K, V> instanceof
  Generator` is `Generator<K, V, ...>`, not an intersection whose unparameterised part
  yields `mixed` in a foreach (#12773); enums are never `Traversable`.
- **Evaluate the iterated expression of a `foreach` once**, before anything is assigned; a
  second assignment must not analyse it again (#12792).
- **Check what you push.** Chain `phpunit && phpcs && self-analysis && git commit && git push`
  with `&&` or inspect each result first: a guard test with a truncated expected message was
  pushed once because the push ran after a failing test. Read the exact emitted message
  (`Failed asserting ... was emitted` prints it) before pinning it.
- **Label what you did not verify.** A reason you inferred (why a line is new, why a dedupe
  happens) goes into the comment as "my reading, not verified", or you verify it first;
  patch comments that stated a guess as a fact (`gh api -X PATCH .../issues/comments/<id>`).
  Never leave a placeholder in a posted comment.
- **A PR that master already contains is superseded.** After merging master, if
  `git diff origin/master -- src` is empty, say so and leave the close/merge decision to the
  maintainers (#12836).
- **Resolve conflicts in big provider files by taking master's file and re-inserting your
  entries** programmatically (extract by entry name, insert at the start of the provider),
  then check that the diff to master only adds lines (#12767).
- **Reply format:** English; what was fixed and why, a "**Behaviour change**" list of exactly
  the verified effects, tests named, and what was *not* run. `@mention` after the push.

