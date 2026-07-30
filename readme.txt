=== BuddyNext Importer ===
Contributors: wbcomdesigns
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later

Move an existing BuddyPress or BuddyBoss community into BuddyNext, then remove this plugin.

== Description ==

A one-time migration tool. It reads a BuddyPress or BuddyBoss community directly
from the database and writes it into BuddyNext through BuddyNext's own services,
then gets deactivated and deleted.

REHEARSE ON A STAGING SITE OR A LOCAL COPY FIRST. Never run this on a live
community you have not already migrated somewhere else. An import writes real
members, spaces, posts and messages. It never duplicates on a re-run, but it
cannot be undone from inside the plugin, and reversing it means restoring a
backup.

Take a copy of the live site - a host staging environment, or a local one with
LocalWP, DevKinsta, wp-env or Docker. Migrate there, check the result with the
migration checks, and look at the community the way a member would: open a
private space, a migrated discussion, a member's profile. Counts reconciling is
not the same as the community feeling right. Only then repeat it on production,
with a backup you have actually tested restoring.

It reports source-against-written for every domain, and gives a reason for
everything it did not write. A silent shortfall is the worst thing a migration
tool can do, because the operator deletes the old community believing everything
moved.

Domains covered: profile fields and values, member types, space categories,
spaces and their members, activity posts and comments, blog-post articles with
their comment threads, connections, follows, reactions, forums with their topics,
replies and tags, avatars and covers, albums and media, and private messages.

You choose what comes across. Everything is selected by default, so an owner who
ignores the panel gets the whole community. Deselecting is safe: a later run adds
the missing domains rather than duplicating what already arrived, and a domain left
out on purpose is reported as skipped by choice rather than as a shortfall.

Before a run it names what it cannot carry, so nothing is discovered afterwards.
After a run, "wp buddynext-import verify" and the Check the migration button walk
the result end to end: coverage, per-domain totals against an independent count,
randomly sampled objects, and a check that no private or secret space content
became publicly searchable.

The migration is resumable and safe to re-run. Every write is keyed, so a second
full run writes nothing and duplicates nothing.

Requires BuddyNext. Forums need Jetonomy; media and private messages need
WPMediaVerse. A domain whose engine is absent is skipped and said so, never
skipped silently.

== Changelog ==

= 1.1.0 - August 2026 =

The owner chooses what to import, and every surface now explains a shortfall in the same words.

* New      - A standing advisory to rehearse the migration on a staging site or a local copy first, on the admin screen and before every migrate-all. An import cannot be undone from inside the plugin, and nothing said so before.
* New      - The plugin is translatable: it now ships a POT file and declares its Domain Path, so a translator has something to open and a site owner's .mo is actually found.
* New      - "What to import" panel on the importer screen: 12 domains, all selected by default, with parents pulled in automatically so comments can never be imported without their posts.
* New      - CLI parity for the same choice, --only and --skip on migrate-all, validated against the step registry so a typo is an error rather than a silent no-op.
* New      - A domain left out on purpose reads "skipped by choice" in verify and in the "What was imported" table, never as a shortfall.
* New      - BuddyBoss album contents are imported from the album side and keep the order the member arranged them in.
* New      - A BuddyBoss group album becomes a space-owned album in BuddyNext instead of arriving as one member's personal album.
* New      - bbPress topic tags are carried onto the topics they belong to.
* New      - rtMedia photos and videos migrate with the activity that holds them.
* New      - The source panel names content this importer cannot carry, per kind and before a run starts.
* New      - Migration checks run from the admin screen, so an owner never needs WP-CLI to verify a migration.
* New      - Blog activity migrates as BuddyNext's article type and brings its comment thread with it.
* Improve  - Skip reasons are full sentences on every surface. The admin table read "17 forbidden" where the CLI explained the same rows in full; both now read from one shared list.
* Improve  - The activity domain reports source against written plus a reason for every row it did not write, instead of a bare total.
* Improve  - migrate-all runs from the step registry like every other surface, so a domain added once is picked up everywhere.
* Improve  - Threads folded into an existing conversation are reported by migrate-all, not only by the standalone messages command.
* Improve  - verify no longer invents a shortfall for a space owner it added itself, and attributes the comment and reaction gaps it does report.
* Fix      - A fatal at the avatars step ended every browser-run import partway through.
* Fix      - A background job that died reported "running" forever; it is now noticed and restarted.
* Fix      - Source counts for forums, follows and comments disagreed with what the readers import, so a complete migration reported a gap.
* Fix      - Member cover images were written straight to user meta and did not appear.
* Fix      - An icon on the importer screen could expand to fill the whole page as a solid black shape whenever its stylesheet was unavailable or stale.
* Dev      - One source of truth for what ships, read by both the release build and CI. The lint gate previously missed the boot class and the autoloader.
* Dev      - CI runs PHP lint on 8.1 to 8.4, WPCS, and a packaging job that rejects fixture material.
* Dev      - The Docker fixture fetches its community generator on first use instead of failing mid-seed on a fresh clone.
* Dev      - reconcile reads the importer's own activity-type list and works against a BuddyBoss source.
* Compat   - Requires WordPress 6.9 and declares BuddyNext as a required plugin, so it can no longer activate where BuddyNext cannot run.

= 1.0.0 - July 2026 =

First stable release. Every domain now reports what moved and why anything did not.

* New      - wp buddynext-import verify: source relationship integrity, per-domain totals against an independent source count, and randomly sampled objects walked end to end.
* New      - "What was imported" table on the admin screen, per domain, source against written, surviving a page reload.
* New      - Content that cannot migrate is declared BEFORE a run, on the admin screen and in migrate-all, instead of being discovered afterwards.
* New      - Group types are imported as space categories, taking the first where a group carries several.
* New      - Blog-post activity is imported as link cards, built from the local post so no HTTP request is made during a migration.
* New      - Reactions on comments, not only on posts.
* New      - Exposure check: confirms no private or secret space content is publicly searchable after a migration.
* New      - Disposable Docker fixture for BuddyPress and BuddyBoss sources, with save and restore so a run starts from identical data.
* Improve  - Source table-existence checks are memoised for the request. The check guards 41 read paths, one of them inside the per-activity media loop, where it cost a SHOW TABLES per activity.
* Improve  - One shared step registry drives the CLI, the background runner, the REST endpoint and the admin page, so they cannot disagree about what a migration contains.
* Improve  - Every shortfall is attributed. A reaction on unimported activity, a post refused because its author is not a member of the space, and media with no file behind it each report their own reason.
* Fix      - "Start import" ran 5 of 16 domains and reported success. It now runs all of them.
* Fix      - Checkbox and file profile fields were mapped to types BuddyNext does not have, so their values were dropped on write.
* Fix      - Comments were lost mid-run to the comment rate limit. A bulk replay trips it by definition, so it is lifted for the duration of an import.
* Fix      - The background import stopped after its first tick, leaving the job reporting "running" and the last domains unimported. It only affected communities too large to finish inside one tick, which is the case the background runner exists for.
* Fix      - migrate-all halted partway when the member-type service was unavailable, skipping every later domain.
* Fix      - The admin page honoured the operating system's dark mode while WordPress stayed light, making the completion notice unreadable.
* Security - A group whose space failed to import had its posts republished to the global feed as public. A post that cannot be placed is now skipped, not published.
* Security - Activity the source kept out of its own feed is no longer republished. It keeps its space when it has one, and is refused when it would land in the global feed with nothing to protect it.
* Security - A blog post that is private or password protected no longer produces a link card exposing its title, excerpt and image.
* Dev      - Source adapters expose a relationship report, so referential integrity is checked before the source is blamed for a shortfall.
* Dev      - New filter buddynext_importer_tick_budget sets the per-tick wall-clock budget for the background runner, for hosts with a short max_execution_time.

== Upgrade Notice ==

= 1.1.0 =
You can now choose which domains to import. BuddyBoss album contents keep their
order, bbPress topic tags and rtMedia photos come across, and every surface
explains a shortfall in the same words. Re-running an earlier migration adds the
new domains without duplicating anything already imported.
