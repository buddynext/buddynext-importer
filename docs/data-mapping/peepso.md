# PeepSo adapter

`Source\PeepSo\PeepSoAdapter` is the reader. It is registered as `peepso` and fills the same writers BuddyPress and BuddyBoss use. A PeepSo-only site is detected on its own, so `wp buddynext-import stats` does not need `--source`.

## What it covers

The importer moves the main community data. Every site is set up differently, so this list is the promise. Anything not on it is not carried over.

| PeepSo | BuddyNext |
|---|---|
| Members' profile fields and values, with each member's field privacy | Profile fields and values |
| VIP icons that members hold | Member types and assignments |
| Groups and pages, with privacy, rules, avatar and cover | Spaces |
| Group and page members, with owner and moderator roles | Space members |
| Posts on profiles, groups and pages, with photos, video, audio, polls, links and YouTube | Posts |
| Comments and replies | Comments |
| Reactions, keeping the type where BuddyNext has it, otherwise a like | Reactions |
| Journal (blog) posts | Article cards, keeping the original date |
| NSFW flag and profile pins | Content warning and pinned post |
| Friends | Connections |
| Followers | Follows |
| Blocked members | Blocks |
| Avatars and covers | Member media |
| Photo albums | WPMediaVerse albums |
| Private messages and their text | WPMediaVerse conversations |

Members-only posts are stored as private, because a members-only value would otherwise be published to guests.

Before anything moves, `migrate-all` prints what it found on this site that is outside the list, with counts. `verify` and the admin page show the same list. That gives the site's developer a starting point for anything they want to handle themselves.

## Running it

Rehearse on a **copy** of the site. The importer writes into the site it is activated on, and it cannot be undone from inside the plugin.

```bash
wp buddynext-import stats
wp buddynext-import migrate-all
wp buddynext-import verify
```

A second `migrate-all` writes nothing, so a stopped run can be resumed.

Photos are files in `wp-content/peepso/`, not attachments. The writers accept a path for that case. Stream photos ride their post.

The row-by-row checklist with the Maple Hill fixture ids is [peepso-checklist.md](peepso-checklist.md). The QA walkthrough is [peepso-qa.md](peepso-qa.md).
