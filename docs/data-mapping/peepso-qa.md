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
| Group, closed | Maple Hill Parents | Members | "Does anyone know a sitter who is free Thursday evening?" with its two photos and Jonah's and Ruby's comments. Lila's own reply ("Jonah that would be the whole evening saved") is refused by BuddyNext and named in the run report, see below |
| Group, secret | Tool Library | Members only | "The drill is back." A guest gets nothing for this space (404, not an empty public page) |
| Page | The Hearth Cafe | Anyone | "Rainy-day soup tomorrow" |
| Page | Maple Hill Library | Anyone | "Library hours this week" |
| Page | Saturday Market | Anyone | "Peonies are done" |
| Page | Birch Lane Park | Anyone | "I will be at the Birch Lane gate" |

Lila Okonkwo stays a **pending** member of Parents. Her old sitter post is still in that space. Do not expect her to be promoted to an active member. Her reply under that post does not land: BuddyNext refuses a comment from someone who cannot see the post, and a pending member of a private space cannot. The importer has no public seam around that rule, so the run reports "1 comments were refused because their author can no longer see the post they belong to".

Every space shows its PeepSo avatar and cover, the four pages included. Tool Library has none at the source. The eight groups with rules text show it on the space's About tab. The pages and Tool Library have no rules at the source.

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
- [ ] Reactions keep their type: 27 like, 8 love, 4 haha, 2 wow. PeepSo's one "grateful" has no BuddyNext match and is one of the 27 likes; the run report says so.
- [ ] Derek Olsen blocks Aisha Rahman. Derek's blocked list shows Aisha.
- [ ] The welcome post ("Welcome to Maple Hill Commons") is pinned on Varun's profile.
- [ ] Theo Marsh's post that he flagged NSFW is behind a content warning.
- [ ] The four journal Article cards carry their PeepSo dates (2026-10-18), not the time of the run.
- [ ] A profile field a member set to "only me" in PeepSo is hidden from other members. Every field on this copy is public, so test it by setting one first.
- [ ] Direct messages are conversations, not posts on the public feed. They arrive read.
- [ ] "River path" album exists and holds its photo. Opening the photo returns an image.

## Must still be absent

These are correct when they are missing. Do not file them as lost posts.

- [ ] "Ridge run, Saturday 7am" is not published. It was scheduled.
- [ ] The comment that was reported as spam is not in its thread.
- [ ] No PeepSo story row. This fixture has none.
- [ ] The three posts waiting for approval in PeepSo are not published.
- [ ] The import log names, before it says success: the vendor-list file, the background preset, the scheduled post, the 3 posts waiting for approval, the reported comment, the 8 photos on the journal cards, the 1 group follower who is not a member, the 111 post follows, the 33 view and read records, the edited stamp, the 8 unread message markers, the 8 VIP icons nobody holds, and the 1 reaction imported as a like.

## Counts from a clean run

`wp buddynext-import migrate-all --source=peepso` on this fixture reports:

- 77 profile values
- 0 member types (no member holds a VIP icon on this copy)
- 13 spaces (9 groups + 4 pages) and 96 memberships
- 36 posts
- 25 comments, and 1 refused because its author cannot see the post
- 1 block
- 43 connections (accepted pairs plus the 2 pending requests)
- 82 follows
- 41 reactions
- 13 space images (8 groups + 4 pages, each with an avatar and a cover; Tool Library has none)
- 1 album and 1 album photo
- 4 message threads

A second run of the same command writes 0 rows in every domain.

The pre-run stats `groups` figure is 13, not 9. Pages are included because they are imported as spaces.
