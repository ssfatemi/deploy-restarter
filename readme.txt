=== Deploy Restarter ===
Contributors: your-wp-username
Donate link: shahabfatemi.ir
Tags: deploy, restart, plugin, ftp, api
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely restart a plugin after its files have been replaced over FTP/SFTP.

== Description ==

Deploy Restarter provides a secure REST API endpoint that deactivates and
reactivates a WordPress plugin. It is designed for developers who deploy
plugins over FTP and need to clear caches and trigger activation hooks
without manually clicking anything in the admin.

= Features =

* Secure REST endpoint protected by an API key
* IP allow-list with CIDR support
* Per-IP rate limiting
* Optional object-cache purge and OPcache reset
* Activity log with retention settings

= Usage =

Send a POST request to:

https://your-site.com/wp-json/deploy-restarter/v1/restart

With the header `X-API-Key: YOUR_KEY` and this JSON body:

{"plugin": "your-plugin-slug"}

== Installation ==

1. Upload the `deploy-restarter` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to Settings → Deploy Restarter to configure the API key and options.

== Frequently Asked Questions ==

= How do I get the API key? =

It is generated automatically when you activate the plugin. You can view or
regenerate it in Settings → Deploy Restarter.

= Is the API secure? =

Yes. It uses a 48-character API key, supports an IP allow-list, and enforces
rate limiting. Always use HTTPS.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
First release.