# CLAUDE.md — BuddyNext Importer

Engineering guidance for AI agents and contributors working in this repository.
Read before changing anything.

## What this is

A **one-time migration tool**: it moves an existing BuddyPress or BuddyBoss
community into BuddyNext, then gets deactivated and deleted. That framing decides
most of the design arguments in here — it is not a long-lived feature plugin, it is
a staging tool that must be honest about what it moved and leave no residue.

- **Plugin path:** `wp-content/plugins/buddynext-importer/`
- **Namespace:** `BuddyNextImporter\*` (PSR-4 → `includes/`, hand-written autoloader)
- **REST namespace:** `buddynext-importer/v1`
- **CLI:** `wp buddynext-import <subcommand>`
- **Boot:** `plugins_loaded:20` → `BuddyNextImporter\Plugin::boot()`
- **Admin page:** Tools → Import to BuddyNext (`?page=buddynext-importer`)

### It depends on BuddyNext, and that guarantees more than it looks

**Every record is written through BuddyNext's own services** — never by INSERTing
into `bn_*` tables. `Plugin::buddynext_active()` gates the write paths; read-only
source inspection (`stats`) works without it.

Two consequences worth knowing before you write a guard:

1. **Action Scheduler is always available.** BuddyNext Free bundles it
   (`buddynext/libs/action-scheduler`) and `require`s it at *file* load, before
   `plugins_loaded`. Verified: AS 4.0.0 resolves from BN's libs, and both REST
   entry points that can start work (`/background`, `/step`) refuse unless BN is
   active. So `function_exists( 'as_enqueue_async_action' )` is true wherever this
   plugin can legitimately do anything. `BackgroundImport` keeps a WP-Cron fallback
   as *defence against a structurally broken BN build* — that is deliberate and
   documented in the class, not an oversight to "finish".
2. **Content conventions are BuddyNext's, not the source's.** See "Writing content
   the way BuddyNext stores it" below. Getting this wrong produces output that
   looks fine and is subtly wrong, which is the worst failure mode here.

---

## Where things live

| What | Where |
|---|---|
| Boot + dependency gate | `includes/Plugin.php` |
| Source readers (one per platform) | `includes/Source/{BuddyPress,BuddyBoss}/` + `SourceAdapter.php` |
| Per-domain orchestration (batch loops) | `includes/Pipeline/*Importer.php` |
| **The step list — single source of truth** | `includes/Pipeline/StepRegistry.php` |
| Writers (call BuddyNext services) | `includes/Writer/` |
| Working tables | `includes/Pipeline/IdMap.php`, `Checkpoint.php` |
| Unattended runner | `includes/Background/BackgroundImport.php` |
| REST | `includes/Rest/ProgressController.php` |
| CLI | `includes/CLI/MigrateCommand.php` |
| Post-migration checking | `includes/Verify/VerifyService.php` |
| Admin screen | `includes/Admin/ImporterPage.php` + `templates/admin/importer-page.php` |
| Disposable source fixture | `docker/` |

---

## The four rules that carry this codebase

### 1. `StepRegistry` is the single source of truth for what a migration contains

Four surfaces run or report a migration: the CLI (`migrate-all`), the background
runner, REST `/step`, and the admin page's in-browser loop. They each used to carry
their own copy of the step list, and the copies drifted — **the browser loop knew 5
of 16 domains and `/step` knew 13**, so "Start import" silently skipped member
types, follows, reactions, forums, images, media and messages while reporting
success. A site owner got a half-migrated community and no warning.

A step declares its identity (phase + stage + label + checkpoint domain) **and** how
it runs. Adding a domain is **one entry in the registry**, never four edits kept in
sync by hand. If you find yourself writing a phase name in a second place, stop.

`client_steps()` filters by each step's `available()` callable (target engine +
source reader both present), so a site with no source correctly gets zero steps and
the button says so. That is not the drift bug returning.

### 2. Idempotency lives in the id-map; resume lives in the checkpoint

- **`bni_id_map`** — source id → target id, per (source, domain). Makes every write
  idempotent and resolves relationships (a topic finds its forum through it). **Note
  the table name: `bni_id_map`, with underscores.** Guessing `bni_idmap` gets you a
  silently-failing query and a wrong conclusion.
- **`bni_checkpoint`** — highest fully-processed source id per (source, domain), so a
  resumed run is O(remaining) rather than re-walking from 0.

Correctness stays in the id-map; the cursor only skips rows already mapped. A stale
cursor costs a re-scan, never a lost row.

`wp buddynext-import cleanup` (and the admin "Remove import data" button) drops both.
It is deliberate, confirmed and final: afterwards there is no duplicate protection,
so a later run re-creates everything from scratch. Never make it automatic, and
never allow it while a job is running — the checkpoint it deletes is exactly what
the next tick reads.

### 3. A silent shortfall is the worst bug this tool can have

The operator deletes the old community believing everything moved. So:

- Every domain reports **source-vs-written**, plus a **reason-coded breakdown** of
  everything it did not write. Never "N imported" alone.
- A skip reason is either a **note** (expected, not loss) or it feeds the
  **shortfall warning**. See `MigrateCommand::report_skips()` for the note list —
  `already_imported`, `self_follow`, `blocked`, `activity_not_imported`,
  `linked_from_activity`, etc. Misfiling a reason either cries wolf on a clean
  migration or hides a real loss.
- **Never report success for a write that did not happen.** If a service returns
  false or a `WP_Error`, propagate it. Do not mark the id-map for a row that was
  refused — that poisons the retry and makes the loss unrecoverable.
- **A source-count predicate must match its fetch query.** If `stats()` and the
  batch `WHERE` disagree, the report invents a shortfall (or hides one) on a
  migration that is fine.

### 4. History is replayed; today's preferences do not veto it

`ImportMode` neutralises, for the duration of a run and through BN's own public
filters, everything that would judge replayed history by today's settings:

- **Preference gates** — `buddynext_can_follow`, `buddynext_can_connect`,
  `buddynext_max_following` (the follow cap). A relationship that genuinely existed
  at the source must not be dropped because the member's *current* setting is
  restrictive; the preference governs future actions, not the replay of history.
  (Owner decision, 2026-07-27.)
- **Notification sends** — `buddynext_notification_should_send`,
  `jetonomy_notification_should_send`. Re-creating years of activity must not
  notify anybody. If you add a domain that can notify, check it routes through a
  path this actually suppresses.
- **WPMediaVerse DM limits** — `mvs_dm_convo_rate_limit`,
  `mvs_dm_message_rate_limit`, `mvs_message_max_length`. Rate limits exist to stop
  live abuse; a bulk history replay trips them by definition.

**A real block still refuses.** `PrivacyService` checks `bn_blocks` *before* those
filters, so lifting a preference can never re-create a relationship across a block.
Blocked rows are reason-coded, never silent.

---

## Writing content the way BuddyNext stores it

BuddyNext stores post/comment bodies as **plain text** and renders them escaped,
then linkifies at render time via `buddynext_format_content()`. So:

- **Raw source HTML does not render as markup — it renders as itself.** A comment
  imported with `<span class="atwho-inserted">…` showed the member that literal
  string. Always go through `ActivityWriter::clean_content()`; both post and comment
  paths do now.
- **Mentions are plain `@handle`**, linkified by `buddynext_format_content()` using
  `PageRouter::member_handle()` (which is `bn_profile_slug ?: user_nicename`, never
  `user_login`). So an imported mention must arrive as *this site's* handle.
  BuddyBoss stores the target as `{{mention_user_id_N}}` in the href with its own
  display handle as the anchor text — **the id is authoritative, the anchor text is
  not.** Trusting the text produced a confident link to a profile that did not exist
  (`@admin-guy` where the real handle was `varundubey`), which survives review far
  longer than visibly broken markup. `ActivityWriter::rewrite_mentions()` handles it.
- **Media ingestion is idempotent by source attachment id.** `MediaIngest::ingest()`
  returns the already-created media id, so a row reachable by two domains does not
  duplicate. Use `MediaIngest::existing()` when you need to tell "already written by
  another pass" from "written here" — the counts depend on that distinction.

---

## Source adapters

`SourceAdapter` is a ~33-method read-only contract over one source platform. Rules:

- **Adapters read, writers write.** An adapter never touches a `bn_*` or partner
  table, and a writer never queries the source.
- **Keyset pagination, always.** `( $after, $limit )` with `WHERE id > %d ORDER BY id
  ASC LIMIT %d`. No `OFFSET` — this tool targets large communities.
- **Guard every table** with `table_exists()`; a source may not have the feature.
- **Batch methods over per-row ones.** `activity_media_for( array $ids )` exists
  because the per-row version was an N+1 at 100k activities. The base class can
  default a batch method to a loop, but a real adapter should override it with one
  `IN (…)` query.
- **BuddyPress core has no media/albums** — `media_albums()` / `standalone_media()`
  return empty there and BuddyBoss overrides them. Do not "fix" that emptiness.

---

## Verification — there is no PHPUnit suite

This is the important gap to know about. Correctness is established by **running a
real migration against a disposable seeded community**, not by unit tests.

```bash
cd docker

./run.sh bp          # blank site + a BuddyPress community (BuddyX active,
                     # BuddyNext INACTIVE — a pure source), via buddypress-playground-cli
./run.sh migrate     # wp buddynext-import migrate-all
./run.sh verify      # coverage + per-domain totals + random object checks
./run.sh reconcile   # source vs destination, counted independently
./run.sh shell       # poke around
./run.sh down        # destroy it

USERS=2000 GROUPS=100 ./run.sh bp    # bigger
./run.sh small | ./run.sh large      # preset scenarios
./run.sh reign | ./run.sh fresh
```

`http://localhost:8080`, admin/admin, or add `?autologin=1` to any URL.

**The fixture is BuddyPress-only — there is no BuddyBoss source fixture, and
BuddyBoss is a paid plugin.** BuddyBoss-specific paths (`BuddyBossAdapter`, album
media, `{{mention_user_id_N}}`) therefore have **no automated coverage**. To work on
them, build the shape you need: create the BuddyBoss-shaped tables (`bp_media`,
`bp_media_albums`, …) with their real column layout, seed the exact scenario, and
drive the adapter/writer directly with `wp eval`. Tear the fixtures down afterwards
— and use the real WP/BuddyNext APIs for anything you seed on the destination side,
because raw SQL creates rows the services themselves would refuse.

### Gates

| Gate | Command |
|---|---|
| PHP lint | `php -l <file>` |
| WPCS | `php ../buddynext/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/` |

There is no `composer.json` here, so borrow BuddyNext's `vendor/bin/phpcs`. Some
pre-existing violations live in `VerifyService`, `SpaceImporter`, `ImporterPage` and
`BuddyPressAdapter` — **check a finding is yours before fixing it**, by comparing
against `git show HEAD:<file>`, and do not renumber someone else's debt into your PR.

Every UI change is browser-verified at **1280 and 390** before it is called done —
including hover/focus states and no horizontal spill.

---

## Frontend conventions (admin screen)

- `assets/js/admin-importer.js` is plain ES5-ish vanilla JS using `apiFetch` with an
  `X-WP-Nonce` header. No jQuery, no build step.
- **No `alert()` / `confirm()`** — the BuddyNext UX standard bans them and a browser
  dialog cannot explain what a destructive action costs. Use an in-page two-step
  confirmation (see the teardown block), and move focus onto it when it opens.
- `.bni-hint` is `display: flex` with a `::before` marker. Multi-line content must be
  wrapped in a single child, or each node becomes its own flex item and the heading
  collapses into a narrow column.
- Colours come from the `--bni-*` ramp on `.bni-wrap`. A destructive surface uses the
  `--bni-danger-*` ramp, not the accent one.
- **Watch source order on modifiers.** `.bni-hint--danger::before` and
  `.bni-hint::before` tie on specificity (one class + one pseudo-element), so the
  modifier must be declared *after* the base or it silently loses. This has already
  been got wrong once, with a measured accent-blue marker on a red panel.

---

## Cards are a SUGGESTION, not a specification

Same rule as BuddyNext, and it keeps earning its place here.

> **Reproduce it first. If you cannot reproduce it, it is not a bug yet.**

On the BI Bugs column, verifying first repeatedly changed the outcome:

- Seven of twelve cards were **already fixed** — the column was mostly stale, not
  backlog.
- One card's stated root cause ("the importer lacks a MediaImporter/MediaWriter
  module") was **false**; those classes existed, along with four media steps.
- One card had an **empty body** and duplicated another.
- The album-media card was **right about the cause but wrong about the remedy** —
  the `activity_id = 0` filter it named is deliberate (it prevents double-import),
  so deleting it would have duplicated media instead of fixing albums.
- The mention card was **wrong in the safe direction**: it said posts were fine.
  They were not; they rendered a confident link to a nonexistent profile, which is
  worse than the broken markup the card reported.

So: read the code the card names, try to **refute** it, reproduce, then fix the
cause. Comment the card with what you actually found — including where it was wrong.
An invalid card closed with proof is a good outcome.

---

## Docs

Customer-facing and internal docs live in `docs/` in this repo. Plans, audits and QA
material for the BuddyNext *plugins* live on the private shelf in
`buddynext-pro/free-internal/` — do not copy importer internals into the public
`buddynext` repo.
