# ⚡ APCu Dashboard

A modern, single-file PHP dashboard for managing your APCu in-memory cache.  
No Composer. No npm. No build step. No external requests. Drop it in and go.

> **Why does this exist?**  
> The official APCu repository ships an `apc.php` management script, but it is a
> largely unchanged carry-over from the APC era — a wall of tables and raw PHP with
> no authentication, no modern UI, and no Ajax. It gets the job done in a pinch, but
> it is not something you would comfortably leave running on a production server or
> hand to a team-mate. This project is a ground-up replacement: a single file you can
> drop in, lock down in two lines, and actually enjoy using.

---

## Screenshot

![screenshot](apcu-dashboard.png)

---

## Features

| Feature | Details |
|---|---|
| **Zero dependencies** | Pure PHP + vanilla JS + inline CSS. No Composer, npm, CDN, or network requests whatsoever. |
| **Single file** | Everything — PHP logic, HTML, CSS, JS — lives in one `apcu-dashboard.php` file you can `wget` and drop anywhere. |
| **Ajax operations** | All delete and clear actions run via `fetch()` with no page reload. A spinner appears in the button while the request is in flight, and the stats and table update instantly when it completes. |
| **Live UI** | The header clock, uptime counter, and "Cache active" pulse all tick every second client-side. The dashboard feels alive, not stale. |
| **Built-in login system** | Change `APCU_DASH_USER` and `APCU_DASH_PASS` from the defaults to instantly enforce a session-based login wall. No code to uncomment — changing the constants *is* the switch. |
| **Brute-force protection** | Five consecutive failed login attempts trigger a 5-minute IP lockout, tracked in APCu itself. Remaining attempts and lockout countdown are shown to the user. |
| **At-a-glance stats** | Entry count, total hits/misses, hit-rate with colour coding, memory usage bar with used/free/total breakdown, and cache uptime. |
| **Entry table** | Full list of all cache keys with per-key hit count, memory size, TTL (forever or countdown in seconds), and creation timestamp. |
| **Sort** | Click any column header to sort ascending/descending. Numeric columns (hits, size, TTL, timestamp) sort numerically. |
| **Live filter** | Type in the search box to instantly filter by key name — no page reload. |
| **Delete single key** | Per-row delete button opens a styled confirmation modal. Confirmed deletes fire via Ajax — no page reload, no browser `alert()`. |
| **Clear all cache** | "Clear Cache" button opens a modal that shows the exact entry count before asking for confirmation. Also runs via Ajax. |
| **CSRF protection** | Every write request includes a per-installation CSRF token derived with `hash_hmac`. |
| **Key prefix dimming** | For dot-namespaced keys (e.g. `wordpress.options.home`) the prefix is visually dimmed so the meaningful suffix stands out. |
| **Hot key indicator** | Keys with 10+ hits are highlighted with 🔥 and an accent colour. |
| **Memory colour coding** | Memory bar turns amber at ≥ 70 % and red at ≥ 90 %. |
| **Accessibility** | ARIA roles, `aria-sort` on sortable columns, `aria-label` on icon-only buttons, `role="progressbar"` on the memory bar. |
| **Responsive** | Collapses gracefully on narrow screens. Table scrolls horizontally before breaking layout. |
| **No external fonts** | Uses system-default monospace and sans-serif font stacks (`ui-monospace`, `system-ui`, …). Looks great on every OS. |

---

## Requirements

| | |
|---|---|
| **PHP** | 8.0 or later (uses union types — `int\|float`) |
| **APCu extension** | `php-apcu` installed and enabled for the correct SAPI (CLI vs FPM vs mod_php — see FAQ) |
| **Web server** | Apache, Nginx, Caddy, or anything else that can serve `.php` files |

---

## Quick Start

### 1. Download

```bash
# wget
wget -O apcu-dashboard.php https://raw.githubusercontent.com/CallismartLtd/apcu-dashboard/main/apcu-dashboard.php

# or curl
curl -o apcu-dashboard.php https://raw.githubusercontent.com/CallismartLtd/apcu-dashboard/main/apcu-dashboard.php
```

### 2. Place the file

Put `apcu-dashboard.php` somewhere your web server can execute PHP. The web root is fine for a quick check; for long-term use see the Security section below.

### 3. Open it

```
https://yoursite.com/apcu-dashboard.php
```

That's it.

---

## Security

> **This file exposes the full contents of your APCu cache and lets anyone who can reach it delete entries or wipe the whole cache.**  
> **Never leave it publicly accessible.**

### Option A — Built-in login system (recommended)

Edit the two constants at the top of the file:

```php
define( 'APCU_DASH_USER', 'your-username' );
define( 'APCU_DASH_PASS', 'a-long-random-password' );
```

As soon as both values differ from the defaults (`admin` / `changeme`), the dashboard enforces a full session-based login page. The session cookie is `HttpOnly`, `SameSite=Strict`, and `Secure` on HTTPS.

> Use this only over HTTPS — credentials are sent as a form POST, which is trivially intercepted over plain HTTP.

### Option B — Protect via web server

**Nginx:**
```nginx
location = /apcu-dashboard.php {
    allow 203.0.113.42;   # your IP
    deny  all;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

**Apache `.htaccess`:**
```apache
<Files "apcu-dashboard.php">
    Require ip 203.0.113.42
</Files>
```

### Option C — Keep it out of the web root entirely

Place the file above the document root and alias it with restricted access.

---

## Configuration

All configuration is at the top of `apcu-dashboard.php`:

```php
define( 'APCU_DASH_USER', 'admin' );    // change to enable the login wall
define( 'APCU_DASH_PASS', 'changeme' ); // change to enable the login wall
```

There is no config file, no database, and no installation step.

---

## How It Works

### PHP side

1. On every request the script calls `apcu_cache_info(true)` (stats, no entry data) and `apcu_sma_info()` (memory). Both are cheap shared-memory reads.
2. For the entry table it calls `apcu_cache_info(false)` to get the full `cache_list` array, which is serialised into the initial HTML and also available via the Ajax endpoint.
3. When the request carries `X-Requested-With: XMLHttpRequest`, the script returns `application/json` instead of HTML. Write actions (delete/clear) also respond with a fresh snapshot of stats and entries so the UI can update immediately without a second round-trip.
4. The CSRF token is `hash_hmac('sha256', $current_url, php_uname())`. It is deterministic per machine (no session required) but not guessable by an attacker on a different server.
5. Login state is maintained with PHP sessions. The session stores a `hash_hmac`-signed token rather than a plain boolean, so a valid session from one installation cannot be replayed against another.

### JavaScript side

- **Ajax:** All write operations use `fetch()` with `X-Requested-With: XMLHttpRequest`. The response JSON is used to re-render the stat cards and table in-place — no page reload occurs.
- **Spinner:** Each button has a CSS-only spinner element that is shown while a request is in flight and the button is disabled.
- **Live clock & uptime:** A `setInterval` tick runs every second. The clock is computed client-side from `new Date()`. Uptime is computed by adding elapsed client seconds to the server-rendered initial value — so the counter continues smoothly without any polling.
- **Table renderer:** After every write, `_render_table()` rebuilds `<tbody>` from the fresh JSON entries array, keeping the displayed data consistent with the server state.
- **Sort:** Rows are extracted from `<tbody>`, sorted in-memory with `Array.prototype.sort`, then appended back. Numeric columns use `data-bytes` / `data-ts` attributes so sort order matches the underlying number, not the formatted string.
- **Filter:** Hides rows using `row.hidden` — no `display` style manipulation needed. Filter is re-applied after every table re-render.
- **Modal:** A `<div>` overlay toggled with a CSS class. Holds `_pending_action` — the params for the confirmed request — so the single confirm button works for both delete and clear operations. Closes on backdrop click or Escape key.

---

## FAQ

### APCu is installed but the page just shows "APCu is not loaded or not enabled for this SAPI"

APCu has separate enable flags for CLI and web (FPM/mod_php). Check:

```bash
# FPM / mod_php
php -r "echo ini_get('apc.enabled');"   # should print 1

# Verify in PHP info
php -i | grep -i apcu
```

Also make sure `extension=apcu` is present in the correct `.ini` file for your web SAPI (often `/etc/php/8.x/fpm/conf.d/` rather than `/etc/php/8.x/cli/conf.d/`).

### How do I enable APCu for PHP-FPM?

```bash
# Debian / Ubuntu
sudo apt install php-apcu

# Then make sure the config is in the fpm conf.d
ls /etc/php/8.3/fpm/conf.d/ | grep apcu

# Reload
sudo systemctl reload php8.3-fpm
```

### Does this work with PHP OPcache?

Yes. APCu and OPcache are completely separate extensions. OPcache caches compiled bytecode; APCu caches arbitrary PHP values in shared memory. They coexist happily.

### The entry list is huge — can I paginate it?

Not yet. Pagination is on the roadmap. As a workaround, use the live filter box to narrow down to the keys you care about. PRs welcome!

### Can I run this on the CLI to inspect cache from the terminal?

No. CLI PHP uses a different shared-memory segment from web SAPI processes. Cache set by your web application is only visible from another web SAPI request (FPM worker, Apache mod_php, etc.).

### I deleted a key but it came back immediately

Your application re-populated the cache. The dashboard only deletes; it can't prevent your app from caching the value again.

### The delete/clear buttons don't do anything

JavaScript must be enabled. The dashboard degrades gracefully to a full-page POST/redirect/GET flow as a fallback, but the Ajax path requires JS.

### What PHP versions are supported?

**PHP 8.0 or later.** The file uses union types (`int|float`) introduced in PHP 8.0. If you need PHP 7.4 compatibility, change the `int|float` hint in `fmt_bytes()` to just `float`.

---

## Roadmap

- [ ] Pagination for large entry lists
- [ ] Export cache key list as CSV
- [ ] Dark / light mode toggle
- [ ] Per-key value inspector (decoded JSON / serialized data)
- [ ] Optional auto-refresh interval

---

## Contributing

Bug reports, feature requests and pull requests are all welcome.

1. Fork the repo
2. Make your changes to `apcu-dashboard.php`
3. Open a pull request with a clear description

Please keep the **single-file constraint** — no build step, no `node_modules`, no `vendor/`.

---

## License

MIT License

Copyright (c) 2026 Callistus Nwachukwu

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
