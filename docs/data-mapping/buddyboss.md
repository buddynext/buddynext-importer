# BuddyBoss Platform -> BuddyNext data mapping

Verified at code level against **BuddyBoss Platform 2.14.4** (installed on reign-release.local) and cross-checked against **FluentCommunity's** built-in BuddyPress/BuddyBoss migrator, a peer reference implementation.

## BuddyBoss is a BuddyPress superset

BuddyBoss reuses BuddyPress's `bp_*` tables, so **[buddypress.md](buddypress.md) is the base** - profiles, groups, group members, activity, friends, messages, members, and the import order all carry over unchanged. The BuddyBoss adapter is "the BuddyPress adapter + the deltas below," selected by a detector (the same approach FluentCommunity uses via `BPMigratorHelper::isBuddyBoss()`):

```
if ( is_buddyboss() ) { run the BuddyPress reads + the deltas below }
```

Three deltas are in scope. Everything else BuddyBoss adds is intentionally skipped (see the bottom of this page).

## Delta 1 - three extra xprofile field types

BuddyBoss adds these on top of the BuddyPress core types (which map exactly as in `buddypress.md`):

| BuddyBoss `type` | Storage (code-verified) | BN type | Transform |
|---|---|---|---|
| `gender` | single sanitized string in `bp_xprofile_data` (serialize-handled) | `select` (or `text`) | none |
| `social-networks` | **serialized array** of network -> URL rows (`maybe_serialize`) | multi-value | unserialize -> explode into N `url` fields (or one structured/multi field) |
| `member-types` | NOT field data - assigns the user's member type via `bp_set_member_type` / the member-type post type | (member-type assignment) | map to the BN member type, do not create a profile field |

`social-networks` is the only one without a clean 1:1: it is a structured multi-row value, so the adapter explodes it into individual `url` fields (one per network) or a single multi-value field - a per-import setting.

## Delta 2 - activity media (photos + videos)

BuddyPress core has no activity media; this is a BuddyBoss addition. To BuddyNext both photos and videos are just **media**, ingested through the WPMediaVerse / `MediaClient` seam, so they share one step:

- On each activity, read the activity meta keys **`bp_media_ids`** (photos, table `bp_media`) and **`bp_video_ids`** (videos, table `bp_video`).
  (Verified: `bp_activity_update_meta($activity_id, 'bp_media_ids', $media_ids)`; `bp_media`/`bp_video` also carry a back-reference `activity_id`.)
- For each id, resolve `bp_media.attachment_id` / `bp_video.attachment_id` -> the WP attachment (the real file).
- Push the files through MediaVerse and attach them to the activity's imported BN post.

This step is folded into the **shared activity mapping**, so the BuddyPress adapter (no media) and the BuddyBoss adapter (media present) run the same activity import; the media lookup simply returns nothing for plain BuddyPress.

## Delta 3 - forums (bbPress -> Jetonomy), gated on Jetonomy

BuddyBoss bundles forums via its `bp-forums` component (bbPress integration), so the data is standard bbPress in `wp_posts` + `_bbp_*` meta. The BuddyNext discussion engine is **Jetonomy** - an in-house Wbcom plugin - which is forum/topic/reply structured too, so the mapping is clean and first-class (we own both sides of it):

| bbPress (`wp_posts` type) | Jetonomy |
|---|---|
| `forum` | discussion space / category |
| `topic` | discussion (`jt_posts`) |
| `reply` | reply on the discussion |

**Conditional on Jetonomy being active.** Forums have no target engine without it, so this delta runs only when Jetonomy is enabled on the destination BuddyNext site; otherwise it is skipped (the rest of the import is unaffected). Because Jetonomy is our own plugin, the forums adapter ships alongside the importer rather than depending on a third party.

This is the one non-trivial delta: a different source structure (bbPress `wp_posts`, not `bp_*`) AND a different target engine (Jetonomy, not BN core), so it is a distinct sub-adapter rather than a field-map row. Exact post-types/meta to be confirmed at code level when this step is built.

## Out of scope (skipped by decision)

| BuddyBoss feature | Tables | Why skipped |
|---|---|---|
| Documents | `bp_document`, `document_folder` | not needed |
| Email invites | `bp_invitations` | not needed |
| Reactions / likes | `bb_reactions`, `bb_user_reactions` | not needed |
| Moderation (block/report) | `bp_moderation`, `bp_moderation_meta` | not needed |
| Subscriptions | `bb_subscriptions` | not needed |

## Summary

BuddyBoss = **BuddyPress base + 3 extra field types + activity media (photos & videos) + forums (bbPress -> Jetonomy, when Jetonomy is active)**. Profiles, groups, activity, friends, messages and members are inherited verbatim from `buddypress.md`; the activity-media step is shared with the BuddyPress adapter (it just no-ops there); the forums step is gated on Jetonomy.
