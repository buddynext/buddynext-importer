# BuddyNext Importer

Move an existing BuddyPress or BuddyBoss community into [BuddyNext](https://github.com/buddynext/buddynext), then remove this plugin.

> One-time transition tool. Install it, run the migration, verify it, then delete it. BuddyNext core never carries migration code.

**Current version: 1.1.0** - see [readme.txt](readme.txt) for the changelog.

## Supported sources

- **BuddyPress** (including bbPress forums and rtMedia activity photos)
- **BuddyBoss Platform** (including its media, albums and DMs)

FluentCommunity, PeepSo and Ultimate Member are v2. The adapter architecture below
is built to take them, but no adapter for them ships today. See
[docs/build-plan.md](docs/build-plan.md).

## The rule this tool is built around

**A silent shortfall is the worst bug a migration tool can have**, because the
operator deletes the old community believing everything moved. So every domain
reports source-against-written plus a reason-coded breakdown of everything it did
not write. Never "N imported" alone.

```
posts       1838   1443
  395 posts were refused because their author is not a member of the space they belong to.
comments   11006   8983
  7819 comments were on a post that did not migrate, so they were dropped with it.
```

The same sentences appear in the CLI, in the admin table and in `verify`, because
they come from one shared list rather than a copy per surface.

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
| Private messages | WPMediaVerse DM engine (`mvs_conversations` + `mvs_messages`) |

A BuddyBoss group album becomes a space-owned album. Notifications are deliberately
not imported - they are transient.

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
