# PeepSo import QA

Walk this on a **copy** of Maple Hill after `wp buddynext-import migrate-all --source=peepso`. Leave `http://peepso.local` untouched and use it only as the before picture. The copy must be running the **REIGN** theme (`body` class `wp-theme-reign-theme`). BuddyNext post ids change every import, so match posts by the words below, not by id.

Sign in as Maple Hill members. The shared password is not in this repository.

A pass is the card looking like the same kind of thing it was in PeepSo. A status that became a "Media" card, a journal post that became a photo grid, or a friends-only note that a guest can read is a fail.

## Activity type

Check these on the Reign Explore feed, then open the post.

| PeepSo | Find this | BuddyNext must be | Pass |
|---|---|---|---|
| Photo post | "Welcome to Maple Hill Commons" | `photo` | Two images in the photo grid. No "Media" label. |
| Photo post | "The cherry tomatoes on the south fence" | `photo` | Three images. Thread has Priya's comment and a reply under it. |
| Uploaded video | "One second of the ridge" | `photo` | One video tile with a play control. It plays. |
| Uploaded audio | "A porch recording from the garden club" | `photo` | One audio tile. The player loads `porch-note` and plays. |
| YouTube | "River path in the rain" in **Weekend Rides** | `link` | The YouTube player (or the link card to `youtube.com/watch?v=aqz-KE-bpKQ`). Not a plain paragraph that only contains the URL. |
| Poll | "Which night works for the porch potluck?" | `poll` | Choices: Thursday after 6 (2), Friday after 6 (1), Sunday afternoon (0). Maya and June hold Thursday. Owen holds Friday. Someone who has not voted can. |
| Journal | "Soup weather at the Hearth", "The porch light on Cedar Court", "The river path after rain", "What the garden club actually does" | `article` | Article card: kicker "Article", headline, excerpt, cover, link to that WordPress post. The activity body is empty. No photo grid under the card. |
| Status | "Sharing this with the new neighbors." | `text` | A normal status. |
| Post background | "Sweet peas are on the stand." | `text` | The words are there. There is no colored background. BuddyNext has no field for one. |
| File | "Vendor list for this Saturday." | `text` | The sentence is there. The `.txt` file is not attached. |

On Explore after a scroll there should be photo tiles, one video tile, one audio tile, four Article cards, one poll, and one YouTube embed. There should be **no** bridge card labeled "Media".

## Spaces

PeepSo groups and pages both become spaces. A page is not dropped onto the main feed.

| Source | Space | Who can see it | A post that must be inside it |
|---|---|---|---|
| Group, open | Garden Club, Book Nook, Weekend Rides, Saturday Food Swap, New on the Hill, Ridge Run Club, Fix-It Bench | Anyone | Weekend Rides contains the YouTube post and "Saturday ride is the river path" |
| Group, closed | Maple Hill Parents | Members | "Does anyone know a sitter who is free Thursday evening?" with three comments, including Lila's ("Jonah that would be the whole evening saved") |
| Group, secret | Tool Library | Members only | "The drill is back." A guest gets nothing for this space (404, not an empty public page) |
| Page | The Hearth Cafe | Anyone | "Rainy-day soup tomorrow" |
| Page | Maple Hill Library | Anyone | "Library hours this week" |
| Page | Saturday Market | Anyone | "Peonies are done" |
| Page | Birch Lane Park | Anyone | "I will be at the Birch Lane gate" |

Lila Okonkwo stays a **pending** member of Parents. Her old sitter post is still in that space. Do not expect her to be promoted to an active member.

Garden Club has no stream post of its own. Its posts are sitewide. That is the source, not a missing import.

## Who can read a post

| Post | Author | Guest | A friend | Someone else signed in |
|---|---|---|---|---|
| "Friends only: the spare key" | Hannah Brooks | No | Yes (Riley Adler) | No (Luis Ortega) |
| "Note to myself: return the library book" | Riley Adler | No | No | No. Only Riley |
| "Library hold list is up" | Maya Chen | No | No | No. Only Maya. BuddyNext has no "logged-in members" audience; that choice would show the post to guests, so it stays with the author |

## People, reactions, messages

- [ ] Members from PeepSo are members here, with avatars. Avatars and covers are the WebP files under `wp-content/uploads/bn-avatars` and `bn-covers`, not MediaVerse records.
- [ ] Accepted friends are connections. Elena Vasquez's request to Theo Marsh, and Samir Patel's request to Derek Olsen, are still pending.
- [ ] Follows survived. Elena follows Luis.
- [ ] Reactions are likes. PeepSo's other reaction icons are not separate reactions.
- [ ] Direct messages are conversations, not posts on the public feed.
- [ ] "River path" album exists and holds its photo. Opening the photo returns an image.

## Must still be absent

These are correct when they are missing. Do not file them as lost posts.

- [ ] "Ridge run, Saturday 7am" is not published. It was scheduled.
- [ ] The comment that was reported as spam is not in its thread.
- [ ] No PeepSo story row. This fixture has none.
- [ ] The import log names the vendor-list file, the background preset, the block, the scheduled post, and the reported comment before it says success.

## Counts from a clean run

`wp buddynext-import migrate-all --source=peepso` on this fixture reports:

- 13 spaces (9 groups + 4 pages) and their members
- 36 posts
- 26 comments
- 43 connections (accepted pairs plus the 2 pending requests)
- 82 follows
- 41 reactions
- 1 album and 1 album photo
- 4 message threads

A second run of the same command writes 0 posts and 0 comments.

The pre-run stats `groups` figure is 13, not 9. Pages are included because they are imported as spaces.
