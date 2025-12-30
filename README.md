# SafeComms Moderation (WordPress)

Server-side moderation for posts and comments using SafeComms.

SafeComms is a powerful content moderation platform designed to keep your digital communities safe. It provides real-time analysis of text to detect and filter harmful content, including hate speech, harassment, and spam.

## Requirements
- PHP 8.0+
- WordPress 5.8+ (Tested up to 6.9)

## Installation
1. Place the plugin directory in `wp-content/plugins/safecomms`.
2. Activate the plugin from **Plugins** in WP Admin (tables are auto-created).

## Configuration
- Go to **Settings → SafeComms** (or **SafeComms → Settings**) and add your API key.
- Toggle scanning for posts/comments, auto-scan, admin notices, and fail-open for comments if desired.
- Retry/backoff and cache TTL can be tuned in settings.

## Features
- Auto-scan on post publish/update and on comment submission (fail-closed by default).
- **Text Replacement**: Automatically rewrite unsafe content with safe alternatives (requires Starter tier+).
- **PII Redaction**: Automatically redact sensitive information (requires Starter tier+).
- **Non-English Support**: Support for scanning content in languages other than English (requires Pro tier+).
- Retry queue with exponential backoff on API errors/429.
- Caching of recent decisions to avoid duplicate scans on minor edits.
- Moderation list with filters, manual re-scan, and override to allow.
- Logs page with severity filters.
- Admin notice for blocked-count since last reset.
- Shortcode `[safecomms_status post_id="123"]` to display moderation status.

## Manual Actions
- From **SafeComms → Moderation**, use row/bulk actions to re-scan or mark allowed.
- Overrides update post/comment status/meta and are logged.

## Notes
- API key is never rendered in clear text after save.
- On comment rate-limit/error, comments are held unless fail-open is enabled.