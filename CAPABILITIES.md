# BuddyNext Importer - what it can and cannot do

Buyer-language answers to "can it do X?". Refresh this in the same pass as
`audit/manifest.json` so store and docs copy can never over- or under-claim.

**Version 1.1.0.** Requires BuddyNext, WordPress 6.9+, PHP 8.1+.

> Run it on a staging copy first. An import writes real members, spaces, posts and
> messages. It never duplicates on a re-run, but it cannot be undone from inside
> the plugin - reversing it means restoring a backup.

## Can it migrate my community?

| Source platform | Status |
|---|---|
| BuddyPress | **Yes** |
| bbPress forums | **Yes** (topics, replies and topic tags; needs Jetonomy) |
| rtMedia | **Partly** - photos and videos that were posted to activity come across. Album-only media does not, and the importer tells you how much before you start. |
| BuddyBoss Platform | **Yes** (including its media, albums and DMs) |
| FluentCommunity | No - planned for v2 |
| PeepSo | No - planned for v2 |
| Ultimate Member | No - planned for v2 |

## What comes across

| Content | Comes across? | Notes |
|---|---|---|
| Members and profile field values | Yes | Including checkbox and multi-select fields |
| Member types and their assignments | Yes | |
| Group types | Yes | Become space categories |
| Groups and their members | Yes | Privacy and roles mapped |
| Activity posts and comments | Yes | |
| Blog-post activity | Yes | Becomes an article, and brings its comment thread |
| Friendships | Yes | Pending requests migrate as pending |
| Follows | Yes | |
| Reactions and favourites | Yes | On posts and on comments |
| Forums, topics, replies, tags | Yes | Needs Jetonomy |
| Avatars and cover images | Yes | |
| Albums and media | Yes | Needs WPMediaVerse. Album order is preserved. |
| Private messages | Yes | Needs WPMediaVerse |
| Notifications | **No** - deliberately | They are transient; replaying them would notify everyone |

## Can I choose what to import?

**Yes.** 12 domains, all selected by default. An owner who ignores the panel gets
the whole community. The picker understands dependencies, so you cannot select
comments without posts. Available on the admin screen and on the CLI
(`--only=` / `--skip=`).

Re-running later with more selected **tops up** rather than duplicating - until you
use "Remove import data", which drops the mapping that makes that safe.

## Will it tell me if something did not make it?

**Yes, and this is the point of the tool.** Every domain reports the source count
against what was written, plus a plain-sentence reason for every row it did not
write. The same wording appears in the CLI, on the admin screen and in `verify`.

It also declares, **before** a run, content it cannot carry at all - so nothing is
discovered afterwards.

## Can I check the result?

**Yes.** `wp buddynext-import verify` or the "Check the migration" button:
coverage, per-domain totals against an independently counted source, randomly
sampled objects walked end to end, and an exposure check confirming no private or
secret space content became publicly searchable.

## Is it safe to run twice?

**Yes.** Every write is keyed by source id, so a second full run writes zero rows
and duplicates nothing. A run interrupted by a timeout or a failure resumes from
where it stopped rather than restarting.

## Does it work on a large community?

Built for it: keyset pagination throughout (no `OFFSET`), batched reads, a
background runner for communities too large to finish in one request, and
counter recalculation deferred to the end. Notifications, emails, webhooks and
rate limits are suppressed for the duration so a 50k-activity import does not
spam every member.

## What it does NOT do

- No live/two-way sync. It is a one-time move, then you delete it.
- No rollback. Restore a backup instead - which is why you run it on staging first.
- No import of notifications.
- No rtMedia album import (photos on activity only).
- No FluentCommunity / PeepSo / Ultimate Member sources yet.
- It never writes to `bn_*` tables directly, so anything BuddyNext's services
  refuse (a non-member posting into a private space) is refused here too, and
  reported rather than forced.
