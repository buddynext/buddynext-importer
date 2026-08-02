# Agent smoke runbook - BuddyNext Importer

Executed by the global `/wp-plugin-smoke` skill, which reads
`docs/qa/qa-config.json` and dispatches a sub-agent through the steps below.

**What this runbook proves:** the plugin installs from its release zip, boots
beside BuddyNext, renders its one admin screen, answers its REST surface, and
leaves no fatals. **What it does NOT prove: that a migration is correct.** This
plugin's correctness gate is a real migration against the disposable fixture
(`docker/run.sh bp | migrate | verify | reconcile`) - see CLAUDE.md,
"Verification - there is no PHPUnit suite". A green smoke on a broken importer is
entirely possible, so never read it as migration coverage.

**This plugin has no member-facing surface.** There is nothing to check at 390px
on the front end. The admin screen still gets 1280 and 390, per CLAUDE.md.

---

## Preconditions

| | |
|---|---|
| BuddyNext Free | active, and it must be, or every write path correctly refuses |
| A source community | BuddyPress or BuddyBoss tables present, or the step list is legitimately empty |
| Zip under test | built by `bin/build-release.sh`, installed fresh - never the dev tree |

If BuddyNext is inactive the plugin is *supposed* to degrade: read-only `stats`
works, everything that writes refuses. That is step 6, not a failure.

---

## Steps

### 1. Install from the built zip

```bash
wp plugin install /path/to/buddynext-importer-<version>.zip --activate
wp plugin list --status=active --field=name | grep buddynext
```

**Pass:** both `buddynext` and `buddynext-importer` active, no activation error.

### 2. No fatals on boot

```bash
: > wp-content/debug.log
wp eval 'echo BUDDYNEXT_IMPORTER_VERSION;'
wp cli info > /dev/null
wc -l < wp-content/debug.log
```

**Pass:** the version prints and matches the zip filename; debug.log gains no
`Fatal`, `Uncaught`, `Class ... not found`, or `_load_textdomain_just_in_time`
line attributable to this plugin.

### 3. The admin screen renders

Navigate to `/wp-admin/tools.php?page=buddynext-importer` (add `?autologin=1`
first if needed).

**Pass, and look at it rather than at the status code:**

- The page renders inside `.bni-wrap` with its heading and no PHP notice.
- Source detection states plainly what it found (a BuddyPress/BuddyBoss source,
  or that there is none). "No source" is a correct outcome, not a failure.
- The step list is populated from `StepRegistry::client_steps()`. **A site with a
  source must not show zero steps** - that is the drift bug this plugin exists to
  never repeat (CLAUDE.md rule 1).
- No `alert()` / `confirm()` anywhere; the destructive "Remove import data"
  control is an in-page two-step confirmation.
- Screenshot at **1280 and 390**. No horizontal spill at 390, and the
  `.bni-hint` blocks keep their marker beside the text rather than collapsing
  into a narrow column.

### 4. REST answers, and refuses correctly

```bash
wp eval 'echo wp_json_encode( rest_do_request( new WP_REST_Request( "GET", "/buddynext-importer/v1/progress" ) )->get_data() );'
```

**Pass:** a JSON body, not a 500. Then confirm the two entry points that can
*start* work refuse when they should:

```bash
# As a logged-out request, /background and /step must not start a job.
curl -s -o /dev/null -w '%{http_code}\n' "<site>/wp-json/buddynext-importer/v1/step"
```

**Pass:** 401/403. A 200 here is a release blocker.

### 5. CLI is registered

```bash
wp buddynext-import --help
wp buddynext-import stats
```

**Pass:** the subcommand list prints, and `stats` runs *without* writing
anything. `stats` is the read-only surface and must work even with no source (it
reports zeroes) - it must never fatal.

### 6. Degrade check - BuddyNext deactivated

```bash
wp plugin deactivate buddynext
wp buddynext-import stats          # must still work: read-only
curl -s -o /dev/null -w '%{http_code}\n' "<site>/wp-admin/tools.php?page=buddynext-importer"
wp plugin activate buddynext       # restore
```

**Pass:** `stats` still reports, the admin screen still loads and *says* it needs
BuddyNext rather than white-screening, and no write path is reachable. The site
must stay up.

### 7. Uninstall leaves no residue

This is a one-time tool that gets deleted afterwards, so residue is a product
defect, not housekeeping.

```bash
wp buddynext-import cleanup      # drops bni_id_map + bni_checkpoint
wp db tables --all-tables | grep bni_ || echo "no bni_ tables - correct"
```

**Pass:** both working tables are gone. **Never run `cleanup` while a job is
running** - the checkpoint it deletes is what the next tick reads.

---

## Reporting

Write `docs/qa/.last-smoke-pass.json` per the `/wp-plugin-smoke` report shape.
File any defect against **BI Bugs** (column `10138981830`), not BuddyNext's own
Bugs column.

Per CLAUDE.md, a finding is a *lead*: read the code it names, try to refute it,
and reproduce it before filing. On this plugin's board, seven of twelve cards
were already fixed and one named a root cause that did not exist.
