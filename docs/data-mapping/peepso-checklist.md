# PeepSo -> BuddyNext checklist

Coverage list for the PeepSo reader on this branch (`Source\PeepSo\PeepSoAdapter`, source key `peepso`). Use it to check a run against Maple Hill. How to run the import is in [peepso.md](peepso.md).

Verified on **2026-10-20** against a real Local site, not a sketch:

| | |
|---|---|
| Source site | `http://peepso.local` (Local site id `peepso`, "Maple Hill Commons") |
| PeepSo | core 9.1.0.0, add-ons 9.0.5.3 |
| Importer this map targets | 1.2.0, `StepRegistry` + the existing writers |
| Destination to rehearse on | a **copy** of that site with BuddyNext active. Do not run the import on the only copy of the fixture |

The importer reads the database of the site it is activated on and writes through BuddyNext services. PeepSo tables and BuddyNext have to be on the same WordPress for a run. Copy Maple Hill, install BuddyNext plus this plugin there, then migrate. Leave `http://peepso.local` intact so the sample ids below still resolve.

A local inventory also sits on the fixture at `wp-content/uploads/maple-hill-migration-index.json`. Treat this file as the contract. The JSON was written earlier in the seed and its `nested_reply_comment` id is stale (it says 278; the reply that landed is **299**).

## What the reader does

`Source\PeepSo\PeepSoAdapter` implements `SourceAdapter` and is registered in `AdapterRegistry::all()` under the key `peepso`.

Do not add a second writer, and do not write SQL into `bn_*`. The BuddyPress and BuddyBoss adapters already proved the writers. This adapter's job is to emit the same record arrays those writers already accept, keyset-paginated (`id > ? ORDER BY id ASC LIMIT ?`).

`source_id` for a post or a comment is the PeepSo **activity id** (`wp_peepso_activities.act_id`), not the WordPress post id. Reactions, the id map, and comment parents all join on that id. Using `act_external_id` (the WP post id) will make every reaction report `activity_not_imported`.

Register it the same way the other two are registered. `key()` returns `peepso`. `is_available()` is true when `wp_peepso_activities` exists. `stats()` must count exactly what the readers return, including pending rows, or `verify` will report a gap that is not a gap.

## What we import, what we declare, what we skip

| PeepSo record | Action | Existing step | BuddyNext target |
|---|---|---|---|
| Members + profile values | import | `profiles` | `bn_profile_fields` / `bn_profile_values`. Values come from `peepso_user_field_{slug}` usermeta (Website from `user_url`), with `usr_gender` / `usr_birthdate` as the fallback |
| Per-field privacy | import | `profiles` | the member's `peepso_user_field_{slug}_acc` choice (10 public, 20 members, 30 friends, 40 only me), and the field's `default_acc` as the field default |
| Group categories | import | `space_categories` | `bn_space_categories` |
| Groups and pages + members, mods, bans, join requests | import | `spaces` | `bn_spaces` + `bn_space_members`. Rules text (`peepso_group_rules` / `peepso_page_rules`) is written through `SpaceService::update()` |
| Status posts, including group posts | import | `activity` / `posts` | `bn_posts` |
| Journal stream cards (module 6661) | import | `activity` / `posts` | article post, same as BP `new_blog_post` |
| Comments and replies | import | `activity` / `comments` | `bn_comments` |
| Accepted friendships + pending requests | import | `friends` | `bn_connections` |
| Follows | import | `follows` | `bn_follows` |
| Blocks | import | `blocks`, before connections and follows | `BlockService::block()` |
| Reactions | import, by type | `reactions` | `bn_reactions`. like, love, haha, wow, sad and angry keep their type. wink, crazy, speechless, grateful, celebrate and custom types become a like and are declared |
| NSFW flag, profile pin | import | `activity` / `posts` | `content_warning` (`nsfw`). A live pin on a profile post is re-pinned with `PostService::pin()` |
| Avatars + covers | import | `images` / `members`, `images` / `groups` | member and space media. Page files are read from `wp-content/peepso/pages/{id}/` |
| Photo albums + photos | import | `media` / `albums`, `album_media`, `media` | WPMediaVerse |
| Uploaded video | import | `media` | WPMediaVerse, same as a BuddyBoss video |
| Messages | import | `messages` | WPMediaVerse conversations |
| Forums | empty | `forums` | return empty arrays. PeepSo has no forum here |
| Member types | import | `member_types` | VIP icons a member holds (`peepso_vip_user_icon`). Icons nobody holds are declared, not created. A member holds one BuddyNext type; extra icons are reported as `multiple_source_types` |
| Files (module 50) | **declare, do not import** | — | same refusal as BuddyBoss `bp_document` |
| Stories | **declare** | — | no phase in `StepRegistry` |
| Backgrounds, reports, pending-approval posts, journal-card photos | **declare** | — | no field on the current writers |
| Group/page followers who are not members, group/page notification settings, post follows, view and read records, edit stamps, space pins, unread and deleted message state, non-default group/page settings | **declare** | — | BuddyNext has no equivalent, or (message state) MediaVerse has no API to write it |
| Notifications, mail queue, digest, GDPR, read markers | **skip** | — | transient, same decision as `bp_notifications` |
| Avatar-change and cover-change stream posts | **skip** | — | same idea as BP `joined_group` |

"Declare" means `unsupported_content()`, the method BuddyBoss uses for documents. The admin screen and `wp buddynext-import migrate-all` print that list before a run. A row that is counted by no step and named by no notice disappears, and the run still reports success.

## Record shapes the writers already accept

Copy these keys. The writers read them by name.

### Groups — `SpaceWriter::import_space()`

| Key | PeepSo source |
|---|---|
| `source_id` | `peepso-group` post id |
| `creator_id` | group owner (`gm_user_status = member_owner`) |
| `name`, `slug`, `description` | the group post |
| `status` | see the privacy table below. The writer only understands `public`, `private`, `hidden` |
| `parent_id` | `0`. PeepSo groups are not nested |
| `date_created` | `post_date_gmt` |
| `type_ids` | category ids from `wp_peepso_group_categories` (`gm_cat_id`, `gm_group_id`). Not WordPress terms |

`SpaceWriter::STATUS_MAP`: `public` -> space `open`, `private` -> `private`, `hidden` -> `secret`. Anything else becomes `open`, which would publish the secret group.

| PeepSo `peepso_group_privacy` | Emit `status` | Fixture |
|---|---|---|
| `0` open | `public` | 147 Garden Club |
| `1` closed | `private` | 156 Maple Hill Parents |
| `2` secret | `hidden` | 267 Tool Library |

Categories on the fixture: 143 Outdoors, 144 Neighbors, 145 Food, 146 Family. Import categories before groups. A group stores `category_id` at create time.

### Group members — `SpaceWriter::import_member()`

| Key | PeepSo `gm_user_status` |
|---|---|
| `user_id` | the member |
| `is_admin` | `0`. The owner is `creator_id` and is not a member row |
| `is_mod` | `1` for `member_moderator` |
| `is_confirmed` | `0` for `pending_user` |
| `is_banned` | `1` for `banned` |
| `row_id` | membership row id, used as the keyset cursor |

| Role on the fixture | Emit | Where |
|---|---|---|
| owner | `creator_id`, do not also emit a member row | all 9 groups |
| `member` | confirmed member | 54 rows |
| `member_moderator` | `is_mod = 1` | Caleb (user 10) on group 267 |
| `pending_user` | `is_confirmed = 0` | Hannah (user 7) on group 267 |
| `banned` | `is_banned = 1` | Ruby (user 15) on group 267 |
| `pending_admin` | no writer field | Lila (user 19) on group 156. Declare this one row until a role exists |

Group followers (`wp_peepso_group_followers`, 65 rows) are not members. Declare them. Do not insert them as `bn_space_members`.

### Activity posts — `ActivityWriter::import_post()`

| Key | PeepSo source |
|---|---|
| `source_id` | `act_id` |
| `user_id` | `post_author` |
| `component` | `activity` for a profile/site post. `groups` for a group post |
| `item_id` | group post id when `component = groups`, else `0` |
| `source_type` | `activity_update`, or `new_blog_post` for module 6661 |
| `secondary_item_id` | WordPress post id for a journal card, else `0` |
| `content` | `post_content`, after the mention rewrite below |
| `date_recorded` | `post_date_gmt` |
| `hide_sitewide` | `1` for secret-group posts and for anything that must not hit the global feed |
| `privacy` | see the privacy table below |

A group post whose space did not import is refused with `space_not_imported`. It must not fall through to `space_id = 0`. That is the global feed, and it is how a secret group becomes public. The writer already does this. The reader has to set `component = groups` and `item_id` to the group id, read from post meta `peepso_group_id`.

Fixture group posts: **285** (YouTube, group 153) and **297** (secret group 267, "Extension cord"). Guests and non-members cannot see 297. After import, 297 is inside the secret space and is not publicly searchable. `verify` already checks that for private and secret spaces.

Module ids on `act_module_id`:

| Module | What it is | Reader |
|---|---|---|
| 1 | status | import, roots only (`act_comment_object_id = 0`) |
| 4 | photo activity | do not count these as posts. Photos go through the media steps. 103 of these rows are orphans whose parent post was deleted (avatar and cover changes). The photo table has 41 real rows |
| 5 | video or audio | video file through media. Audio is declared |
| 6 | message | messages step, never the public stream. `act_access` is 40 |
| 8 | group stream post | import. The secret-group post is activity 200, post 297, `peepso_group_id` 267. The group record itself is the spaces step |
| 9 | page activity | declare with pages |
| 30 | poll | declare |
| 50 | file | declare |
| 111 | post background | import the text as a status, and declare the background preset (post 300) |
| 6661 | journal card | `source_type = new_blog_post`. Fixture journal posts 239, 242, 245, 248 |

Skip `post_status = future` until the post writer grows a schedule field. Fixture: post **294**, "Ridge run, Saturday 7am", `2026-10-25`. Declaring it is better than publishing it during the run.

Skip anything with post meta `peepso_reported`. That is the PeepSo equivalent of `is_spam = 1`, which the BuddyPress reader already excludes. Fixture: comment **199**, reported by Marcus as spam.

### Post privacy — one decision before coding the reader

`PrivacyMap::post_privacy()` returns `private` only for `onlyme`, `friends`, `adminsonly`, and `private`. Every other string, including a members-only value, becomes **`public`**.

| PeepSo `act_access` | Meaning | Fixture | Emit this, or the post changes audience |
|---|---|---|---|
| 10 | public | 183 welcome post | `public` |
| 20 | site members | 292 library hold list | **no matching post value.** Emitting `loggedin` publishes it. Do not ship the reader until this row has an explicit decision |
| 30 | friends | 298 spare key. Maya (user 3) can see it, Samir (user 12) cannot | `friends` -> writer stores `private` |
| 40 | only me | 293 | `onlyme` -> writer stores `private` |

Friends-only and only-me both land on BuddyNext `private`. That matches what BuddyBoss already does. Members-only does not match anything the post writer stores. Profile fields do have a `members` level. Posts do not.

`hide_sitewide = 1` on a post that has no space is refused (`withheld_at_source`). Use that for access 20 and 40 only if the decision is "drop it" rather than "publish it". Do not use it as a quiet way to publish them.

### Mentions

`ActivityWriter` rewrites BuddyBoss anchors of the form `{{mention_user_id_N}}` into `@handle`. It does not understand PeepSo tokens.

PeepSo stores `@peepso_user_{id}(Display Name)` in `post_content`. The reader rewrites that to the BuddyBoss anchor (or straight to the destination `@handle`) **before** the writer sees the string. The id inside the token is the user id. The display name is not.

Fixture posts with mentions: **183**, **187**, **188**.

Hashtags ride along in the content (`#tag`). BuddyNext rebuilds them on write. The `wp_peepso_hashtags` table does not need its own step.

### Comments — `ActivityWriter::import_comment()`

| Key | PeepSo source |
|---|---|
| `source_id` | the comment's `act_id` |
| `user_id` | comment author |
| `root_id` | `act_id` of the root post, not the WP post id |
| `secondary_item_id` | `act_id` of the parent comment when this is a reply, else `0` |
| `content` | comment `post_content` |
| `date_recorded` | `post_date_gmt` |

`root_id` is looked up in the id map as a **post**. `secondary_item_id` is looked up as a **comment**. A reply whose parent comment was not imported is dropped, not attached to the root.

PeepSo stores the parent as `act_comment_object_id` = the parent **WP post id**, and `act_comment_module_id = 1` when that parent is itself a comment. Walk that to activity ids before emitting the row.

Fixture reply: comment post **299**, text "Replying under that comment, so this thread has a second level." Its `act_comment_object_id` is **201** (Priya's comment on post 184, slug `83z4v6nw`). Top-level comments on that post include Riley's fire-escape comment and Priya's pot-counts comment.

### Friendships — `ConnectionWriter`

PeepSo splits what BuddyPress keeps in one table.

| Source | Rows | Emit `is_confirmed` |
|---|---|---|
| `wp_peepso_friends` | 41, one row per accepted pair, no status column | `1` |
| `wp_peepso_friend_requests` | 2 pending | `0` |

`stats()['friendships']` must be 43, because `friendships()` returns both. Counting only the accepted table makes `verify` say the importer invented two connections.

Pending on the fixture: Samir (12) -> Derek (18), Elena (13) -> Theo (14).

A friend request also inserts `wp_peepso_user_followers`. Do not import those rows again as follows.

### Follows

`wp_peepso_user_followers` where `uf_follow = 1`. There were 84 such rows on the fixture, and many exist only because a request was sent. Import a row when the member followed on purpose.

Real sample: Elena (13) follows Luis (6).

### Reactions

`ReactionWriter` calls `react(..., 'like', ...)`. It ignores reaction type. PeepSo has 11 types (like is reaction post 22) and 41 uses in `wp_peepso_reactions`. Emit `user_id`, `activity_id` = `act_id`, `date_created`. All 41 become likes. Declare the other ten types as lossy in `unsupported_content()` if the product decision is to say so, and still import the rows so the like count is explained.

The row can point at a post or a comment. The writer tries the post id map, then the comment id map. Both `source_id`s have to be activity ids.

### Avatars and covers

Files, not attachments: `wp-content/peepso/users/{id}/` and the matching group directory. Same idea as `uploads/avatars/{user_id}/`.

Skip stream posts whose meta `peepso_photo_type` is `peepso_avatar` or `peepso_cover`. The image step already takes the file. Importing those posts floods the feed. The fixture still has orphan module-4 activity rows from deleted avatar and cover posts. Do not resurrect them.

### Photos, albums, video

The media writer ingests a **WordPress attachment id** (`MediaIngest::ingest`). BuddyBoss gets there via `bp_media.attachment_id` / `bp_video.attachment_id`. PeepSo's source of truth is `wp_peepso_photos` (41 rows) and files under the PeepSo user or group directory. `pho_thumbs` stores bare filenames. The original has to exist on the filtered path or the front end renders an empty tile.

Reader work, in the adapter, before the writer:

1. Albums from `wp_peepso_photos_album`. Empty system albums (avatar, cover, stream) are not albums. Fixture album worth checking: **40** "River path", owner user 10, holds the photo from post 187.
2. Album photos (`pho_album_id > 0`) through `album_media`, after the album is in the id map.
3. Stream photos (`pho_album_id = 0`) through `standalone_media`.
4. Resolve each row to an attachment id whose file exists. A row with no file is `unsupported_content`, same as an rtMedia row whose attachment was deleted.
5. Uploaded video: `wp_peepso_videos` with `vid_stored = 1` and post meta `peepso-video`. Fixture post **286**.
6. YouTube is not media. Post **285** has `vid_url` `https://www.youtube.com/watch?v=aqz-KE-bpKQ`. Leave the URL in the post body.

Audio lives in `wp_peepso_videos` too, split by post meta `peepso-audio`, not by the table name. Fixture post **288** "Porch note". Declare it. Do not file it as a video.

### Messages

`peepso-message` posts, module 6. Eight posts on the fixture, `act_access` 40. Count threads, not message rows, which is what `stats()['message_threads']` already does for BuddyPress. A photo attached to a message stays on the conversation and does not enter the member's media library. That rule already exists for BuddyBoss DM attachments.

### Journal posts

The WordPress posts (239, 242, 245, 248) are ordinary posts. The stream card is a separate activity, module 6661. Emit the card as `new_blog_post` with `secondary_item_id` = the WP post id, which is how `ActivityWriter` builds the article without an HTTP fetch. The card is refused when the post is not public.

## Declare in `unsupported_content()`

Say it before the run, with a row count, the way `BuddyBossAdapter::unsupported_content()` does for `bp_document`.

The table below is the original pre-build plan. What the reader declares today, measured on Maple Hill on 2026-10-23:

| Declared | Rows |
|---|---|
| File attachment (module 50) | 1 |
| Post background preset | 1 |
| Scheduled post | 1 |
| Posts waiting for approval | 3 |
| Reported item | 1 |
| Photos on journal cards (the card shows the blog post and its featured image) | 8 |
| Group/page followers who are not members | 1 |
| Post follows | 111 |
| View and read records | 33 |
| Edited stamp | 1 |
| Unread message markers | 8 |
| VIP icons nobody holds | 8 |
| Reactions with no BuddyNext type (imported as a like) | 1 |

Muted groups, deleted messages, space pins and non-default group/page settings are declared when present. Maple Hill has none.

| Record | Fixture | Why |
|---|---|---|
| Files, module 50 | post 290, one text attachment | `UploadService` refuses documents. BuddyBoss PDFs are already named and not carried |
| Audio | post 288 | no audio type in either current adapter |
| Polls + votes | post 284, 3 votes in `wp_peepso_polls_user_answers` | no phase. `select_options` is a map of option key to label and count |
| Pages | 171 Hearth, 174 Library, 177 Market, 180 Birch Lane | `peepso-page` is not a group and not a WordPress page. 30 rows in `wp_peepso_page_members` |
| Stories | 6 `peepso-story` posts (228, 230, 232, 234, 236, 238) | no phase |
| Blocks | 1 row, Derek (18) blocks Aisha (9) | no phase. BuddyBoss moderation was left out the same way |
| Group followers | 65 | not memberships |
| Post backgrounds | post 300, preset 33, red background, June (user 17) | no field on the post writer. The sentence can still travel as a status |
| Saved posts | Maya (user 3) saved activity 190, the poll. `wp_peepso_saved_posts.post_id` is an **activity id** | no phase |
| Revisions | one row on post 184, live text ends "Edited: the gate is unlocked." | import the live text. The previous text has nowhere to go |
| Pending-admin membership | Lila on group 156 | writer has banned, pending, and moderator, not pending-admin |
| Scheduled post | 294 | writer would publish it |
| Reported item | comment 199 | skip with spam, and name it so the count is explained |
| Reaction types other than like | 11 definitions, 41 uses | rows import as likes |

## Skip outright

| Record | Fixture | Why |
|---|---|---|
| Notifications | `wp_peepso_notifications` | transient |
| Mail queue | drained, 0 rows | must not send |
| Email digest | one logged row, sending is off | transient |
| GDPR request | Hannah (user 7), status ready | not community content |
| Read / unread | 10 reads for Maya, activity 199 left unread | transient |
| Avatar and cover change posts | deleted posts, orphan module-4 activities remain | the image step has the files |

Reposts: one row, post **296**, `act_repost_id` pointing at the welcome activity. There is no repost field on the post writer. Import it as a normal post whose content quotes the original, or declare it. Do not drop it silently.

Pins (`peepso_pinned` on post 183) and the sensitive flag (`peepso_nsfw` on post 295, marked by user 14) have no writer field. Declare both. The post text still imports.

## Build order

Same dependency order as `StepRegistry::steps()`. A later phase that cannot resolve an id map entry is refused, not published.

1. `profiles`
2. `member_types` (empty)
3. `space_categories`
4. `spaces` (groups, then members)
5. `images` / `groups` after spaces exist
6. `activity` / `posts` (depends on spaces)
7. `activity` / `comments`
8. `blocks`
9. `friends`
10. `follows`
11. `reactions` (depends on activity)
12. `forums` (empty)
13. `images` / `members`
14. `media` albums, then album photos, then loose photos and uploaded video
15. `messages`

First slice that is worth a rehearsal: profiles, categories, spaces, posts, comments, friends. Media, messages, and the `unsupported_content()` list follow. Do not start by forking `ActivityWriter`.

## Test on Maple Hill

Rehearse on a copy. The importer cannot be undone from inside the plugin. After the run, walk [peepso-qa.md](peepso-qa.md). That file is the pass/fail list. The table under it is the older spot-check and is not the contract.

Before the run:

```bash
wp buddynext-import stats --source=peepso
```

`stats` should agree with the readers. In particular:

| Stat | Expect |
|---|---|
| `groups` | 9 |
| `group_members` | every membership row, pending and banned included, owners not double-counted |
| `friendships` | accepted pairs **plus** the 2 pending requests |
| `activities` | real status posts only. Not module-4 orphans, not messages, not reported items |
| `activity_comments_importable` | comments whose root post is in that activity set, including reply 299 |

Then `wp buddynext-import migrate-all --source=peepso` and `wp buddynext-import verify`. A second full run writes zero rows.

Spot-checks, in the product, not only in the counts:

| Check | Pass |
|---|---|
| Group 267 | secret space. Post 297 is inside it. A guest search does not find it |
| Group 156 | private space. Hannah is not a member of 267. Caleb is a moderator of 267. Ruby is banned from 267 |
| Post 183 | public, both photos visible, mentions point at the right members |
| Post 298 | not visible to a non-friend (Samir). Visible to a friend (Maya) |
| Post 292 | still members-only, or listed in the pre-run notice if the decision was to drop it. It is not public |
| Post 293 | only the author |
| Comment 299 | threaded under comment 201, on post 184 |
| Comment 199 | absent, and the notice or the skip reason explains it |
| Post 284 | absent as a poll, and the notice says so |
| Post 290 | absent as a document, and the notice says so |
| Album 40 | exists, and the river-path photo is in it |
| Post 286 | plays. Post 285 still shows the YouTube URL as text |
| Messages | not on the public stream |
| Post 294 | still scheduled, or named as not carried. Not already published |

Do not re-run the fixture seed. A second seed duplicates the community and invalidates every id in this doc.
