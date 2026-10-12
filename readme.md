# Deploy Restarter

A WordPress plugin that exposes a secure REST API endpoint to restart
(deactivate + reactivate) another plugin after its files have been replaced
over FTP/SFTP.

**Author:** Shahab Fatemi
**Website:** [shahabfatemi.ir](https://shahabfatemi.ir)

---

## What it does

When you upload a plugin update over FTP, WordPress keeps running the old
version until the plugin is deactivated and reactivated. This plugin gives you
a single REST endpoint you can call from your deployment script to do exactly
that — safely, with an API key, IP allow-list, and rate limiting.

After a restart it can also:

- Purge the WordPress object cache
- Reset PHP OPcache (if available)

---

## Requirements

- WordPress 5.6 or newer
- PHP 7.2 or newer
- HTTPS on your site (strongly recommended)

---

## Installation

1. Download or clone this repository.
2. Copy the `deploy-restarter` folder into `wp-content/plugins/`.
3. In WordPress admin, go to **Plugins** and activate **Deploy Restarter**.
4. Go to **Settings → Deploy Restarter** and copy your API key.

> The API key is generated automatically the first time you activate the plugin.

---

## Configuration

Open **Settings → Deploy Restarter** in WordPress admin. Available options:

| Setting | Default | Description |
|---|---|---|
| Enable REST endpoint | On | Turn the API on or off. |
| API key | auto-generated | Sent in the `X-API-Key` header. |
| Allowed plugins | empty | Optional whitelist. Empty = all plugins. |
| IP allow-list | empty | IPs or CIDR ranges. Empty = any IP. |
| Trust proxy headers | Off | Read client IP from Cloudflare / reverse proxy. |
| Rate limit | 10 / minute | Requests per minute per IP. `0` disables. |
| Purge object cache | On | Runs `wp_cache_flush()` after restart. |
| Reset OPcache | On | Runs `opcache_reset()` if available. |
| Activity log | On | Keeps the last 200 requests. |
| Log retention | 30 days | How long log entries are kept. |

---

## API usage

### Endpoint
