# BuddyNext Importer

Migrate an existing WordPress community into [BuddyNext](https://github.com/buddynext/buddynext) - members, profile fields, groups/spaces, and the activity stream - from any of the major community platforms.

> One-time transition tool. Install it, run the migration, then remove it. BuddyNext core never carries migration code.

## Supported sources

- **BuddyPress**
- **BuddyBoss Platform**
- **FluentCommunity**
- **PeepSo**
- **Ultimate Member**

## How it works

A source-adapter architecture. Each platform has a read-only **adapter** that reads its own database tables and normalizes records to one common shape. A single **writer** then creates them in BuddyNext **through the BuddyNext service layer** (never raw SQL into `bn_*` tables), so denormalized counters, the search index, hashtags, mentions, and privacy/role rules all stay correct.

```
BuddyPress / BuddyBoss / FluentCommunity / PeepSo / Ultimate Member
        |  (per-source read adapter)
        v
   common record shape  ->  BuddyNext services (write)  ->  bn_* tables
```

### Built for scale and safety

- **Import mode** suppresses side effects for the duration of the run - no notifications, emails, webhooks, or real-time pushes are fired for imported content (so a 50k-activity import never spams every member).
- **Deferred recounts** - counters are recomputed once in bulk at the end, not per row.
- **Resumable and idempotent** - a `source-id -> buddynext-id` map lets a large import resume after a failure and never double-import. Runs via WP-CLI in keyset-paginated batches, not a single web request.

## Migration domains (per source, where the platform supports it)

| Domain | BuddyNext target |
|---|---|
| Members + profile fields | `bn_profile_fields` / `bn_profile_values` |
| Groups | `bn_spaces` + `bn_space_members` (privacy + role mapping) |
| Activity + comments | `bn_posts` + `bn_comments` |
| Friendships / connections | `bn_connections` |
| Private messages | WPMediaVerse DM engine |
| Avatars + cover images | BuddyNext member media |

Notifications are intentionally not imported (they are transient). Old permalinks can be mapped to BuddyNext routes for SEO continuity.

## Lifecycle

This is a one-time transition utility. It is offered as a 1-click companion install from inside BuddyNext when a source platform is detected, run once, then deactivated and deleted - keeping BuddyNext core lean.

## Requirements

- BuddyNext active
- PHP 8.1+
- WordPress 6.6+

## Status

Planning / early development. Architecture and v1 scope are being defined; the first domain to land end-to-end is profile fields, then groups/spaces, then activity.

## License

GPL-2.0-or-later. Part of the [BuddyNext](https://github.com/buddynext) project by [Wbcom Designs](https://wbcomdesigns.com).
