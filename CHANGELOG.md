# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `set_auth.sh` — CLI script to set/update admin password with automatic web-user ownership fix.
- Video media support: X videos are now downloaded and posted to Bluesky as `app.bsky.embed.video` instead of a static thumbnail image.

### Fixed
- `Auth::getPasswordHash()` no longer crashes when the hash file exists but is unreadable (permission denied). Instead it logs the error and returns an invalid hash so login fails gracefully.
- `login.php` now shows "Password file not readable — run set_auth.sh" when the hash file has permission issues.
- `api/fetch.php`: video media from X API now stores the best-quality MP4 variant URL instead of the static thumbnail.
- `api/sync.php`: media download and embed building now branch on image vs video type.

### Changed
- `Auth::getPasswordHash()` no longer crashes when the hash file exists but is unreadable (permission denied). Instead it logs the error and returns an invalid hash so login fails gracefully.
- `login.php` now shows "Password file not readable — run set_auth.sh" when the hash file has permission issues.

### Removed
- `init_auth.php` (replaced by `set_auth.sh`).

## [0.6.0] - 2026-04-27

### Fixed
- X Premium long-form tweets (>280 chars) now correctly capture full text via `note_tweet` X API field. Previously only the truncated `text` field was used, losing 95%+ of post content.
- Retweets now capture the full original tweet text from `includes.tweets` (including `note_tweet.text`), instead of the truncated RT preview text.
- Quoted tweet URL extraction no longer incorrectly matches `/photo/N` URLs that also contain `/status/`.
- Self-replies (thread continuations) are no longer skipped. Only replies to other users are filtered out.
- Bluesky `createThread()` now passes `{uri, cid}` objects for both `parent` and `root`, matching `api/sync.php` format.
- Cron/worker path (`SyncEngine`) now uses full text (`_full_text`) for processing and deduplication.

## [0.5.0] - 2026-04-25

### Fixed
- Retweet media not synced: added `referenced_tweets.id` to X API expansions.
- Archive page title unified to "Archive".

### Added
- Archive pagination settings (`history_per_page`, `history_max_pages`).
- "Sync to BSKY" button on Archive page for X-only posts.

## [0.4.0] - 2026-04-24

### Fixed
- MySQL `datetime("now")` → `NOW()` syntax errors across multiple files.
- Bluesky blob upload switched from multipart to binary format.
- Bluesky `createdAt` timezone corrected to UTC (`gmdate()`).
- X t.co short links auto-replaced with `expanded_url`.
- Bluesky session path resolved to absolute for worker context.
- `mime_content_type()` fallback for servers without fileinfo extension.

### Added
- `thread_media_position` setting (first or last post in thread).
- Detailed error info in sync results.
- Thread progress display.

## [0.3.0] - 2026-04-24

### Changed
- Migrated from SQLite to MySQL (MariaDB 10.11).
- Redesigned schema: `posts` + `post_media` + `synced_destinations`.
- Multi-platform sync support (Bluesky + personal website).

## [0.2.0] - 2026-04-24

### Added
- `fetched_posts` table for two-step fetch-then-sync flow.
- User-selectable posts to sync.
- Reply filtering via `referenced_tweets.type`.
- RT default unchecked, user can opt-in.
- Quote tweets include referenced link.

## [0.1.0] - 2026-04-24

### Added
- Basic X → Bluesky sync.
- Auto-sync all posts.
- Initial project setup.
