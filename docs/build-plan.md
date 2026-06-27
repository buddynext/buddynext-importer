# Build plan - v1: BuddyPress + BuddyBoss

v1 ships **BuddyPress and BuddyBoss** only. FluentCommunity, PeepSo, and Ultimate Member are v2+ (their data-mapping docs come when we build them). BuddyBoss is a BuddyPress superset, so the BuddyBoss adapter extends the BuddyPress one (`isBuddyBoss()`-gated deltas). Mappings: [buddypress.md](data-mapping/buddypress.md), [buddyboss.md](data-mapping/buddyboss.md).

## Architecture (read-side adapter -> common shape -> BuddyNext services)

```
Source DB (bp_* / bb_* / wp_posts)
   |  Source\SourceAdapter (read-only, per platform)
   v
common record DTOs  ->  Writer\* (BuddyNext services)  ->  bn_* tables
        ^                      ^
     Pipeline\IdMap        Pipeline\ImportMode (side-effects off)
```

### The pieces

- **`Source\SourceAdapter`** (interface) - read-only methods the pipeline calls: `stats()`, `profileFields()`, `profileValues($userId)`, `groups($afterId, $limit)`, `groupMembers($afterId, $limit)`, `activities($afterId, $limit)`, `activityComments($activityId)`, `activityMedia($activityId)`, `friendships($afterId, $limit)`. Keyset-paginated (`$afterId`), never OFFSET.
  - `Source\BuddyPress\BuddyPressAdapter` - the base.
  - `Source\BuddyBoss\BuddyBossAdapter extends BuddyPressAdapter` - overrides only the deltas (3 field types, `bp_media`/`bp_video` activity media, forums).
- **`Pipeline\ImportMode`** - a request-scoped flag plus listener guards: while on, no notifications, emails, webhooks, realtime, or per-row recounts fire for imported content. Bridges/listeners check `ImportMode::isOn()` and early-return.
- **`Pipeline\IdMap`** - one table `bni_id_map(source, domain, source_id, bn_id)`. Idempotency + resume + relationship resolution (a comment finds its parent's `bn_id`, a group post finds its space).
- **`Writer\*`** - thin writers that call BuddyNext services, never raw SQL: `ProfileWriter` (ProfileService), `SpaceWriter` (SpaceService/SpaceMemberService), `ActivityWriter` (PostService + comments), `MediaWriter` (MediaClient/WPMediaVerse), `ForumWriter` (Jetonomy, gated).
- **`Pipeline\Importer`** - orchestrates the phases in dependency order, resumable from the IdMap.
- **`CLI\MigrateCommand`** - `wp buddynext-import run --source=buddypress|buddyboss`, plus per-phase subcommands for re-runs. WP-CLI, keyset-batched, recursive (the pattern FluentCommunity's migrator uses).

## Build order (each phase verified end-to-end on reign-release before the next)

1. **Foundation** - `ImportMode` + `IdMap` (table + read/write) + the CLI skeleton + `stats()`. No data moved yet; prove the toggle + map.
2. **Profiles** - xprofile field defs + values. The easiest domain, de-risks the pipeline (straight type map; only multi-value `maybe_unserialize`). Verify a member's fields land in BuddyNext.
3. **Groups -> spaces** - groups + members (privacy + role + pending). Verify a space + its roster.
4. **Activity -> posts + comments + media** - import `activity_update` only; skip system rows; rebuild comment threads; attach `bp_media`/`bp_video` via MediaVerse (the shared media step). Verify a post with photos.
5. **Friends -> connections** - mutual; pending preserved.
6. **BuddyBoss deltas** - the 3 extra field types; then **forums -> Jetonomy** (only when Jetonomy is active).
7. **Finishing** - one bulk recount pass; summary report.

## Verification harness

reign-release.local is the BuddyPress fixture (real demo data: 26 users, 250 xprofile values, 45 groups, 129 real activities, 91 friendships). Each phase is run there, then the result is checked in BuddyNext (counts + spot-checks). A BuddyBoss fixture (buddyboss.local) covers the deltas.

## Non-negotiables (carried from BuddyNext core)

- Write through BuddyNext services, never raw SQL into `bn_*`.
- Side-effects OFF during import (`ImportMode`); one bulk recount at the end.
- Resumable + idempotent via the IdMap; keyset pagination, never OFFSET.
- One-time tool: companion-installed, run, then removable.
