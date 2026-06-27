# BuddyPress -> BuddyNext data mapping

Verified at code + data level against **reign-release.local** (BuddyPress + BP xProfile Custom Field Types 1.3.1) using real demo data, not assumptions.

Demo volumes (for scale context): 26 users, 250 xprofile values, 45 groups, 569 activity rows (129 real / 440 system), 91 friendships.

## What we import (and what we deliberately do not)

| Component | Imported | BuddyNext target |
|---|---|---|
| Members + xprofile fields | yes | `bn_profile_fields` / `bn_profile_values` |
| Groups | yes | `bn_spaces` |
| Group members | yes | `bn_space_members` |
| Activity (real posts) | yes | `bn_posts` |
| Activity comments | yes | `bn_comments` |
| Friendships | yes | `bn_connections` |
| Member types | yes (if present) | BN member types |
| Avatars + cover images | yes | BN member media |
| Private messages | yes (later phase) | WPMediaVerse DM engine |
| **Notifications** | **no - intentionally skipped** | - |

**Why skip notifications:** they are transient. Re-creating historical `bp_notifications` rows would only add noise to a fresh community. The same reasoning drops the system-activity rows below.

## 1. Members + xprofile fields

Source: `bp_xprofile_groups`, `bp_xprofile_fields`, `bp_xprofile_data`. Field groups -> BN field groups (`type => flat`); order/required/default carry as field attributes; per-field visibility (public/loggedin/friends/adminsonly) maps to the BN field privacy check.

### Field-type map (core BuddyPress)

| BP `type` | Storage (verified sample) | BN type | Transform |
|---|---|---|---|
| `textbox` | `Antawn Jamison` (plain) | `text` | none |
| `textarea` | plain | `textarea` | none |
| `selectbox` | `selectbox 1` (plain) | `select` | none |
| `radio` | `radio 3` (plain) | `radio` | none |
| `url` | `https://wordpress.com` (plain) | `url` | none |
| `number` | `2010` (plain) | `number` | none |
| `telephone` | plain | `text` | none (no tel type) |
| `datebox` | `2000-01-01 00:00:00` (datetime) | `date` | reformat datetime |
| `checkbox` | `a:2:{i:0;s:10:"checkbox 2";...}` (PHP serialized) | `checkbox` | `maybe_unserialize` -> BN multi-value |
| `multiselectbox` | `a:2:{...}` (PHP serialized) | `multiselect` | same |
| `wordpress-textbox` / `wordpress-biography` | NOT in `bp_xprofile_data` - synced to WP user (`wp_update_user`) | (WP user field) | **skip** - BN reads `display_name`/`description`/`user_url` from WP core |

### Field-type map (BP xProfile Custom Field Types 1.3.1)

These are just additional `type` slugs in `bp_xprofile_fields`; their values live in `bp_xprofile_data` exactly like core types, so the adapter needs only +rows in the map, no new read logic.

| BPXCFT `type` | BN type | Storage | Fidelity |
|---|---|---|---|
| `email` | `email` | plain | clean |
| `web`, `oembed` | `url` | plain | clean |
| `datepicker`, `birthdate` | `date` | plain | clean (reformat) |
| `decimal_number`, `number_minmax`, `slider` | `number` | plain | clean |
| `country`, `select_custom_taxonomy`, `select_custom_post_type` | `select` | plain | clean |
| `multiselect_custom_taxonomy`, `multiselect_custom_post_type`, `tags`, `token` | `multiselect` | serialized array -> unserialize | clean |
| `checkbox_acceptance` | `checkbox` | plain (`1`) | clean (or skip - GDPR consent) |
| `color` | `text` | plain (`#rrggbb`) | lossy widget, value preserved |
| `fromto` | `text` | plain (range) | lossy widget, value preserved |
| `file`, `image` | BN `file` (Pro) or `url` (free) | attachment id/url | configurable - URL in free, native file in Pro |

**Two storage facts the adapter depends on:** (1) only multi-value types serialize (`checkbox`, `multiselectbox`, the two `multiselect_*`, `tags`, `token`) - everything else is a plain string; (2) `file`/`image` hold an attachment id/url, the only value type that is not text.

## 2. Groups -> Spaces

Source: `bp_groups`, `bp_groups_members`, `bp_groups_groupmeta`. Write through `SpaceService` / `SpaceMemberService`.

| BP groups | BuddyNext |
|---|---|
| `status = public` | space `open` |
| `status = private` | space `private` |
| `status = hidden` | space `secret` |
| `creator_id` | space owner |
| `parent_id` | BN sub-space (nested) |
| `name` / `slug` / `description` / `date_created` | space fields |

Group members (`bp_groups_members`): `is_admin=1` -> admin, `is_mod=1` -> moderator, else member; `is_banned=1` -> banned; `is_confirmed=0` -> pending join request; `invite_sent=1` (unconfirmed) -> pending invite.

## 3. Activity -> Posts + Comments

Source: `bp_activity`, `bp_activity_meta`. This version has no `privacy` column - visibility is `hide_sitewide` + `component`.

**Import rule (verified from the real type breakdown):**

| `component` / `type` | Count (demo) | Action |
|---|---|---|
| `activity` / `activity_update` | 75 | **import** -> `bn_posts` (sitewide post) |
| `groups` / `activity_update` | 54 | **import** -> `bn_posts` with `space_id` = mapped space (`item_id` = group id) |
| `*` / `activity_comment` | (threaded, `mptt_left/right`) | **import** -> `bn_comments` |
| `groups` / `joined_group` | 323 | **skip** - system, derivable from membership |
| `friends` / `friendship_created` | 91 | **skip** - system, derivable from connections |
| `members` / `last_activity` | 26 | **skip** - presence timestamp, not a post (map to BN presence separately if wanted) |
| any with `is_spam = 1` | - | **skip** |

Carry `content` (HTML), author (`user_id`), `date_recorded` -> `created_at`. Re-resolve @mentions to the imported users. Threaded comments rebuild from `secondary_item_id` / mptt.

## 4. Friendships -> Connections

`bp_friends` -> `bn_connections` (BuddyNext connections are mutual, same as BP friendship). `is_confirmed=0` -> pending connection request. BP has no "follow", so `bn_follows` starts empty (or derive follow = accepted connection if desired).

## 5. Private messages -> DM (later phase)

`bp_messages_messages` + `bp_messages_recipients` (+ `_meta`, `_notices`) -> WPMediaVerse DM engine via the `MediaClient` seam. Different engine/schema, so this is the riskiest domain and is scheduled after profiles/groups/activity.

## 6. Member types

`bp_member_type` taxonomy (`term_taxonomy` + `term_relationships`) -> BN member types. (None registered in this demo; handle when present.) `bp_group_type` similarly maps to BN space categories/types if used.

## 7. Avatars + cover images

File-based, not in the DB: `wp-content/uploads/avatars/{user_id}/` and `uploads/buddypress/members/{user_id}/cover-image/`. Copy the files into BuddyNext's member-media location and set the corresponding meta. No DB rows to read beyond the `_bp_avatar`/cover meta pointers.

## Import order (dependency-safe)

users (already WP users) -> xprofile field defs -> xprofile values -> member types -> groups -> group members -> activity posts -> activity comments -> friendships -> avatars/covers -> (later) messages. Counters off during the run; one **bulk recount** at the end.

## Explicitly NOT imported (noise)

- Notifications (`bp_notifications`) - transient.
- System activities (`joined_group`, `friendship_created`, `last_activity`).
- Spam activity (`is_spam = 1`).
