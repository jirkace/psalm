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

**The remark that repeats most (about 20 of 35 comments):** the fix is right for the reported case but
treats something *conditional* as unconditional, which gives a false negative, and the review asks for a
regression test of that exact case. Before every push, go through this list for the changed code and add a
test for each item that applies:

1. **Skipped path:** the right of `&&`, `||`, `?:`, `??`; a branch that is not taken.
2. **Early exit:** `return`, `break`, `continue`, `throw`, `exit` in a branch (assignments or mutations on
   it don't reach the code after).
3. **Alias or other spelling:** `self`/`static`/`parent`, a named class, a redeclared property, a trait
   alias, a class-string.
4. **Override:** the class (or an outer trait) overrides the method or property being followed.
5. **Invalidation:** a later write, `unset()`, an impure call, a reference, the offsets of the same
   variable.
6. **Variadic, by-reference, first-class callable, trait, nested:** the forms of the construct the fix
   doesn't mention.
7. **The perf corpora:** the second most common remark is a new diagnostic in Symfony, Laravel or Drupal
   (a lost type that became `mixed`); reproduce with a class that *uses* the trait or extends the base,
   not only with the file alone.


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
- **A new diagnostic can be the twin of one the base already has.** Before changing code for a
  "new false positive", look for the sibling construct in the base output (the getter next
  to the one the PR touched, #12818): if the base reports the same issue there, the PR only
  made the types precise enough to report both, and the answer is the comparison, not a
  workaround.
- **An intentional uninitialised state shows in the code.** A typed property the class checks
  with `isset($this->p)` is allowed to stay unset on an early constructor `return`
  (#13085); a `finally` runs on every return through it, so its `$this->` assignments belong
  to the exit states. A token check in a docblock type has to know the grammar around the
  token: `array{T: int}` has a key `T`, not a template reference (#12734).
- **Trait before parent.** Populating a class imports traits before the parent, so anything
  that stands in for a missing member (the empty constructor) hides the trait's member and
  must be removed before the trait import (#13084).
- **Before "can't reproduce", vary the edit, not only the snippet.** A reply that said the
  language server shows no stale diagnostics was wrong because the test replaced a line
  instead of deleting it (#10689); the reporter, a maintainer and a reviewer had it right.
  Use the recipe in skill `psalm-repro` (section on the language server). A comment that
  only repeats a generic request ("share your version") is noise; and a comment written
  by the assistant must say what it ran and what it did not cover.
- **A check on a type has to look through template params.** `Atomic::isIterable()` (and
  similar `is*()` helpers) is false for a `TTemplateParam` whatever its bound: replace it by
  the atomics of its bound, recursively, and let an unbounded template (`as mixed`) pass
  like `mixed` does (#13127).
- **Control flow that leaves more than one level is recorded on the innermost scope only.**
  `break 2`/`continue 2` in a nested loop or switch is on the nested loop scope, so a guard
  that reads `loop_scope->final_actions` misses it; scan the AST with a depth counter
  (closures and classes excluded) (#13205).
- **A heuristic exemption has to be narrow enough to name its evidence.** "The class calls
  `isset($this->p)` somewhere" hid genuine uninitialised properties; "every read outside
  the constructor is under an `isset` of it, and the property is private" is checkable and
  was accepted in spirit (#13085). Say what the exemption does not cover (traits).
- **Do not add a negation on top of one that already exists.** The polarity flag passed down
  a reconciliation and an explicit `getNegation()` of the assertions did the same job; doing
  both gave `$a: true` where the condition is false (#13124). When you introduce an explicit
  negation, find the flag or the second pass that used to stand in for it.
- **"Narrower on master" can be unsound.** Before restoring a type that was narrower on the
  base, run the reduced shape on the base: master's `bool|resource` came from applying an
  assertion with the wrong polarity (`$x: null` in the else of `null === $x`), losing the
  other members. Show it in the reply instead of reintroducing the mistake.
- **A union with `mixed` is `mixed`.** A new message for the same location (`mixed|array`
  instead of `mixed`) is a new issue in the perf diff; collapse it (#13177).
- **A finding that is real in the real project needs its baseline.** A true positive in the
  `test-with-real-projects` project (PHPUnit) turns that CI check red until the baseline in
  `psalm/endtoend-test-phpunit` is updated; say so in the PR and offer the baseline PR
  (#13085, as for #13229).
- **Find members by the effective method, not by name.** Code that scans class and trait
  statements for a method name must analyze only what PHP calls: resolve the *called* name (it
  may be an alias) with `getDeclaringMethodId()` for the analysed class, skip a trait method the
  class or an outer trait overrides, and read maps such as `trait_visibility_map` from the class
  the alias appears in. (#12673 nested traits, #13319 aliases: both false negatives of one loop)
- **A variadic parameter stands for every parameter it covers.** Compare each covered parameter
  with it (type, by-reference), never skip it; its name is exempt. (#13095)
- **Don't widen an invalid input to hide it.** Mapping what PHP rejects (an object or array as
  an `array_column` index) to `array-key` silences a real `TypeError`: report it and leave the
  invalid part out of the type. (#13313)
- **Compare type arguments with the comparator, not containment.** `GenericTypeComparator`
  respects the declared variance; `isContainedBy` per argument treats every template as
  covariant. (#12955)
- **Separating two expressions needs alias-aware invalidation.** If `static::$x` and `self::$x`
  stop sharing an id, a write through one must invalidate what is known of the other. (#13364)
- **A fix that drops an assignment's type must keep it where the condition is true**, also in
  nested `&&`/`||`; add a regression test with the nesting. (#13361)
- **`self` in a closure is its scope, not the caller's class.** Test it across classes (a
  closure from a trait used by A, called from B). (#13301)
- **`Fixes #N` only when the diff fixes that issue's symptom**; read the issue again before
  keeping the keyword. (#13313)
- **Negative tests assert the message**, not just the issue name (`expects Walker`). (#13301)
- **Run the sibling cases of the area, one by one, before pushing an analyzer change.** Two of
  my own pushes were red in CI only because an existing case of the same test file broke
  (`assignmentInBranchOfOr`, a `strtolower(null)` in a plugin analysed with the project). Script
  it: take the case names with the shared prefix from the provider, run each with `--filter
  '@name'`, and print only the failures; also run self-analysis for `examples/` code.
- **Narrow, never widen, what an earlier pass already set.** When a second pass overrides the
  type of a variable in a context (the body of an `if`), apply it only if it is contained by
  the current type, or it erases the narrowing the first pass made (`instanceof` on an assigned
  variable). Keep one state per assignment node, not per variable. (#13361)
- **A fallback must be conservative.** Code that models some expression kinds and falls back
  for the rest (a visitor that collects assignments, a state walker) must treat what it cannot
  model as "may not run", not "runs". Wrap the PR's own regression case in `== true`, a cast,
  `match`, `??`, `?->` and run it. (#13361)
- **Collect a fact where the path is known, not where the fact is created.** Gathering
  assignments at the assignment also gathered those of branches that later `return` (#13122);
  collecting them at the `throw`, from the context of that path, is exact. Likewise a context
  entry (`$foo->bar`) exists after a plain read or after an `if`, so it is no proof that a
  condition held: store the narrowing together with what it narrowed from (#13409).
- **Invalidation events to test for every stored narrowing:** `unset`, assignment of the
  member, reassignment of the parent, end of the branch, early return, a call that forgets
  properties (`rememberPropertyAssignmentsAfterCall="false"`; by default Psalm remembers).
- **Before pushing, diff the test file against master and read every removed or moved line.**
  Five reviews of one batch complained about the same churn (`nonFinalClassCanBeIdenticalToIterable`
  and other neighbours moved or re-added): my conflict-resolution and "keep both" scripts reorder
  provider entries. `git diff origin/master -- tests | grep '^-'` must show only what the PR
  meant to change.
- **Every new case must fail on master, and a valid case needs real assertions.** Reviewers ran
  each case on master: cases that pass there "pin nothing" (`'assertions' => []`, a case on a
  shape another PR already fixed, an `is empty` case after the semantics changed). Run each new case
  against master's `src` and drop or rewrite those that pass; keep a passing one only as a named guard.
- **Fix the other entry points of the same root cause, then list the limits.** A review lists
  siblings that still fail: `elseif` next to `if` and ternary, the string form next to the array
  form, `canBeIdentical` next to `canExpressionTypesBeIdentical`, `switch`/`foreach` next to the
  ternary, `&&` next to `||`, `callable(int &...$i)` next to `callable(int &$i)`. Grep for every
  caller of the changed helper and every analyzer that builds the same context; what stays
  unfixed goes into a test that pins the limit.
- **A cleared or dropped clause is sound only for a single clause.** Dropping whole clauses of
  the left of `||` is wrong when the left is a conjunction; collecting assigned variables must
  skip closure and arrow-function bodies. Test the CNF with `(A && B) || C` shapes.
- **A flag set for one operand must not be stored in the scope.** Making a type possibly
  undefined for an `isset()` operand leaked into `vars_in_scope` and changed later reads (#13286);
  a rebuilt body type changed the type after a plain `if ($x = f())` (#13361). Probe: use the
  variable after the construct, with no `&&`/`||`.
- **Descriptions go stale and `Fixes #N` must be true.** Re-read the description against the
  final behaviour (`never` vs `empty`), add `Fixes` for every issue the rule really fixes, and use
  `Related to` when only part of an old issue is fixed. Close a PR when master fixed it, with the
  three cases run against master as evidence (#13204).
- **Hot analyzers:** no allocation per iteration (`Type::getNonFalsyString()` in a loop, a
  `PrettyPrinter` per assignment, `clone $context` when nothing was assigned); hoist or guard.
  A message that embeds printed code must be one line.
- **A reused worktree is stale until reset.** `git worktree add` fails when `psalm-work/c<n>`
  exists, and the old checkout can be behind the fork branch (orklah merges master into PR
  branches; my push of #13433 was rejected). Always `git fetch git_origin_jirkace <branch>`, then
  `git checkout -B <branch> git_origin_jirkace/<branch>` (not `FETCH_HEAD`, which is per
  worktree) and check `git log HEAD..git_origin_jirkace/<branch>` before editing. If a push is
  rejected after the comment was posted, merge the maintainer's commit, push, and correct the
  comment.
- **Verify a claim in a posted comment before posting it.** A guessed issue name in a comment
  (`Risky...` instead of `PossiblyUndefinedMethod`) had to be patched; run the snippet first.
- **Watching CI:** a background script that polls `gh pr checks` for the open PRs and exits
  when nothing is pending (notified on exit) saves foreground waiting.
- **Reply format:** English; what was fixed and why, a "**Behaviour change**" list of exactly
  the verified effects, tests named, and what was *not* run. `@mention` after the push.

