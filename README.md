# BuddyNext Importer

**Your whole BuddyPress or BuddyBoss community moves to [BuddyNext](https://github.com/buddynext/buddynext) - and you get told exactly what arrived.**

Not an export. Not "members and a CSV of posts". Members, profile fields and their
values, member types, groups with their privacy and roles, years of activity with
its comment threads, friendships, follows, reactions, forums with topics, replies
and tags, avatars and covers, albums and media, and private messages.

Then you delete this plugin and it leaves nothing behind.

## Why this one is different

Community migrations have historically meant "move what you can and accept the
losses", with no way to tell a legitimate drop from a silent one. Three things
change that here.

**Nothing moves in the dark.** Every domain reports source count against rows
written, plus a plain-sentence reason for every row it did not write. Not a
progress bar and a total.

```
posts       1838   1443
  395 posts were refused because their author is not a member of the space they belong to.
comments   11006   8983
  7819 comments were on a post that did not migrate, so they were dropped with it.
```

That matters because of what happens next: the operator deletes the old
community. A shortfall nobody explained is how that goes wrong.

**It writes through BuddyNext's services, never raw SQL.** So counters,
the search index, hashtags, mentions and privacy rules end up correct rather than
approximately correct. It also means anything BuddyNext itself would refuse gets
refused here too, and reported, instead of being forced in through the back door.

**Privacy is enforced during the move, not assumed.** A group post that cannot be
placed in a space is refused rather than published to the global feed, and
`verify` independently confirms no private or secret space content became
publicly searchable. This is the failure that quietly turns a private group into
a public one, and it is checked on every run.

## Proven on real communities, not a demo

Every release is established by migrating disposable but realistic communities end
to end, then reconciling the result against an independently counted source.

| Source | Size | Result |
|---|---|---|
| BuddyPress + bbPress | 501 members, 20 groups, 1,838 activities, 16,802 comments, 6,757 friendships, 1,405 forum items, 200 DM threads | every domain reconciled, every shortfall attributed |
| BuddyBoss Platform 3.3.0 | 201 members, 20 groups, 4,619 comments, 1,284 friendships, 5 albums, 25 DM threads | same, plus media and album ordering preserved |

A second full run over a completed migration writes **zero rows** across all 18
domains. Nothing duplicates, and an interrupted run resumes where it stopped.

## Before you run it

> [!WARNING]
> **Rehearse on a staging site or a local copy first. Never run this on a live
> community you have not already migrated somewhere else.** It never duplicates on
> a re-run, but it cannot be undone from inside the plugin, and reversing it means
> restoring a backup.

The rehearsal is the point. A migration is a one-way door, and the only way to
know what your community looks like on the other side is to open it somewhere
that does not matter yet.

1. **Take a copy of the live site.** A host staging environment, or a local one -
   LocalWP, DevKinsta, wp-env and Docker all work, and a local copy is usually
   faster to throw away and rebuild than a staging slot. Whatever is easiest to
   destroy and recreate is the right choice.
2. **Migrate there**, with the domains you actually want.
3. **Check it**, with `wp buddynext-import verify` or the "Check the migration"
   button. Read the reasons under any shortfall rather than the totals alone.
4. **Look at the community as a member would.** Open a private space, a migrated
   discussion, a member's profile. Counts reconciling is not the same as the
   community feeling right.
5. **Only then repeat it on production**, once you know what you are going to get
   and you have a backup you have actually tested restoring.

If you skip straight to production and the result is wrong, the fix is a database
restore. There is no undo button in this plugin, and adding one is not on the
roadmap - a migration touches too many services to unwind safely.

**Current version: 1.2.0** - see [readme.txt](readme.txt) for the changelog.

## Supported sources

- **BuddyPress** (including bbPress forums, and rtMedia photos, albums and library media)
- **BuddyBoss Platform** (including its media, albums, documents notice and DM attachments)

FluentCommunity, PeepSo and Ultimate Member are v2. The adapter architecture below
is built to take them, but no adapter for them ships today. See
[docs/build-plan.md](docs/build-plan.md).

The same reason sentences appear in the CLI, in the admin table and in `verify`,
because they come from one shared list rather than a copy per surface. Two
surfaces that disagree about why a row was dropped is how a migration loses an
owner's trust at exactly the wrong moment.

## How it works

A source-adapter architecture. Each platform has a read-only **adapter** that reads
its own database tables and normalizes records to one common shape. **Writers** then
create them in BuddyNext **through the BuddyNext service layer** (never raw SQL into
`bn_*` tables), so denormalized counters, the search index, hashtags, mentions and
privacy/role rules all stay correct.

```
BuddyPress / BuddyBoss          (v2: FluentCommunity / PeepSo / Ultimate Member)
        |  (per-source read adapter)
        v
   common record shape  ->  BuddyNext services (write)  ->  bn_* tables
```

### Four surfaces, one step list

The CLI, the background runner, the REST endpoint and the admin page all read
`Pipeline\StepRegistry`. They each used to carry their own copy of the step list and
the copies drifted - the browser loop knew 5 of 16 domains while reporting success.
Adding a domain is now one entry in the registry, never four edits kept in sync.

### Built for scale and safety

- **Import mode** suppresses side effects for the duration of the run: no notifications, emails, webhooks or real-time pushes fire for imported content, and rate limits that exist to stop live abuse are lifted for the replay.
- **Keyset pagination everywhere** (`WHERE id > ? ORDER BY id ASC LIMIT ?`), never `OFFSET`. This targets large communities.
- **Resumable and idempotent** - a `source-id -> buddynext-id` map makes every write idempotent and lets a large import resume after a failure without double-importing. A second full run writes zero rows.
- **Privacy is enforced, not assumed** - a group post that cannot be placed in a space is refused rather than published to the global feed, and `verify` confirms no private or secret space content is publicly searchable.

## What gets migrated

18 steps across 12 selectable domains.

| Domain | BuddyNext target |
|---|---|
| Profile fields and values | `bn_profile_fields` / `bn_profile_values` |
| Member types and assignments | `bn_member_types` / `bn_member_type_assignments` |
| Group types | `bn_space_categories` |
| Groups and their members | `bn_spaces` + `bn_space_members` (privacy + role mapping) |
| Activity posts and comments | `bn_posts` + `bn_comments` |
| Blog-post activity | `bn_posts` as the article type, with its comment thread |
| Friendships | `bn_connections` |
| Follows | `bn_follows` |
| Reactions and favourites | `bn_reactions` |
| Forums, topics and replies | Jetonomy (`jt_*`), including bbPress topic tags |
| Avatars and cover images | BuddyNext member and space media |
| Albums and media | WPMediaVerse (`mvs_media_index`, `mvs_album_items`), preserving album order |
| Private messages | WPMediaVerse DM engine (`mvs_conversations` + `mvs_messages`), with their photo attachments |

A BuddyBoss group album becomes a space-owned album, and so does an rtMedia album
whose context is a group. Media that was never posted to activity - a photo sitting
only in someone's library - comes across too, rather than being left behind because
no activity referenced it.

A photo sent inside a private message stays scoped to that conversation: it is
migrated as conversation media, so it never surfaces in a member's media library or
in Explore Media.

Notifications are deliberately not imported - they are transient.

### Choosing what to import

Everything is selected by default. An owner who ignores the panel gets the full
migration. Deselecting is safe: a later run tops up rather than duplicating.

```bash
wp buddynext-import migrate-all --skip=messages,media
wp buddynext-import migrate-all --only=reactions   # pulls in its parents automatically
```

Selection is per phase, so "comments but not posts" cannot be expressed - the
dependency that would silently drop every comment. A domain left out on purpose
reads *skipped by choice*, never as a shortfall.

## Usage

Tools -> Import to BuddyNext, or:

```bash
wp buddynext-import stats                  # read the source, write nothing
wp buddynext-import migrate-all            # run it
wp buddynext-import verify --samples=20    # coverage, per-domain totals, sampled objects
wp buddynext-import cleanup                # drop the mapping tables when you are done
```

`cleanup` is final: afterwards there is no duplicate protection, so a later run
re-creates everything. Do any top-up imports before it.

## Requirements

- BuddyNext active (declared via `Requires Plugins`, so WordPress enforces it)
- WordPress 6.9+ (BuddyNext needs the Abilities API)
- PHP 8.1+
- Jetonomy for forums, WPMediaVerse for media and private messages. A domain whose engine is absent is skipped and said so, never skipped silently.

## Development

There is no PHPUnit suite. Correctness is established by running a real migration
against a disposable seeded community:

```bash
cd docker
./run.sh bp          # blank site + a BuddyPress community, BuddyNext INACTIVE
./run.sh target-on   # deactivate the source, activate BuddyNext + this plugin
./run.sh migrate
./run.sh verify
./run.sh reconcile   # source vs destination, counted independently
./run.sh down
```

`./run.sh bb` builds a BuddyBoss source instead. The fixture fetches its community
generator on first use, so a fresh clone needs nothing but Docker.

Gates (also run in CI across PHP 8.1 to 8.4):

```bash
bin/shipped-php-files.sh | xargs -0 -n1 php -l    # every file that ships
../buddynext/vendor/bin/phpcs --standard=.phpcs.xml.dist
bin/build-release.sh                              # both, plus packaging
```

Read [CLAUDE.md](CLAUDE.md) before changing anything - it carries the four rules
this codebase depends on.

## License

GPL-2.0-or-later. Part of the [BuddyNext](https://github.com/buddynext) project by [Wbcom Designs](https://wbcomdesigns.com).
