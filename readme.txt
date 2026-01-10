=== SafeComms Moderation ===
Contributors: safecomms
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Server-side content moderation for WordPress posts and comments using SafeComms. Scans content on publish/comment submit, holds or blocks when necessary, and offers admin tooling for overrides, logs, and retries.

== Features ==
- Auto-scan on post publish/update and comment submission (fail-closed by default).
- Retry queue with exponential backoff for API errors and rate limits.
- Caching of recent decisions to prevent duplicate scans on quick edits.
- Moderation list with filters, manual re-scan, and allow override.
- Logs page with severity/type filters.
- Admin notice for blocked items since last reset.
- Account usage tracking and plan warnings in settings.
- Shortcode `[safecomms_status post_id="123"]` to display moderation status.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/safecomms`.
2. Activate via **Plugins** in WP Admin.
3. Go to **Settings → SafeComms** to enter your API key and toggles.

== Frequently Asked Questions ==
= Does this send data client-side? =
No. Scans are performed server-side using the SafeComms API.

= What happens on API errors? =
Posts stay draft and comments are held (unless fail-open for comments is enabled). Retries are queued with backoff for posts.

== Changelog ==
= 0.1.0 =
* Initial release with auto-scan, retries, caching, moderation list, logs, and shortcode.
