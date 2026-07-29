# Migration fixture (Docker)

A disposable WordPress with a real BuddyPress community in it, so the importer
can be run against source data and every domain reconciled source-vs-destination.

```bash
./run.sh            # seed source -> migrate -> reconcile
./run.sh migrate    # re-run migrate + reconcile against the existing fixture
./run.sh shell      # wp-cli shell inside the container
./run.sh down       # destroy it
```

Nothing here touches a Local site, and the database lives in tmpfs, so `down`
leaves no state behind.

## Why a fixture, not a Local site

`buddynext.local` is the migration TARGET: it has no BuddyPress and no `wp_bp_*`
tables, so it can prove the registry, the REST dispatch and the field-type map,
but it can never prove a migration moved the rows. That gap is what let a
"Start import" that migrated 5 of 16 domains report success.

## What the fixture deliberately contains

The seed is adversarial on purpose - a fixture with only the happy path proves
only the happy path:

| Fixture row | Exercises |
|---|---|
| a `checkbox` xProfile field with multi-values | BP checkbox -> BN `multiselect`; the 0/25 loss |
| a group slugged `joined` | `SpaceService::RESERVED_SLUGS` - `unique_slug()` does not check it, so `create()` refuses the space |
| activities inside private + hidden groups | a group post must never land at `space_id = 0` (the global feed) |
| comments on an `activity_update` root | must all migrate |
| comments on a `new_member` root | the posts query only takes `activity_update`, so these are dropped uncounted |
| a comment on a comment | nesting must survive, not flatten |
| `bp_favorite_activities` usermeta | the ReactionImporter fallback path |

## Reading the output

`reconcile.php` counts the source and the destination independently. The
importer's own "N imported" line can only ever prove what it wrote - it says
nothing about what it silently declined to write, which is the entire bug class
here.

Two rows matter most:

- **`of which importable` vs `bn_comments`** must match EXACTLY. A gap there is a
  comment lost for a reason other than a missing root.
- **the PRIVACY block** is the only check that looks at placement rather than
  volume. A group post republished to the global feed does not change any total,
  so no count above can catch it.
