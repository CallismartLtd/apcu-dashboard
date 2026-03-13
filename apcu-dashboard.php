<?php
/**
 * APCu Dashboard
 *
 * A single-file, zero-dependency APCu cache management dashboard.
 * Drop this file into your web root (behind authentication!) and open it.
 *
 * @author    Smliser
 * @license   MIT
 * @link      https://github.com/smliser/apcu-dashboard
 */

declare(strict_types=1);

/* ══════════════════════════════════════════════
   0.  SECURITY — Basic HTTP auth gate (optional)
   ══════════════════════════════════════════════
   Uncomment the block below and set your own credentials,
   or protect this file via your web-server config instead.

define('APCU_DASH_USER', 'admin');
define('APCU_DASH_PASS', 'changeme');

if (!isset($_SERVER['PHP_AUTH_USER'])
    || $_SERVER['PHP_AUTH_USER'] !== APCU_DASH_USER
    || $_SERVER['PHP_AUTH_PW']   !== APCU_DASH_PASS
) {
    header('WWW-Authenticate: Basic realm="APCu Dashboard"');
    header('HTTP/1.0 401 Unauthorized');
    exit('Access denied.');
}
*/

/* ══════════════════════════════════════════════
   1.  GUARD
   ══════════════════════════════════════════════ */
if (!extension_loaded('apcu') || !apcu_enabled()) {
    http_response_code(503);
    exit('APCu is not loaded or not enabled for this SAPI.');
}

/* ══════════════════════════════════════════════
   2.  ACTIONS  (redirect after write)
   ══════════════════════════════════════════════ */
$base_url = strtok((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . ($_SERVER['REQUEST_URI'] ?? '/'), '?');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_key']) && is_string($_POST['delete_key'])) {
        apcu_delete(trim($_POST['delete_key']));
    } elseif (isset($_POST['clear_all'])) {
        apcu_clear_cache();
    }
    header('Location: ' . $base_url);
    exit;
}

/* ══════════════════════════════════════════════
   3.  DATA
   ══════════════════════════════════════════════ */
$cache_info  = apcu_cache_info(true);
$sma_info    = apcu_sma_info();
$entries     = apcu_cache_info(false)['cache_list'] ?? [];

$total_mem   = (int)($sma_info['num_seg'] * $sma_info['seg_size']);
$used_mem    = $total_mem - (int)$sma_info['avail_mem'];
$mem_pct     = $total_mem > 0 ? round(($used_mem / $total_mem) * 100, 1) : 0;
$hits        = (int)($cache_info['num_hits']   ?? 0);
$misses      = (int)($cache_info['num_misses'] ?? 0);
$num_entries = count($entries);
$uptime_secs = isset($cache_info['start_time']) ? time() - (int)$cache_info['start_time'] : 0;

/* ══════════════════════════════════════════════
   4.  HELPERS
   ══════════════════════════════════════════════ */
function fmt_bytes(int|float $b): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
    return round($b, 2) . ' ' . $units[$i];
}

function hit_rate(int $h, int $m): string {
    $t = $h + $m;
    return $t === 0 ? 'N/A' : round($h / $t * 100, 1) . '%';
}

function fmt_uptime(int $s): string {
    $d  = intdiv($s, 86400);
    $h  = intdiv($s % 86400, 3600);
    $m  = intdiv($s % 3600, 60);
    $sc = $s % 60;
    $parts = [];
    if ($d) $parts[] = "{$d}d";
    if ($h) $parts[] = "{$h}h";
    if ($m) $parts[] = "{$m}m";
    $parts[] = "{$sc}s";
    return implode(' ', $parts);
}

function nonce(): string {
    return bin2hex(random_bytes(8));
}

$csrf = hash_hmac('sha256', $base_url, php_uname());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>APCu Dashboard</title>
<style>
/* ═══════════════════════════════════════════
   RESET & TOKENS
═══════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:          #07111b;
    --surface:     #0c1a28;
    --surface-2:   #10202f;
    --border:      #172d42;
    --accent:      #5dd3a8;
    --accent-dim:  #27604a;
    --accent-glow: rgba(93,211,168,.11);
    --danger:      #f47070;
    --warning:     #f8be5a;
    --text:        #bdd0e0;
    --text-dim:    #3f5a72;
    --text-bright: #e2eef8;
    --radius:      10px;
    --mono: ui-monospace, 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
    --sans: system-ui, -apple-system, 'Segoe UI', sans-serif;
}

/* ═══════════════════════════════════════════
   BASE
═══════════════════════════════════════════ */
html { scroll-behavior: smooth; }

body {
    font-family: var(--mono);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 32px 24px 72px;
    font-size: 13px;
    line-height: 1.65;
    background-image:
        radial-gradient(ellipse 80% 40% at 50% -10%, rgba(93,211,168,.06) 0%, transparent 70%);
}

/* ═══════════════════════════════════════════
   HEADER
═══════════════════════════════════════════ */
.header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 22px;
    margin-bottom: 30px;
    border-bottom: 1px solid var(--border);
    animation: fadeDown .45s ease both;
}

.logo-row { display: flex; align-items: center; gap: 12px; }

.logo-chip {
    width: 40px; height: 40px;
    border: 1.5px solid var(--accent-dim);
    border-radius: 10px;
    background: var(--accent-glow);
    display: grid; place-items: center;
    font-size: 20px;
    flex-shrink: 0;
}

.site-title {
    font-family: var(--sans);
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -.4px;
    color: var(--text-bright);
    line-height: 1.15;
}

.site-title span { color: var(--accent); }

.site-sub {
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 2px;
}

.header-right {
    text-align: right;
    font-size: 11px;
    color: var(--text-dim);
    flex-shrink: 0;
}

.status-row {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--accent);
    font-size: 11px;
    margin-bottom: 3px;
}

.pulse-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 7px var(--accent);
    animation: pulse 2.2s ease-in-out infinite;
}

/* ═══════════════════════════════════════════
   STAT GRID
═══════════════════════════════════════════ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 28px;
}

.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    position: relative;
    overflow: hidden;
    transition: border-color .2s, box-shadow .2s;
    animation: fadeUp .45s ease both;
}

.stat-card:hover {
    border-color: var(--accent-dim);
    box-shadow: 0 0 22px var(--accent-glow);
}

.stat-card::after {
    content: '';
    position: absolute;
    inset: 0 0 auto 0;
    height: 1.5px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
    opacity: 0;
    transition: opacity .25s;
}

.stat-card:hover::after { opacity: 1; }

.stat-card:nth-child(1) { animation-delay: .05s; }
.stat-card:nth-child(2) { animation-delay: .10s; }
.stat-card:nth-child(3) { animation-delay: .15s; }
.stat-card:nth-child(4) { animation-delay: .20s; }
.stat-card:nth-child(5) { animation-delay: .25s; }
.stat-card:nth-child(6) { animation-delay: .30s; }

.stat-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 7px;
}

.stat-val {
    font-family: var(--sans);
    font-size: 26px;
    font-weight: 800;
    color: var(--text-bright);
    line-height: 1;
}

.stat-val.c-accent  { color: var(--accent); }
.stat-val.c-warning { color: var(--warning); }
.stat-val.c-danger  { color: var(--danger); }

/* Memory card */
.mem-card { grid-column: span 2; }

.mem-bar-bg {
    margin-top: 11px;
    height: 5px;
    border-radius: 3px;
    background: var(--border);
    overflow: hidden;
}

.mem-bar-fill {
    height: 100%;
    border-radius: 3px;
    background: linear-gradient(90deg, var(--accent-dim), var(--accent));
    box-shadow: 0 0 8px rgba(93,211,168,.4);
    transition: width 1.1s cubic-bezier(.22,1,.36,1);
}

.mem-bar-fill.warn {
    background: linear-gradient(90deg, #8a5c00, var(--warning));
    box-shadow: 0 0 8px rgba(248,190,90,.4);
}

.mem-bar-fill.danger {
    background: linear-gradient(90deg, #7a1e1e, var(--danger));
    box-shadow: 0 0 8px rgba(244,112,112,.4);
}

.mem-detail {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 7px;
}

/* ═══════════════════════════════════════════
   TOOLBAR
═══════════════════════════════════════════ */
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
    flex-wrap: wrap;
    animation: fadeUp .45s .3s ease both;
}

.toolbar-left { display: flex; align-items: center; gap: 10px; }

.section-title {
    font-family: var(--sans);
    font-size: 14px;
    font-weight: 700;
    color: var(--text-bright);
}

.badge {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--accent);
    font-size: 11px;
    padding: 2px 9px;
    border-radius: 20px;
}

.search-wrap { position: relative; }

.search-wrap svg {
    position: absolute;
    left: 9px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    pointer-events: none;
    width: 14px; height: 14px;
}

#search {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-family: var(--mono);
    font-size: 12px;
    padding: 7px 12px 7px 28px;
    outline: none;
    width: 220px;
    transition: border-color .2s, box-shadow .2s;
}

#search:focus {
    border-color: var(--accent-dim);
    box-shadow: 0 0 0 3px var(--accent-glow);
}

#search::placeholder { color: var(--text-dim); }

/* ═══════════════════════════════════════════
   BUTTONS
═══════════════════════════════════════════ */
.btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 14px;
    border-radius: 6px;
    font-family: var(--mono);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all .18s;
    border: 1px solid transparent;
    text-decoration: none;
    line-height: 1;
    background: none;
    user-select: none;
}

.btn-danger {
    background: rgba(244,112,112,.07);
    border-color: rgba(244,112,112,.22);
    color: var(--danger);
}

.btn-danger:hover {
    background: rgba(244,112,112,.16);
    box-shadow: 0 0 14px rgba(244,112,112,.18);
}

.btn-ghost {
    border-color: var(--border);
    color: var(--text-dim);
}

.btn-ghost:hover {
    border-color: var(--accent-dim);
    color: var(--accent);
}

/* ═══════════════════════════════════════════
   TABLE
═══════════════════════════════════════════ */
.table-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: auto;
    animation: fadeUp .45s .35s ease both;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 640px;
}

thead {
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
}

th {
    padding: 11px 14px;
    text-align: left;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-dim);
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
    transition: color .15s;
}

th:last-child { cursor: default; }
th:not(:last-child):hover { color: var(--text-bright); }
th.sorted { color: var(--accent); }

.sort-icon { margin-left: 4px; opacity: .35; font-size: 10px; transition: opacity .15s; }
th.sorted .sort-icon { opacity: 1; }

tbody tr {
    border-top: 1px solid var(--border);
    transition: background .12s;
}

tbody tr:first-child { border-top: none; }
tbody tr:hover { background: var(--surface-2); }

td { padding: 10px 14px; vertical-align: middle; }

/* Key */
.key-cell {
    font-weight: 500;
    color: var(--text-bright);
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.key-prefix { color: var(--text-dim); }

/* Hits */
.hits-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px;
    border-radius: 20px;
    font-size: 11px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
}

.hits-badge.hot {
    border-color: var(--accent-dim);
    background: var(--accent-glow);
    color: var(--accent);
}

.hits-badge.cold { color: var(--text-dim); }

/* TTL */
.ttl-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
}

.ttl-forever {
    background: rgba(93,211,168,.07);
    border: 1px solid var(--accent-dim);
    color: var(--accent);
}

.ttl-expiring {
    background: rgba(248,190,90,.07);
    border: 1px solid rgba(248,190,90,.3);
    color: var(--warning);
}

.size-cell { color: var(--text-dim); }
.date-cell { color: var(--text-dim); font-size: 12px; white-space: nowrap; }

/* Delete */
.del-btn {
    font-size: 11px;
    color: var(--text-dim);
    padding: 3px 9px;
    border-radius: 4px;
    border: 1px solid transparent;
    background: none;
    font-family: var(--mono);
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}

.del-btn:hover {
    color: var(--danger);
    border-color: rgba(244,112,112,.28);
    background: rgba(244,112,112,.07);
}

/* Empty */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-dim);
}

.empty-state .icon { font-size: 28px; margin-bottom: 12px; }

/* ═══════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════ */
.footer {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 28px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
    font-size: 11px;
    color: var(--text-dim);
    animation: fadeUp .45s .4s ease both;
}

.footer a { color: var(--text-dim); text-decoration: none; }
.footer a:hover { color: var(--accent); }

/* ═══════════════════════════════════════════
   MODAL
═══════════════════════════════════════════ */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(5px);
    z-index: 200;
    place-items: center;
}

.modal-overlay.open { display: grid; }

.modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 28px 30px;
    max-width: 340px;
    width: 90%;
    text-align: center;
    box-shadow: 0 0 60px rgba(0,0,0,.5);
    animation: popIn .25s ease both;
}

.modal h2 {
    font-family: var(--sans);
    font-size: 17px;
    font-weight: 800;
    color: var(--text-bright);
    margin-bottom: 9px;
}

.modal p {
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.7;
    margin-bottom: 22px;
}

.modal-actions { display: flex; gap: 10px; justify-content: center; }

/* ═══════════════════════════════════════════
   TOAST
═══════════════════════════════════════════ */
.toast {
    position: fixed;
    bottom: 24px; right: 24px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    color: var(--text-bright);
    font-size: 12px;
    padding: 10px 16px;
    border-radius: 6px;
    opacity: 0;
    transform: translateY(10px);
    transition: all .3s ease;
    pointer-events: none;
    z-index: 300;
    max-width: 280px;
}

.toast.show {
    opacity: 1;
    transform: translateY(0);
}

/* ═══════════════════════════════════════════
   ANIMATIONS
═══════════════════════════════════════════ */
@keyframes fadeDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: .3; }
}

@keyframes popIn {
    from { opacity: 0; transform: scale(.95); }
    to   { opacity: 1; transform: scale(1); }
}

tbody tr {
    animation: rowIn .28s ease both;
}

tbody tr:nth-child(1)   { animation-delay: .03s; }
tbody tr:nth-child(2)   { animation-delay: .06s; }
tbody tr:nth-child(3)   { animation-delay: .09s; }
tbody tr:nth-child(4)   { animation-delay: .12s; }
tbody tr:nth-child(5)   { animation-delay: .15s; }
tbody tr:nth-child(6)   { animation-delay: .18s; }
tbody tr:nth-child(7)   { animation-delay: .21s; }
tbody tr:nth-child(8)   { animation-delay: .24s; }
tbody tr:nth-child(n+9) { animation-delay: .27s; }

@keyframes rowIn {
    from { opacity: 0; transform: translateX(-5px); }
    to   { opacity: 1; transform: translateX(0); }
}

/* ═══════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════ */
@media (max-width: 600px) {
    body { padding: 18px 14px 60px; }
    .mem-card { grid-column: span 1; }
    .header { flex-direction: column; }
    .header-right { text-align: left; }
    #search { width: 160px; }
    .site-title { font-size: 18px; }
}
</style>
</head>
<body>

<!-- ═══ CLEAR MODAL ═══════════════════════════════════ -->
<div class="modal-overlay" id="clearModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal">
        <h2 id="modalTitle">Clear all cache?</h2>
        <p>This will permanently delete all <strong><?= $num_entries ?></strong>
        cached entr<?= $num_entries === 1 ? 'y' : 'ies' ?>. The action cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="closeModal()" type="button">Cancel</button>
            <form method="post" action="" style="display:inline">
                <input type="hidden" name="clear_all" value="1">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="btn btn-danger">Yes, clear all</button>
            </form>
        </div>
    </div>
</div>

<!-- ═══ TOAST ══════════════════════════════════════════ -->
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<!-- ═══ HEADER ════════════════════════════════════════ -->
<header class="header">
    <div class="logo-row">
        <div class="logo-chip" aria-hidden="true">⚡</div>
        <div>
            <div class="site-title">AP<span>Cu</span> Dashboard</div>
            <div class="site-sub">PHP <?= PHP_VERSION ?> &middot; APCu <?= phpversion('apcu') ?></div>
        </div>
    </div>
    <div class="header-right">
        <div class="status-row"><span class="pulse-dot" aria-hidden="true"></span> Cache active</div>
        <div><?= htmlspecialchars(date('D, d M Y')) ?></div>
        <div><?= htmlspecialchars(date('H:i:s')) ?></div>
    </div>
</header>

<!-- ═══ STATS ══════════════════════════════════════════ -->
<div class="stats-grid" role="list">

    <div class="stat-card" role="listitem">
        <div class="stat-label">Entries</div>
        <div class="stat-val c-accent"><?= number_format($num_entries) ?></div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Cache Hits</div>
        <div class="stat-val"><?= number_format($hits) ?></div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Cache Misses</div>
        <div class="stat-val <?= ($misses > $hits && ($hits + $misses) > 0) ? 'c-danger' : '' ?>">
            <?= number_format($misses) ?>
        </div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Hit Rate</div>
        <?php
            $hr = ($hits + $misses > 0) ? ($hits / ($hits + $misses)) : null;
            $hr_class = $hr === null ? '' : ($hr >= .7 ? 'c-accent' : ($hr >= .4 ? 'c-warning' : 'c-danger'));
        ?>
        <div class="stat-val <?= $hr_class ?>"><?= hit_rate($hits, $misses) ?></div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Uptime</div>
        <div class="stat-val" style="font-size:18px"><?= fmt_uptime($uptime_secs) ?></div>
    </div>

    <div class="stat-card mem-card" role="listitem">
        <div class="stat-label">Memory</div>
        <div class="stat-val <?= $mem_pct >= 90 ? 'c-danger' : ($mem_pct >= 70 ? 'c-warning' : 'c-accent') ?>">
            <?= $mem_pct ?>%
        </div>
        <div class="mem-bar-bg" role="progressbar" aria-valuenow="<?= $mem_pct ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="mem-bar-fill <?= $mem_pct >= 90 ? 'danger' : ($mem_pct >= 70 ? 'warn' : '') ?>"
                 style="width:<?= $mem_pct ?>%"></div>
        </div>
        <div class="mem-detail">
            <span>Used: <?= fmt_bytes($used_mem) ?></span>
            <span>Free: <?= fmt_bytes((int)$sma_info['avail_mem']) ?></span>
            <span>Total: <?= fmt_bytes($total_mem) ?></span>
        </div>
    </div>

</div>

<!-- ═══ TOOLBAR ════════════════════════════════════════ -->
<div class="toolbar">
    <div class="toolbar-left">
        <span class="section-title">Cache Entries</span>
        <span class="badge" id="visibleCount"><?= $num_entries ?></span>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <div class="search-wrap">
            <!-- Inline SVG search icon — no CDN needed -->
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <circle cx="6.5" cy="6.5" r="4.5"/>
                <line x1="10.5" y1="10.5" x2="14" y2="14"/>
            </svg>
            <input type="search" id="search" placeholder="Filter by key…"
                   oninput="filterTable(this.value)"
                   aria-label="Filter cache entries by key">
        </div>
        <?php if ($num_entries > 0): ?>
        <button class="btn btn-danger" onclick="openClearModal()" type="button" aria-haspopup="dialog">
            &#8960; Clear Cache
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ TABLE ══════════════════════════════════════════ -->
<div class="table-wrap" role="region" aria-label="Cache entries">
<table id="cacheTable">
    <thead>
    <tr>
        <th onclick="sortTable(0)" id="th-0" aria-sort="none">Key <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(1)" id="th-1" aria-sort="none">Hits <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(2)" id="th-2" aria-sort="none">Size <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(3)" id="th-3" aria-sort="none">TTL <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(4)" id="th-4" aria-sort="none">Created <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody id="tableBody">
    <?php if (empty($entries)): ?>
        <tr>
            <td colspan="6">
                <div class="empty-state">
                    <div class="icon">◎</div>
                    <p>No cache entries yet.</p>
                </div>
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($entries as $entry):
            $key      = (string)($entry['info'] ?? '');
            $hits_val = (int)($entry['num_hits'] ?? 0);
            $size_val = (int)($entry['mem_size'] ?? 0);
            $ttl_val  = (int)($entry['ttl'] ?? 0);
            $created  = (int)($entry['creation_time'] ?? 0);
            $is_hot   = $hits_val >= 10;
            /* Split key at last dot for prefix dimming */
            $dot_pos  = strrpos($key, '.');
            $prefix   = $dot_pos !== false ? substr($key, 0, $dot_pos + 1) : '';
            $suffix   = $dot_pos !== false ? substr($key, $dot_pos + 1) : $key;
        ?>
        <tr>
            <td class="key-cell" title="<?= htmlspecialchars($key) ?>">
                <?php if ($prefix): ?>
                    <span class="key-prefix"><?= htmlspecialchars($prefix) ?></span><?= htmlspecialchars($suffix) ?>
                <?php else: ?>
                    <?= htmlspecialchars($key) ?>
                <?php endif; ?>
            </td>
            <td>
                <span class="hits-badge <?= $is_hot ? 'hot' : ($hits_val === 0 ? 'cold' : '') ?>">
                    <?= $is_hot ? '🔥 ' : '' ?><?= number_format($hits_val) ?>
                </span>
            </td>
            <td class="size-cell" data-bytes="<?= $size_val ?>"><?= fmt_bytes($size_val) ?></td>
            <td>
                <?php if ($ttl_val === 0): ?>
                    <span class="ttl-pill ttl-forever">&#8734; forever</span>
                <?php else: ?>
                    <span class="ttl-pill ttl-expiring"><?= number_format($ttl_val) ?>s</span>
                <?php endif; ?>
            </td>
            <td class="date-cell" data-ts="<?= $created ?>"><?= date('Y-m-d H:i:s', $created) ?></td>
            <td>
                <form method="post" action="" onsubmit="return confirmDelete(<?= htmlspecialchars(json_encode($key)) ?>)">
                    <input type="hidden" name="delete_key" value="<?= htmlspecialchars($key) ?>">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit" class="del-btn" aria-label="Delete <?= htmlspecialchars($key) ?>">
                        &#10005; delete
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- ═══ FOOTER ════════════════════════════════════════ -->
<footer class="footer">
    <span>APCu <?= phpversion('apcu') ?> &middot; PHP <?= PHP_VERSION ?> &middot; <?= php_uname('n') ?></span>
    <span>
        Uptime: <?= fmt_uptime($uptime_secs) ?> &middot;
        <a href="https://github.com/smliser/apcu-dashboard" target="_blank" rel="noopener">APCu Dashboard</a>
    </span>
</footer>

<!-- ═══ JAVASCRIPT ════════════════════════════════════ -->
<script>
'use strict';

/* ── Sort ─────────────────────────────────────────── */
let _sortCol = -1, _sortAsc = true;

function sortTable(col) {
    const tbody = document.getElementById('tableBody');
    const rows  = [...tbody.querySelectorAll('tr')].filter(r => r.querySelector('td[colspan]') === null);
    const ths   = document.querySelectorAll('thead th');

    if (_sortCol === col) { _sortAsc = !_sortAsc; }
    else { _sortCol = col; _sortAsc = true; }

    ths.forEach((th, i) => {
        th.classList.toggle('sorted', i === col);
        const ic = th.querySelector('.sort-icon');
        if (ic) ic.textContent = i === col ? (_sortAsc ? '↑' : '↓') : '↕';
        th.setAttribute('aria-sort', i === col ? (_sortAsc ? 'ascending' : 'descending') : 'none');
    });

    rows.sort((a, b) => {
        const ca = a.querySelectorAll('td')[col];
        const cb = b.querySelectorAll('td')[col];
        if (!ca || !cb) return 0;

        // Prefer numeric data attributes, then text content
        const va = ca.dataset.bytes ?? ca.dataset.ts ?? ca.textContent.trim();
        const vb = cb.dataset.bytes ?? cb.dataset.ts ?? cb.textContent.trim();
        const na = parseFloat(va), nb = parseFloat(vb);

        if (!isNaN(na) && !isNaN(nb)) return _sortAsc ? na - nb : nb - na;
        return _sortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
    });

    rows.forEach(r => tbody.appendChild(r));
    updateCount();
}

/* ── Filter ───────────────────────────────────────── */
function filterTable(q) {
    const term = q.trim().toLowerCase();
    document.querySelectorAll('#tableBody tr').forEach(row => {
        const kc = row.querySelector('.key-cell');
        row.hidden = kc ? !kc.textContent.toLowerCase().includes(term) : false;
    });
    updateCount();
}

function updateCount() {
    const n = [...document.querySelectorAll('#tableBody tr')].filter(r => !r.hidden && !r.querySelector('td[colspan]')).length;
    document.getElementById('visibleCount').textContent = n;
}

/* ── Modal ────────────────────────────────────────── */
function openClearModal() {
    const m = document.getElementById('clearModal');
    m.classList.add('open');
    m.querySelector('.btn-ghost').focus();
}

function closeModal() {
    document.getElementById('clearModal').classList.remove('open');
}

document.getElementById('clearModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});

/* ── Delete confirm ───────────────────────────────── */
function confirmDelete(key) {
    return window.confirm('Delete cache key?\n\n' + key);
}

/* ── Toast ────────────────────────────────────────── */
function showToast(msg, duration = 2800) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), duration);
}

/* Show toast if we just performed an action (via URL hash set by server, or query param) */
(function () {
    const p = new URLSearchParams(window.location.search);
    if (p.has('_cleared'))  { showToast('Cache cleared successfully.'); }
    if (p.has('_deleted'))  { showToast('Cache entry deleted.'); }
})();
</script>
</body>
</html>
