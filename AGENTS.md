# Working on Openbook

Follow the existing project conventions in `CONTRIBUTING.md` and the relevant
architecture and development guides in `docs/`. Keep changes focused and avoid
unrelated refactors.

## Development

- Openbook is a PHP 8.2+ / Laravel application with ActivityPub federation.
  Preserve its ability to run on ordinary shared hosting: do not introduce a
  mandatory long-running worker, Redis, or external service for a core feature.
- Keep controllers thin. Put domain and federation behavior in the appropriate
  existing services, and reuse established flows before adding parallel ones.
- Check authorization, visibility, and federation boundaries when changing
  posts, actors, events, inbox processing, or delivery. Do not expose private
  content through a new route, query, or ActivityPub representation.
- When changing migrations or nontrivial queries, account for both MySQL/MariaDB
  and the SQLite test suite. For MySQL queries that scan, filter, join, sort,
  or paginate nontrivial data sets, verify the indexes used by the actual query
  plan (for example with `EXPLAIN`), including each branch of a `UNION ALL`.
  Add or adjust indexes when justified by the access pattern; do not assume
  that an index merely existing means MySQL uses it. Passing SQLite tests does
  not prove a MySQL migration or query is sound.
- Add or update focused PHPUnit tests for behavior changes. Run the relevant
  tests during development and the full suite before proposing a pull request;
  report any tests that could not be run or that fail for unrelated reasons.

## Analysis and documentation

- For a major feature or architectural change, use `analysis/` to record the
  problem, constraints, decisions, open questions, and implementation stages
  before substantial coding. Keep the relevant analysis current as decisions
  change; small, targeted fixes do not need a new analysis document.
- Before changing `CHANGELOG.md`, read and follow
  `.cursor/rules/changelog.mdc`. Update the changelog in the same work session
  for noteworthy user- or operator-facing changes, following that rule's scope
  and version conventions. Do not add release notes for purely internal work.
- Update the relevant pages in `docs/` when behavior, configuration, deployment,
  or federation expectations change. Keep English and Italian counterparts in
  sync where both exist; update the README when its high-level or installation
  guidance changes, without duplicating release details there.

## Before committing

- Review the complete diff with fresh eyes, not only the lines just edited.
  Look for correctness, regressions, security and privacy issues, unnecessary
  complexity, duplicated logic, and accidental unrelated changes. Fix findings
  before committing, or clearly report unresolved risks.
- Confirm the appropriate tests and checks have run, the changelog is current
  where required, and affected `docs/` pages are updated where necessary.
- Stage only files belonging to the task. Use a descriptive commit message that
  explains the change; include an issue reference when the work addresses one.
