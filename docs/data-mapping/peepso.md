# PeepSo adapter

The row-by-row checklist, with fixture ids, is [peepso-checklist.md](peepso-checklist.md). The walkthrough for QA is [peepso-qa.md](peepso-qa.md).

`Source\PeepSo\PeepSoAdapter` is the reader. It is registered as `peepso` and fills the same writers BuddyPress and BuddyBoss use. A PeepSo-only site is detected on its own, so `wp buddynext-import stats` does not need `--source`.

Rehearse on a **copy** of Maple Hill (`http://peepso.local`). The importer writes into the site it is activated on, and it cannot be undone from inside the plugin. Leave the original site alone. Do not re-run its seed.

```bash
wp buddynext-import stats
wp buddynext-import migrate-all
wp buddynext-import verify
```

On the fixture the reader reports 9 groups (267 hidden, 156 private), 43 friendships including 2 pending, the reply under comment activity 125, and post activity 200 inside group 267 with `hide_sitewide`. A guest search must not find that post after the run.

`unsupported_content()` is printed before the run. On this fixture that list is the file attachment (the sentence still imports), the background preset (the words still import), 1 block, 1 scheduled post, and 1 reported comment. Polls, audio, pages, and journal posts do import. Members-only posts are stored as private, because a members-only value would otherwise be published to guests. Walk [peepso-qa.md](peepso-qa.md) after a run. The spot-check table in the checklist is older than the reader.

Photos are files in `wp-content/peepso/`, not attachments. The writers accept a path for that case. Stream photos ride their post. Album 40 is filed after the post that already holds its photo.
