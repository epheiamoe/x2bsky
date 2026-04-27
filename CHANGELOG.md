# Changelog

## 2026-04-27 — Fix X long-form post content loss (note_tweet)

### Fixed
- **XApiClient**: Add `note_tweet` to `tweet.fields` in both `getUserTweets()` and `fetchUserTweets()`.  
  X Premium long-form tweets (>280 chars) expose full text only via `note_tweet.text`; the `text`
  field is always truncated.  Previously the full text was silently discarded, syncing only a few
  dozen characters of posts that may be thousands of characters long.
- **XApiClient**: For retweets, replace truncated RT text with the full original tweet text from
  `includes.tweets` (including `note_tweet.text` if present).  Store `_rt_author` separately so
  `api/fetch.php` can still record the original author.
- **XApiClient**: Exclude `/photo/` URLs when extracting `_quoted_url` from entities; previously
  `https://x.com/user/status/123/photo/1` matched `/status/` and was incorrectly used as the
  quote link.
- **XApiClient**: Allow self-replies (thread continuations) through the filter.  Replies to
  other users are still skipped, but a tweet that replies to the user's own tweet (determined
  by `author_id` from `includes.tweets`) is now treated as `original` and synced.
- **SyncEngine**: Use `_full_text` field in `enqueuePost()`, `filterNewTweets()`, and
  `processQueueItem()` so the cron/worker path also benefits from the full-text resolution.
- **BlueskyClient**: `createThread()` now passes `{uri, cid}` objects for both `parent` and
  `root` in reply references, matching the format used by `api/sync.php`.

### Added
- **TextProcessor**: Stub `validateThreadNotation()` with TODO for verifying Bluesky thread
  completeness by matching `(1/n)` notation suffixes on post text.

### Changed
- **api/fetch.php**: Prefer `_rt_author` from XApiClient's resolved data instead of parsing
  `RT @user:` from (potentially already-resolved) text.
