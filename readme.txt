=== BuddyNext Importer ===
Contributors: wbcomdesigns
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Move an existing BuddyPress or BuddyBoss community into BuddyNext, then remove this plugin.

== Description ==

A one-time migration tool. It reads a BuddyPress or BuddyBoss community directly
from the database and writes it into BuddyNext through BuddyNext's own services,
then gets deactivated and deleted.

It reports source-against-written for every domain, and gives a reason for
everything it did not write. A silent shortfall is the worst thing a migration
tool can do, because the operator deletes the old community believing everything
moved.

Domains covered: profile fields and values, profile types, space categories,
spaces and their members, activity posts and comments, connections, follows,
reactions, forums, avatars and covers, albums and media, and private messages.

Requires BuddyNext. Forums need Jetonomy; media and private messages need
WPMediaVerse. A domain whose engine is absent is skipped and said so, never
skipped silently.

== Changelog ==

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
