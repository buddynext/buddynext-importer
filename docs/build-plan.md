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

1. **Foundation** - DONE. `ImportMode` + `IdMap` (table + read/write) + the CLI skeleton + `stats()`. No data moved; toggle + map proven.
2. **Profiles** - DONE. xprofile groups + field defs (+ options) + member values, written only through `buddynext_service( 'profiles' )` (`create_group`/`create_field`/`save_profile`). `FieldTypeMap` (BP core + BuddyBoss + BPXCFT), multi-value `maybe_unserialize` -> option-slug match, datebox reformat. Both surfaces: `wp buddynext-import migrate-profiles` + REST `POST /step`. Verified end-to-end on buddynext.local against reign-release data (3 groups, 10 fields, 25 members, 250 values; correct types + option slugs), then cleaned up.
3. **Groups -> spaces** - DONE. groups -> spaces via `buddynext_service( 'spaces' )` (`create`, status public/private/hidden -> open/private/secret, parent -> sub-space) + members via `space_members` (`join` = active, `change_role` -> moderator for group admins/mods, `ban_from_space` for bans, `request_join` for pending). Keyset groups + inner keyset members. CLI `migrate-spaces` + REST `/step` phase `spaces`. Verified end-to-end on buddynext.local: 45 spaces (open=33, private=5, secret=7), 368 members incl. owner/moderator/banned branches exercised, then cleaned up.
4. **Activity -> posts + comments** - DONE. `activity_update` only (system rows + spam skipped) -> `buddynext_service( 'post_service' )->create` with backdated `created_at` (PostService is importer-aware) and `space_id` resolved from the group via the id-map; comments -> `buddynext_service( 'comments' )->create` on the mapped post, replies nested via `secondary_item_id`. CLI `migrate-activity` (posts then comments) + REST `/step` phase `activity` (stages posts/comments). Verified end-to-end: 129 posts (75 sitewide + 54 in-space, correct split), 129/129 timestamps preserved, 0 notifications (suppression), then cleaned up. Demo had 0 comments so that path is built but unexercised. **Activity media (BuddyBoss `bp_media`/`bp_video` -> MediaVerse) is the follow-on** - needs the MediaClient seam + a BuddyBoss fixture.
5. **Friends -> connections** - mutual; pending preserved.
6. **BuddyBoss deltas** - the 3 extra field types; then **forums -> Jetonomy** (only when Jetonomy is active).
7. **Finishing** - one bulk recount pass; summary report.

## Verification harness

reign-release.local is the BuddyPress fixture (real demo data: 26 users, 250 xprofile values, 45 groups, 129 real activities, 91 friendships). Each phase is run there, then the result is checked in BuddyNext (counts + spot-checks). A BuddyBoss fixture (buddyboss.local) covers the deltas.

## Non-negotiables (carried from BuddyNext core)

- Write through BuddyNext services, never raw SQL into `bn_*`.
- Side-effects OFF during import. `ImportMode::register()` hooks BuddyNext's own `buddynext_notification_should_send` veto filter -> false while active, so `NotificationService::create()` returns 0 and the in-app notification, its email (EmailDispatchListener), and Pro realtime push (both hang off `buddynext_notification_created`) never fire. Search index, hashtags, and cache busts still run. Verified: 323 imported joins created 0 notifications; the same join outside import mode created 1. (Outbound webhooks no-op unless an endpoint is registered, and only schedule cron; disable them for a large import.)
- Resumable + idempotent via the IdMap; keyset pagination, never OFFSET.
- One-time tool: companion-installed, run, then removable.
