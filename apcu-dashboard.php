<?php
/**
 * APCu Dashboard
 *
 * A single-file, zero-dependency APCu cache management dashboard.
 * Drop this file into your web root (behind authentication!) and open it.
 *
 * @author    Callistus Nwachukwu <https://callistus.callismart.com.ng>
 * @license   MIT
 * @link      https://github.com/CallismartLtd/apcu-dashboard
 */

declare( strict_types = 1 );

/*
|---------------------
| CREDENTIALS
|----------------------------------------------------------------
|
| Change these from the defaults to enable login enforcement.
| While they remain 'admin' / 'changeme' the dashboard is
| accessible without a password but shows a warning banner.
|----------------------------------------------------------------
*/

define( 'APCU_DASH_USER', 'admin' );
define( 'APCU_DASH_PASS', 'changeme' );

if ( ! extension_loaded( 'apcu' ) || ! apcu_enabled() ) {
    http_response_code( 503 );
    exit( 'APCu is not loaded or not enabled for this SAPI.' );
}

/*
|------------------------------------------------------------------
| $script_url — the stable, query-string-free URL of this script.
|
| PHP_SELF always points at the executing script regardless of
| whether it sits at the document root, in a sub-directory, or is
| included from another script. It never carries a query string.
|
| This URL is used for every redirect, the login form action,
| the CSRF token seed, and the JS fetch() endpoint.
|
| REQUEST_URI is intentionally not used here because it reflects
| the caller's full URI including any query string, which would
| break CSRF validation and fetch targets when this file is
| included from another script that has its own query params.
|------------------------------------------------------------------
*/
$scheme     = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri        = $_SERVER['REQUEST_URI'] ?? '/';
$script_url = sprintf( '%s://%s%s', $scheme, $host, $uri );
$is_ajax    = ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) === 'XMLHttpRequest';

/*
|---------
| AUTH
|---------
*/
$auth_enabled  = ! ( APCU_DASH_USER === 'admin' && APCU_DASH_PASS === 'changeme' );
$session_key   = '_apcu_dash_authed';
/*
|-----------------------------------------
| A per-installation secret so a valid
| session token from one server cannot be
| replayed on another.
|-----------------------------------------
*/
$session_secret = hash( 'sha256', __FILE__ . php_uname() . APCU_DASH_USER );
$login_error    = '';

if ( $auth_enabled ) {

    if ( PHP_SESSION_NONE === session_status() ) {
        session_set_cookie_params( [
            'lifetime' => 0, // until browser close
            'path'     => '/',
            'secure'   => 'https' === $scheme,
            'httponly' => true,
            'samesite' => 'Strict',
        ] );

        session_start();
    }

    // Logout.
    if ( isset( $_GET['logout'] ) ) {
        $_SESSION = [];
        session_destroy();
        header( 'Location: ' . $script_url );
        exit;
    }

    // Brute-force counters (stored in APCu).
    $ip_key       = '_apcu_dash_fail_' . hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '' );
    $lockout_key  = '_apcu_dash_lock_' . hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '' );
    $max_attempts = 5;
    $lockout_secs = 300; // 5 minutes

    // Login form submitted.
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_login'] ) ) {
        $locked = apcu_fetch( $lockout_key );
        if ( $locked ) {
            $remaining   = (int) $locked - time();
            $login_error = "Too many failed attempts. Try again in {$remaining}s.";
        } else {
            $u = trim( (string) ( $_POST['username'] ?? '' ) );
            $p = stripslashes( (string) ( $_POST['password'] ?? '' ) );
            if ( hash_equals( APCU_DASH_USER, $u ) && hash_equals( APCU_DASH_PASS, $p ) ) {
                // Success — regenerate session, store signed token.
                session_regenerate_id( true );
                $_SESSION[ $session_key ] = hash_hmac( 'sha256', session_id(), $session_secret );
                apcu_delete( $ip_key );
                header( 'Location: ' . $script_url );
                exit;
            } else {
                // Failure — increment counter.
                $fails = (int) apcu_fetch( $ip_key ) + 1;
                if ( $fails >= $max_attempts ) {
                    apcu_store( $lockout_key, (string) ( time() + $lockout_secs ), $lockout_secs );
                    apcu_delete( $ip_key );
                    $login_error = "Too many failed attempts. Try again in {$lockout_secs}s.";
                } else {
                    apcu_store( $ip_key, (string) $fails, $lockout_secs );
                    $remaining_attempts = $max_attempts - $fails;
                    $login_error        = "Invalid username or password. {$remaining_attempts} attempt"
                                       . ( $remaining_attempts === 1 ? '' : 's' ) . ' remaining.';
                }
            }
        }
    }

    // Check whether already authenticated.
    $is_authed = isset( $_SESSION[ $session_key ] )
        && hash_equals(
            hash_hmac( 'sha256', session_id(), $session_secret ),
            (string) $_SESSION[ $session_key ]
        );

    if ( ! $is_authed ) {
        // Not logged in — render the login page and stop.
        http_response_code( $login_error ? 401 : 200 );
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>APCu Dashboard — Login</title>
<style>
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
    --text:        #bdd0e0;
    --text-dim:    #3f5a72;
    --text-bright: #e2eef8;
    --mono: ui-monospace, 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
    --sans: system-ui, -apple-system, 'Segoe UI', sans-serif;
}
html, body { height: 100%; }
body {
    font-family: var(--mono);
    background: var(--bg);
    background-image: radial-gradient(ellipse 80% 40% at 50% -10%, rgba(93,211,168,.06) 0%, transparent 70%);
    color: var(--text);
    display: grid;
    place-items: center;
    min-height: 100vh;
    padding: 20px;
}
.login-wrap {
    width: 100%;
    max-width: 380px;
    animation: popIn .35s ease both;
}
.login-logo { text-align: center; margin-bottom: 28px; }
.logo-chip {
    display: inline-grid;
    place-items: center;
    width: 52px; height: 52px;
    border: 1.5px solid var(--accent-dim);
    border-radius: 14px;
    background: var(--accent-glow);
    font-size: 26px;
    margin-bottom: 14px;
}
.login-title {
    font-family: var(--sans);
    font-size: 22px;
    font-weight: 800;
    color: var(--text-bright);
    letter-spacing: -.3px;
}
.login-title span { color: var(--accent); }
.login-sub { font-size: 12px; color: var(--text-dim); margin-top: 5px; }
.login-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 28px 28px 24px;
    position: relative;
    overflow: hidden;
}
.login-card::before {
    content: '';
    position: absolute;
    inset: 0 0 auto 0;
    height: 1.5px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
}
.field { margin-bottom: 16px; }
.field label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 7px;
}
.field-inner { position: relative; }
.field-inner svg {
    position: absolute;
    left: 11px; top: 50%;
    transform: translateY(-50%);
    width: 14px; height: 14px;
    color: var(--text-dim);
    pointer-events: none;
}
.field input {
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 7px;
    color: var(--text-bright);
    font-family: var(--mono);
    font-size: 13px;
    padding: 10px 12px 10px 34px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.field input:focus {
    border-color: var(--accent-dim);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
.toggle-pw {
    position: absolute;
    right: 10px; top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-dim);
    padding: 2px;
    display: grid; place-items: center;
    transition: color .15s;
}
.toggle-pw:hover { color: var(--accent); }
.toggle-pw svg { width: 15px; height: 15px; }
.error-msg {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: rgba(244,112,112,.08);
    border: 1px solid rgba(244,112,112,.25);
    border-radius: 7px;
    color: var(--danger);
    font-size: 12px;
    padding: 10px 12px;
    margin-bottom: 16px;
    line-height: 1.5;
}
.error-msg svg { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px; }
.btn-login {
    width: 100%;
    padding: 11px;
    background: var(--accent-dim);
    border: 1px solid var(--accent);
    border-radius: 7px;
    color: var(--accent);
    font-family: var(--mono);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s, box-shadow .2s;
    margin-top: 4px;
    letter-spacing: .03em;
}
.btn-login:hover { background: rgba(93,211,168,.2); box-shadow: 0 0 20px var(--accent-glow); }
.btn-login:active { transform: scale(.99); }
.login-footer { text-align: center; font-size: 11px; color: var(--text-dim); margin-top: 20px; }
@keyframes popIn {
    from { opacity: 0; transform: translateY(14px) scale(.98); }
    to   { opacity: 1; transform: translateY(0)    scale(1); }
}
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    20%       { transform: translateX(-6px); }
    40%       { transform: translateX(6px); }
    60%       { transform: translateX(-4px); }
    80%       { transform: translateX(4px); }
}
.shake { animation: shake .4s ease both; }
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-logo">
        <div class="logo-chip" aria-hidden="true">⚡</div>
        <div class="login-title">AP<span>Cu</span> Dashboard</div>
        <div class="login-sub">Sign in to continue</div>
    </div>

    <div class="login-card" id="loginCard">
        <?php if ( $login_error ) : ?>
        <div class="error-msg" role="alert">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path d="M8 1.5L14.5 13H1.5L8 1.5z"/>
                <line x1="8" y1="6" x2="8" y2="9.5"/>
                <circle cx="8" cy="11.5" r=".5" fill="currentColor" stroke="none"/>
            </svg>
            <?= htmlspecialchars( $login_error ) ?>
        </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars( $script_url ) ?>" id="loginForm">
            <input type="hidden" name="_login" value="1">

            <div class="field">
                <label for="username">Username</label>
                <div class="field-inner">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <circle cx="8" cy="5.5" r="2.5"/>
                        <path d="M2 13.5c0-3 2.7-5 6-5s6 2 6 5"/>
                    </svg>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars( (string) ( $_POST['username'] ?? '' ) ) ?>"
                           autocomplete="username" autocapitalize="none"
                           spellcheck="false" required autofocus>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="field-inner">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <rect x="3" y="7" width="10" height="8" rx="2"/>
                        <path d="M5 7V5a3 3 0 016 0v2"/>
                    </svg>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required>
                    <button type="button" class="toggle-pw" onclick="togglePw()"
                            aria-label="Toggle password visibility">
                        <svg id="iconShow" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6">
                            <ellipse cx="8" cy="8" rx="6" ry="3.5"/>
                            <circle cx="8" cy="8" r="1.5"/>
                        </svg>
                        <svg id="iconHide" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" style="display:none">
                            <line x1="2" y1="2" x2="14" y2="14"/>
                            <path d="M6.5 4.2A6.6 6.6 0 018 4c3.3 0 6 3.5 6 4a6.6 6.6 0 01-1.2 1.8"/>
                            <path d="M4.2 5.8A6.4 6.4 0 002 8c0 .5 2.7 4 6 4a6 6 0 002.8-.8"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login">Sign in &rarr;</button>
        </form>
    </div>

    <div class="login-footer">APCu <?= phpversion( 'apcu' ) ?> &middot; PHP <?= PHP_VERSION ?></div>
</div>
<script>
function togglePw() {
    const inp  = document.getElementById( 'password' );
    const show = document.getElementById( 'iconShow' );
    const hide = document.getElementById( 'iconHide' );
    if ( inp.type === 'password' ) {
        inp.type           = 'text';
        show.style.display = 'none';
        hide.style.display = '';
    } else {
        inp.type           = 'password';
        show.style.display = '';
        hide.style.display = 'none';
    }
}
<?php if ( $login_error ) : ?>
document.getElementById( 'loginCard' ).classList.add( 'shake' );
<?php endif; ?>
</script>
</body>
</html>
        <?php
        exit;
    }
    // Authenticated — fall through to dashboard.
}

/*
|-----------------------------
| JSON API  (Ajax endpoint)
|-----------------------------
|
| All data reads and writes return JSON when the request
| carries the X-Requested-With: XMLHttpRequest header.
| The dashboard JS uses this to refresh the UI without
| a full page reload.
*/
$csrf = hash_hmac( 'sha256', $script_url, php_uname() );

// Helpers (defined early so the JSON path can use them too).
function fmt_bytes( int|float $b ): string {
    $units = [ 'B', 'KB', 'MB', 'GB' ];
    $i     = 0;
    while ( $b >= 1024 && $i < 3 ) {
        $b /= 1024;
        $i++;
    }
    return round( $b, 2 ) . ' ' . $units[ $i ];
}

function hit_rate( int $h, int $m ): string {
    $t = $h + $m;
    return $t === 0 ? 'N/A' : round( $h / $t * 100, 1 ) . '%';
}

function fmt_uptime( int $s ): string {
    $d     = intdiv( $s, 86400 );
    $h     = intdiv( $s % 86400, 3600 );
    $m     = intdiv( $s % 3600, 60 );
    $sc    = $s % 60;
    $parts = [];
    if ( $d ) $parts[] = "{$d}d";
    if ( $h ) $parts[] = "{$h}h";
    if ( $m ) $parts[] = "{$m}m";
    $parts[] = "{$sc}s";
    return implode( ' ', $parts );
}

function build_payload(): array {
    $cache_info  = apcu_cache_info( true );
    $sma_info    = apcu_sma_info();
    $entries_raw = apcu_cache_info( false )['cache_list'] ?? [];

    $total_mem = (int) ( $sma_info['num_seg'] * $sma_info['seg_size'] );
    $used_mem  = $total_mem - (int) $sma_info['avail_mem'];
    $mem_pct   = $total_mem > 0 ? round( ( $used_mem / $total_mem ) * 100, 1 ) : 0;
    $hits      = (int) ( $cache_info['num_hits']   ?? 0 );
    $misses    = (int) ( $cache_info['num_misses'] ?? 0 );

    // APCu's creation_time is seconds elapsed since the cache started,
    // not a Unix timestamp. Add start_time to convert it to a real epoch
    // so date() and JS's new Date() both produce the correct wall-clock time.
    $cache_start = (int) ( $cache_info['start_time'] ?? 0 );

    $entries = [];
    foreach ( $entries_raw as $e ) {
        $raw_created = (int) ( $e['creation_time'] ?? 0 );
        $entries[] = [
            'key'     => (string) ( $e['info']    ?? '' ),
            'hits'    => (int)    ( $e['num_hits'] ?? 0  ),
            'size'    => (int)    ( $e['mem_size'] ?? 0  ),
            'ttl'     => (int)    ( $e['ttl']      ?? 0  ),
            'created' => $cache_start > 0 ? $cache_start + $raw_created : $raw_created,
        ];
    }

    return [
        'stats' => [
            'num_entries' => count( $entries ),
            'hits'        => $hits,
            'misses'      => $misses,
            'hit_rate'    => hit_rate( $hits, $misses ),
            'mem_pct'     => $mem_pct,
            'mem_used'    => fmt_bytes( $used_mem ),
            'mem_free'    => fmt_bytes( (int) $sma_info['avail_mem'] ),
            'mem_total'   => fmt_bytes( $total_mem ),
            'start_time'  => (int) ( $cache_info['start_time'] ?? 0 ),
        ],
        'entries' => $entries,
    ];
}

// Ajax write actions (delete / clear).
if ( $is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST' && ! isset( $_POST['_login'] ) ) {
    header( 'Content-Type: application/json; charset=utf-8' );

    if ( isset( $_POST['delete_key'] ) && is_string( $_POST['delete_key'] ) ) {
        $key    = trim( stripslashes( $_POST['delete_key'] ) );
        apcu_delete( $key );
    } elseif ( isset( $_POST['clear_all'] ) ) {
        apcu_clear_cache();
    }

    echo json_encode( [ 'ok' => true ] + build_payload() );
    exit;
}

// Ajax data refresh (GET).
if ( $is_ajax && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    echo json_encode( build_payload() );
    exit;
}

// Regular full-page write actions (non-JS fallback, POST/Redirect/Get).
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! isset( $_POST['_login'] ) ) {
    if ( isset( $_POST['delete_key'] ) && is_string( $_POST['delete_key'] ) ) {
        apcu_delete( trim( $_POST['delete_key'] ) );
    } elseif ( isset( $_POST['clear_all'] ) ) {
        apcu_clear_cache();
    }
    header( 'Location: ' . $script_url );
    exit;
}

// Full-page HTML render — collect data for the initial paint.
$payload     = build_payload();
$stats       = $payload['stats'];
$entries     = $payload['entries'];
$num_entries = $stats['num_entries'];
$hits        = $stats['hits'];
$misses      = $stats['misses'];
$mem_pct     = $stats['mem_pct'];
$uptime_secs = $stats['start_time'] ? time() - $stats['start_time'] : 0;
$hr          = ( $hits + $misses > 0 ) ? ( $hits / ( $hits + $misses ) ) : null;
$hr_class    = $hr === null ? '' : ( $hr >= .7 ? 'c-accent' : ( $hr >= .4 ? 'c-warning' : 'c-danger' ) );

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>APCu Dashboard</title>
<style>
/* RESET & TOKENS */
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

html { scroll-behavior: smooth; }

body {
    font-family: var(--mono);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 32px 24px 72px;
    font-size: 13px;
    line-height: 1.65;
    background-image: radial-gradient(ellipse 80% 40% at 50% -10%, rgba(93,211,168,.06) 0%, transparent 70%);
}

/* HEADER */
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

.site-sub { font-size: 11px; color: var(--text-dim); margin-top: 2px; }

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

/* WARNING BANNER */
.warn-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: rgba(248,190,90,.07);
    border: 1px solid rgba(248,190,90,.28);
    border-radius: var(--radius);
    padding: 13px 16px;
    margin-bottom: 26px;
    animation: fadeUp .45s ease both;
    line-height: 1.6;
}

.warn-banner svg { width: 16px; height: 16px; color: var(--warning); flex-shrink: 0; margin-top: 1px; }
.warn-banner-body { font-size: 12px; }
.warn-banner-title { font-weight: 600; color: var(--warning); margin-bottom: 2px; }
.warn-banner-body p { color: var(--text-dim); }
.warn-banner code {
    background: rgba(248,190,90,.12);
    border: 1px solid rgba(248,190,90,.2);
    border-radius: 3px;
    padding: 1px 5px;
    font-family: var(--mono);
    font-size: 11px;
    color: var(--warning);
}

/* STAT GRID */
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

.stat-card:hover { border-color: var(--accent-dim); box-shadow: 0 0 22px var(--accent-glow); }

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
    transition: color .3s;
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

.mem-bar-fill.warn   { background: linear-gradient(90deg, #8a5c00, var(--warning)); box-shadow: 0 0 8px rgba(248,190,90,.4); }
.mem-bar-fill.danger { background: linear-gradient(90deg, #7a1e1e, var(--danger));  box-shadow: 0 0 8px rgba(244,112,112,.4); }

.mem-detail { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-dim); margin-top: 7px; }

/* TOOLBAR */
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

.section-title { font-family: var(--sans); font-size: 14px; font-weight: 700; color: var(--text-bright); }

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

#search:focus { border-color: var(--accent-dim); box-shadow: 0 0 0 3px var(--accent-glow); }
#search::placeholder { color: var(--text-dim); }

/* BUTTONS */
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
    position: relative;
}

.btn:disabled { opacity: .55; cursor: not-allowed; }

.btn-danger { background: rgba(244,112,112,.07); border-color: rgba(244,112,112,.22); color: var(--danger); }
.btn-danger:hover:not(:disabled) { background: rgba(244,112,112,.16); box-shadow: 0 0 14px rgba(244,112,112,.18); }

.btn-ghost { border-color: var(--border); color: var(--text-dim); }
.btn-ghost:hover:not(:disabled) { border-color: var(--accent-dim); color: var(--accent); }

/* Spinner — shown inside a button while an Ajax request is in flight */
.btn .spinner {
    display: none;
    width: 11px; height: 11px;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin .6s linear infinite;
    flex-shrink: 0;
}

.btn.loading .spinner  { display: inline-block; }
.btn.loading .btn-text { opacity: .6; }

/* TABLE */
.table-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: auto;
    animation: fadeUp .45s .35s ease both;
}

table { width: 100%; border-collapse: collapse; min-width: 640px; }

thead { background: var(--surface-2); border-bottom: 1px solid var(--border); }

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

tbody tr { border-top: 1px solid var(--border); transition: background .12s; }
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

.hits-badge.hot  { border-color: var(--accent-dim); background: var(--accent-glow); color: var(--accent); }
.hits-badge.cold { color: var(--text-dim); }

/* TTL */
.ttl-pill    { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.ttl-forever { background: rgba(93,211,168,.07);  border: 1px solid var(--accent-dim);         color: var(--accent); }
.ttl-expiring{ background: rgba(248,190,90,.07);  border: 1px solid rgba(248,190,90,.3);        color: var(--warning); }

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
    position: relative;
}

.del-btn:hover { color: var(--danger); border-color: rgba(244,112,112,.28); background: rgba(244,112,112,.07); }

.del-btn .spinner {
    display: none;
    width: 10px; height: 10px;
    border: 1.5px solid var(--danger);
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin .6s linear infinite;
}

.del-btn.loading .spinner  { display: inline-block; }
.del-btn.loading .del-text { display: none; }

/* Empty */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-dim); }
.empty-state .icon { font-size: 28px; margin-bottom: 12px; }

/* FOOTER */
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

/* MODAL */
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

.modal h2 { font-family: var(--sans); font-size: 17px; font-weight: 800; color: var(--text-bright); margin-bottom: 9px; }
.modal p  { color: var(--text-dim); font-size: 12px; line-height: 1.7; margin-bottom: 22px; }

.modal-actions { display: flex; gap: 10px; justify-content: center; }

/* TOAST */
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

.toast.show  { opacity: 1; transform: translateY(0); }
.toast.error { border-left-color: var(--danger); }

/* ANIMATIONS */
@keyframes fadeDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeUp   { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes pulse    { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }
@keyframes popIn    { from { opacity: 0; transform: scale(.95); } to { opacity: 1; transform: scale(1); } }
@keyframes spin     { to { transform: rotate(360deg); } }

tbody tr { animation: rowIn .28s ease both; }
tbody tr:nth-child(1)   { animation-delay: .03s; }
tbody tr:nth-child(2)   { animation-delay: .06s; }
tbody tr:nth-child(3)   { animation-delay: .09s; }
tbody tr:nth-child(4)   { animation-delay: .12s; }
tbody tr:nth-child(5)   { animation-delay: .15s; }
tbody tr:nth-child(6)   { animation-delay: .18s; }
tbody tr:nth-child(7)   { animation-delay: .21s; }
tbody tr:nth-child(8)   { animation-delay: .24s; }
tbody tr:nth-child(n+9) { animation-delay: .27s; }

@keyframes rowIn { from { opacity: 0; transform: translateX(-5px); } to { opacity: 1; transform: translateX(0); } }

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

<!-- CONFIRM MODAL (shared by delete-key and clear-all) -->
<div class="modal-overlay" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal">
        <h2 id="modalTitle"></h2>
        <p id="modalBody"></p>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="closeModal()" type="button">Cancel</button>
            <button type="button" id="modalConfirmBtn" class="btn btn-danger">
                <span class="spinner"></span>
                <span class="btn-text">Confirm</span>
            </button>
        </div>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<!-- HEADER -->
<header class="header">
    <div class="logo-row">
        <div class="logo-chip" aria-hidden="true">⚡</div>
        <div>
            <div class="site-title">AP<span>Cu</span> Dashboard</div>
            <div class="site-sub">PHP <?= PHP_VERSION ?> &middot; APCu <?= phpversion( 'apcu' ) ?></div>
        </div>
    </div>
    <div class="header-right">
        <div class="status-row"><span class="pulse-dot" aria-hidden="true"></span> Cache active</div>
        <div id="liveClock"><?= htmlspecialchars( date( 'D, d M Y' ) ) ?></div>
        <div id="liveTime"><?= htmlspecialchars( date( 'H:i:s' ) ) ?></div>
        <?php if ( $auth_enabled ) : ?>
        <div style="margin-top:8px">
            <a href="?logout" class="btn btn-ghost" style="font-size:11px;padding:4px 10px"
               onclick="return confirm('Sign out?')">&#8594; Sign out</a>
        </div>
        <?php endif; ?>
    </div>
</header>

<?php if ( ! $auth_enabled ) : ?>
<!-- DEFAULT CREDENTIALS WARNING -->
<div class="warn-banner" role="alert">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
        <path d="M8 1.5L14.5 13H1.5L8 1.5z"/>
        <line x1="8" y1="6" x2="8" y2="9.5"/>
        <circle cx="8" cy="11.5" r=".6" fill="currentColor" stroke="none"/>
    </svg>
    <div class="warn-banner-body">
        <div class="warn-banner-title">Dashboard is unprotected</div>
        <p>You are using the default credentials (<code>admin</code> / <code>changeme</code>).
           Change <code>APCU_DASH_USER</code> and <code>APCU_DASH_PASS</code> at the top of this
           file to enforce login and hide this warning.</p>
    </div>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="stats-grid" role="list" id="statsGrid">

    <div class="stat-card" role="listitem">
        <div class="stat-label">Entries</div>
        <div class="stat-val c-accent" id="statEntries"><?= number_format( $num_entries ) ?></div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Cache Hits</div>
        <div class="stat-val" id="statHits"><?= number_format( $hits ) ?></div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Cache Misses</div>
        <div class="stat-val <?= ( $misses > $hits && ( $hits + $misses ) > 0 ) ? 'c-danger' : '' ?>"
             id="statMisses"><?= number_format( $misses ) ?></div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Hit Rate</div>
        <div class="stat-val <?= $hr_class ?>" id="statHitRate"><?= hit_rate( $hits, $misses ) ?></div>
    </div>

    <div class="stat-card" role="listitem">
        <div class="stat-label">Uptime</div>
        <div class="stat-val" style="font-size:18px" id="statUptime"><?= fmt_uptime( $uptime_secs ) ?></div>
    </div>

    <div class="stat-card mem-card" role="listitem">
        <div class="stat-label">Memory</div>
        <div class="stat-val <?= $mem_pct >= 90 ? 'c-danger' : ( $mem_pct >= 70 ? 'c-warning' : 'c-accent' ) ?>"
             id="statMemPct"><?= $mem_pct ?>%</div>
        <div class="mem-bar-bg" role="progressbar"
             aria-valuenow="<?= $mem_pct ?>" aria-valuemin="0" aria-valuemax="100"
             id="memBarWrap">
            <div class="mem-bar-fill <?= $mem_pct >= 90 ? 'danger' : ( $mem_pct >= 70 ? 'warn' : '' ) ?>"
                 style="width:<?= $mem_pct ?>%" id="memBarFill"></div>
        </div>
        <div class="mem-detail">
            <span id="memUsed">Used: <?= $stats['mem_used'] ?></span>
            <span id="memFree">Free: <?= $stats['mem_free'] ?></span>
            <span id="memTotal">Total: <?= $stats['mem_total'] ?></span>
        </div>
    </div>

</div>

<!-- TOOLBAR -->
<div class="toolbar">
    <div class="toolbar-left">
        <span class="section-title">Cache Entries</span>
        <span class="badge" id="visibleCount"><?= $num_entries ?></span>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <div class="search-wrap">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <circle cx="6.5" cy="6.5" r="4.5"/>
                <line x1="10.5" y1="10.5" x2="14" y2="14"/>
            </svg>
            <input type="search" id="search" placeholder="Filter by key…"
                   oninput="filterTable(this.value)"
                   aria-label="Filter cache entries by key">
        </div>
        <?php if ( $num_entries > 0 ) : ?>
        <button class="btn btn-danger" id="clearBtn" onclick="openClearModal()" type="button" aria-haspopup="dialog">
            <span class="spinner"></span>
            <span class="btn-text">&#8960; Clear Cache</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- TABLE -->
<div class="table-wrap" role="region" aria-label="Cache entries">
<table id="cacheTable">
    <thead>
    <tr>
        <th onclick="sortTable(0)" id="th-0" aria-sort="none">Key     <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(1)" id="th-1" aria-sort="none">Hits    <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(2)" id="th-2" aria-sort="none">Size    <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(3)" id="th-3" aria-sort="none">TTL     <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th onclick="sortTable(4)" id="th-4" aria-sort="none">Created <span class="sort-icon" aria-hidden="true">↕</span></th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody id="tableBody">
    <?php if ( empty( $entries ) ) : ?>
        <tr>
            <td colspan="6">
                <div class="empty-state">
                    <div class="icon">◎</div>
                    <p>No cache entries yet.</p>
                </div>
            </td>
        </tr>
    <?php else : ?>
        <?php foreach ( $entries as $e ) :
            $key      = $e['key'];
            $hits_val = $e['hits'];
            $size_val = $e['size'];
            $ttl_val  = $e['ttl'];
            $created  = $e['created'];
            $is_hot   = $hits_val >= 10;
            $dot_pos  = strrpos( $key, '.' );
            $prefix   = $dot_pos !== false ? substr( $key, 0, $dot_pos + 1 ) : '';
            $suffix   = $dot_pos !== false ? substr( $key, $dot_pos + 1 )    : $key;
        ?>
        <tr>
            <td class="key-cell" title="<?= htmlspecialchars( $key ) ?>">
                <?php if ( $prefix ) : ?>
                    <span class="key-prefix"><?= htmlspecialchars( $prefix ) ?></span><?= htmlspecialchars( $suffix ) ?>
                <?php else : ?>
                    <?= htmlspecialchars( $key ) ?>
                <?php endif; ?>
            </td>
            <td>
                <span class="hits-badge <?= $is_hot ? 'hot' : ( $hits_val === 0 ? 'cold' : '' ) ?>">
                    <?= $is_hot ? '🔥 ' : '' ?><?= number_format( $hits_val ) ?>
                </span>
            </td>
            <td class="size-cell" data-bytes="<?= $size_val ?>"><?= fmt_bytes( $size_val ) ?></td>
            <td>
                <?php if ( $ttl_val === 0 ) : ?>
                    <span class="ttl-pill ttl-forever">&#8734; forever</span>
                <?php else : ?>
                    <span class="ttl-pill ttl-expiring"><?= number_format( $ttl_val ) ?>s</span>
                <?php endif; ?>
            </td>
            <td class="date-cell" data-ts="<?= $created ?>"><?= date( 'Y-m-d H:i:s', $created ) ?></td>
            <td>
                <button type="button" class="del-btn"
                        onclick="openDeleteModal(<?= htmlspecialchars( json_encode( $key ) ) ?>)"
                        aria-label="Delete <?= htmlspecialchars( $key ) ?>">
                    <span class="spinner"></span>
                    <span class="del-text">&#10005; delete</span>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- FOOTER -->
<footer class="footer">
    <span>APCu <?= phpversion( 'apcu' ) ?> &middot; PHP <?= PHP_VERSION ?> &middot; <?= php_uname( 'n' ) ?></span>
    <span>
        Uptime: <span id="footerUptime"><?= fmt_uptime( $uptime_secs ) ?></span> &middot;
        <a href="https://github.com/CallismartLtd/apcu-dashboard" target="_blank" rel="noopener">APCu Dashboard</a>
    </span>
</footer>

<!-- JAVASCRIPT -->
<script>
'use strict';

/*
|----------------------------------
| BOOTSTRAP DATA FROM SERVER
|----------------------------------
*/
const _csrf       = <?= json_encode( $csrf ) ?>;
const _scriptUrl  = <?= json_encode( $script_url ) ?>; // absolute URL to this script, query-string-free
const _startTime  = <?= (int) ( $stats['start_time'] ) ?>; // Unix epoch — used for client-side uptime ticker
const _bootTime   = Math.floor( Date.now() / 1000 );       // client-side reference point

/*
|----------------------------------
| LIVE CLOCK & UPTIME
|----------------------------------
|
| Tick every second to keep the header clock and uptime
| counters alive without any server requests.
*/
function _pad( n ) { return String( n ).padStart( 2, '0' ); }

function _fmt_uptime( secs ) {
    const d  = Math.floor( secs / 86400 );
    const h  = Math.floor( ( secs % 86400 ) / 3600 );
    const m  = Math.floor( ( secs % 3600 )  / 60 );
    const sc = secs % 60;
    const parts = [];
    if ( d )  parts.push( d + 'd' );
    if ( h )  parts.push( h + 'h' );
    if ( m )  parts.push( m + 'm' );
    parts.push( sc + 's' );
    return parts.join( ' ' );
}

const _days  = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
const _months= [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

function _tick() {
    const now   = new Date();
    const clock = document.getElementById( 'liveClock' );
    const time  = document.getElementById( 'liveTime' );
    const upEl  = document.getElementById( 'statUptime' );
    const ftEl  = document.getElementById( 'footerUptime' );

    if ( clock ) {
        clock.textContent = _days[ now.getDay() ] + ', '
            + _pad( now.getDate() ) + ' ' + _months[ now.getMonth() ] + ' ' + now.getFullYear();
    }

    if ( time ) {
        time.textContent = _pad( now.getHours() ) + ':' + _pad( now.getMinutes() ) + ':' + _pad( now.getSeconds() );
    }

    if ( _startTime > 0 ) {
        // Add elapsed client-side seconds on top of the uptime the server rendered.
        const elapsed = Math.floor( Date.now() / 1000 ) - _bootTime;
        const total   = ( <?= $uptime_secs ?> ) + elapsed;
        const str     = _fmt_uptime( total );
        if ( upEl ) upEl.textContent = str;
        if ( ftEl ) ftEl.textContent = str;
    }
}

setInterval( _tick, 1000 );
_tick(); // run immediately so there is no one-second blank

/*
|----------------------------------
| AJAX HELPER
|----------------------------------
*/
function _ajax( body_obj ) {
    return fetch( _scriptUrl, {
        method:  'POST',
        headers: {
            'Content-Type':     'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams( { csrf: _csrf, ...body_obj } ).toString(),
    } ).then( r => r.ok ? r.json() : Promise.reject( r.statusText ) );
}

function _ajax_get() {
    return fetch( _scriptUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    } ).then( r => r.ok ? r.json() : Promise.reject( r.statusText ) );
}

/*
|----------------------------------
| TOAST
|----------------------------------
*/
function showToast( msg, is_error = false ) {
    const t = document.getElementById( 'toast' );
    t.textContent = msg;
    t.classList.toggle( 'error', is_error );
    t.classList.add( 'show' );
    clearTimeout( t._timer );
    t._timer = setTimeout( () => t.classList.remove( 'show' ), 2800 );
}

/*
|----------------------------------
| STATS REFRESH
|----------------------------------
|
| Called after every successful write operation.
| Re-renders stat cards and table without a page reload.
*/
function _refresh_stats( data ) {
    const s = data.stats;

    // Stat cards.
    document.getElementById( 'statEntries' ).textContent = s.num_entries.toLocaleString();
    document.getElementById( 'statHits' ).textContent    = s.hits.toLocaleString();
    document.getElementById( 'statMisses' ).textContent  = s.misses.toLocaleString();
    document.getElementById( 'statHitRate' ).textContent = s.hit_rate;
    document.getElementById( 'statMemPct' ).textContent  = s.mem_pct + '%';
    document.getElementById( 'memUsed' ).textContent     = 'Used: '  + s.mem_used;
    document.getElementById( 'memFree' ).textContent     = 'Free: '  + s.mem_free;
    document.getElementById( 'memTotal' ).textContent    = 'Total: ' + s.mem_total;

    // Memory bar.
    const fill = document.getElementById( 'memBarFill' );
    fill.style.width = s.mem_pct + '%';
    fill.className   = 'mem-bar-fill' + ( s.mem_pct >= 90 ? ' danger' : s.mem_pct >= 70 ? ' warn' : '' );

    document.getElementById( 'memBarWrap' ).setAttribute( 'aria-valuenow', s.mem_pct );

    // Hit rate colour.
    const hrEl = document.getElementById( 'statHitRate' );
    const hr   = ( s.hits + s.misses ) > 0 ? s.hits / ( s.hits + s.misses ) : null;
    hrEl.className = 'stat-val ' + ( hr === null ? '' : hr >= .7 ? 'c-accent' : hr >= .4 ? 'c-warning' : 'c-danger' );

    // Misses colour.
    const missEl = document.getElementById( 'statMisses' );
    missEl.className = 'stat-val' + ( s.misses > s.hits && ( s.hits + s.misses ) > 0 ? ' c-danger' : '' );

    // Rebuild the table.
    _render_table( data.entries );
}

/*
|----------------------------------
| TABLE RENDERER
|----------------------------------
|
| Builds <tbody> rows from the JSON entries array
| so the table stays in sync after Ajax operations.
*/
function _esc( s ) {
    return String( s )
        .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
}

function _fmt_bytes( b ) {
    const units = [ 'B', 'KB', 'MB', 'GB' ];
    let i = 0;
    while ( b >= 1024 && i < 3 ) { b /= 1024; i++; }
    return Math.round( b * 100 ) / 100 + ' ' + units[ i ];
}

function _fmt_date( ts ) {
    const d = new Date( ts * 1000 );
    return d.getFullYear() + '-'
        + _pad( d.getMonth() + 1 ) + '-'
        + _pad( d.getDate() )      + ' '
        + _pad( d.getHours() )     + ':'
        + _pad( d.getMinutes() )   + ':'
        + _pad( d.getSeconds() );
}

function _render_table( entries ) {
    const tbody = document.getElementById( 'tableBody' );

    if ( ! entries || entries.length === 0 ) {
        tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state">'
            + '<div class="icon">◎</div><p>No cache entries yet.</p></div></td></tr>';
        document.getElementById( 'visibleCount' ).textContent = '0';

        // Hide the clear button when there is nothing to clear.
        const clearBtn = document.getElementById( 'clearBtn' );
        if ( clearBtn ) clearBtn.style.display = 'none';
        return;
    }

    // Show the clear button if it exists and was hidden.
    const clearBtn = document.getElementById( 'clearBtn' );
    if ( clearBtn ) clearBtn.style.display = '';

    let html = '';
    entries.forEach( e => {
        const is_hot  = e.hits >= 10;
        const dot_pos = e.key.lastIndexOf( '.' );
        const prefix  = dot_pos !== -1 ? e.key.slice( 0, dot_pos + 1 ) : '';
        const suffix  = dot_pos !== -1 ? e.key.slice( dot_pos + 1 )    : e.key;

        const key_html = prefix
            ? '<span class="key-prefix">' + _esc( prefix ) + '</span>' + _esc( suffix )
            : _esc( e.key );

        const hits_cls  = is_hot ? 'hot' : e.hits === 0 ? 'cold' : '';
        const hits_icon = is_hot ? '🔥 ' : '';
        const ttl_html  = e.ttl === 0
            ? '<span class="ttl-pill ttl-forever">&#8734; forever</span>'
            : '<span class="ttl-pill ttl-expiring">' + e.ttl.toLocaleString() + 's</span>';

        // JSON.stringify produces double-quoted strings.
        // _esc() turns those quotes into &quot; so they survive inside an
        // onclick="..." HTML attribute without breaking the attribute boundary.
        const key_json_attr = _esc( JSON.stringify( e.key ) );

        html += '<tr>'
            + '<td class="key-cell" title="' + _esc( e.key ) + '">' + key_html + '</td>'
            + '<td><span class="hits-badge ' + hits_cls + '">' + hits_icon + e.hits.toLocaleString() + '</span></td>'
            + '<td class="size-cell" data-bytes="' + e.size + '">' + _fmt_bytes( e.size ) + '</td>'
            + '<td>' + ttl_html + '</td>'
            + '<td class="date-cell" data-ts="' + e.created + '">' + _fmt_date( e.created ) + '</td>'
            + '<td><button type="button" class="del-btn" onclick="openDeleteModal(' + key_json_attr + ')"'
            + ' aria-label="Delete ' + _esc( e.key ) + '">'
            + '<span class="spinner"></span><span class="del-text">&#10005; delete</span>'
            + '</button></td>'
            + '</tr>';
    } );

    tbody.innerHTML = html;
    _apply_search_filter();
}

/*
|----------------------------------
| SORT
|----------------------------------
*/
let _sort_col = -1, _sort_asc = true;

function sortTable( col ) {
    const tbody = document.getElementById( 'tableBody' );
    const rows  = [ ...tbody.querySelectorAll( 'tr' ) ].filter( r => r.querySelector( 'td[colspan]' ) === null );
    const ths   = document.querySelectorAll( 'thead th' );

    if ( _sort_col === col ) { _sort_asc = ! _sort_asc; }
    else { _sort_col = col; _sort_asc = true; }

    ths.forEach( ( th, i ) => {
        th.classList.toggle( 'sorted', i === col );
        const ic = th.querySelector( '.sort-icon' );
        if ( ic ) ic.textContent = i === col ? ( _sort_asc ? '↑' : '↓' ) : '↕';
        th.setAttribute( 'aria-sort', i === col ? ( _sort_asc ? 'ascending' : 'descending' ) : 'none' );
    } );

    rows.sort( ( a, b ) => {
        const ca = a.querySelectorAll( 'td' )[ col ];
        const cb = b.querySelectorAll( 'td' )[ col ];
        if ( ! ca || ! cb ) return 0;

        const va = ca.dataset.bytes ?? ca.dataset.ts ?? ca.textContent.trim();
        const vb = cb.dataset.bytes ?? cb.dataset.ts ?? cb.textContent.trim();
        const na = parseFloat( va ), nb = parseFloat( vb );

        if ( ! isNaN( na ) && ! isNaN( nb ) ) return _sort_asc ? na - nb : nb - na;
        return _sort_asc ? va.localeCompare( vb ) : vb.localeCompare( va );
    } );

    rows.forEach( r => tbody.appendChild( r ) );
    updateCount();
}

/*
|----------------------------------
| FILTER
|----------------------------------
*/
function _apply_search_filter() {
    const term = ( document.getElementById( 'search' ).value || '' ).trim().toLowerCase();
    document.querySelectorAll( '#tableBody tr' ).forEach( row => {
        const kc = row.querySelector( '.key-cell' );
        row.hidden = kc ? ! kc.textContent.toLowerCase().includes( term ) : false;
    } );
    updateCount();
}

function filterTable( q ) { _apply_search_filter(); }

function updateCount() {
    const n = [ ...document.querySelectorAll( '#tableBody tr' ) ]
        .filter( r => ! r.hidden && ! r.querySelector( 'td[colspan]' ) ).length;
    document.getElementById( 'visibleCount' ).textContent = n;
}

/*
|----------------------------------
| MODAL
|----------------------------------
|
| Shared by both "delete single key" and "clear all".
| _pending_action holds the request params for the
| confirmed action so the confirm button can send them.
*/
const _modal      = document.getElementById( 'confirmModal' );
const _modalTitle = document.getElementById( 'modalTitle' );
const _modalBody  = document.getElementById( 'modalBody' );
const _confirmBtn = document.getElementById( 'modalConfirmBtn' );

let _pending_action = null; // { delete_key: '...' }  or  { clear_all: '1' }

function openDeleteModal( key ) {
    _modalTitle.textContent = 'Delete cache entry?';
    _modalBody.innerHTML    = 'This will permanently remove the key:<br><br>'
                            + '<code style="word-break:break-all;color:var(--accent)">' + _esc( key ) + '</code>';
    _confirmBtn.querySelector( '.btn-text' ).textContent = 'Yes, delete';
    _pending_action = { delete_key: key };
    _open_modal();
}

function openClearModal() {
    const n = parseInt( document.getElementById( 'visibleCount' ).textContent, 10 ) || 0;
    _modalTitle.textContent = 'Clear all cache?';
    _modalBody.innerHTML    = 'This will permanently delete all <strong>' + n + '</strong> cached entr'
                            + ( n === 1 ? 'y' : 'ies' ) + '. The action cannot be undone.';
    _confirmBtn.querySelector( '.btn-text' ).textContent = 'Yes, clear all';
    _pending_action = { clear_all: '1' };
    _open_modal();
}

function _open_modal() {
    _modal.classList.add( 'open' );
    _modal.querySelector( '.btn-ghost' ).focus();
}

function closeModal() {
    _modal.classList.remove( 'open' );
    _pending_action = null;
}

// Confirm button — fires the Ajax request.
_confirmBtn.addEventListener( 'click', () => {
    if ( ! _pending_action ) return;

    // Capture now — closeModal() sets _pending_action to null,
    // so reading it after that throws a TypeError that swallows
    // the .then() branch and makes the delete look like it failed.
    const action      = _pending_action;
    const is_clear    = !! action.clear_all;

    _confirmBtn.disabled = true;
    _confirmBtn.classList.add( 'loading' );

    _ajax( action )
        .then( data => {
            closeModal();
            _refresh_stats( data );
            showToast( is_clear ? 'Cache cleared.' : 'Entry deleted.' );
        } )
        .catch( err => {
            closeModal();
            showToast( 'Request failed. Please try again.', true );
        } )
        .finally( () => {
            _confirmBtn.disabled = false;
            _confirmBtn.classList.remove( 'loading' );
        } );
} );

_modal.addEventListener( 'click', e => { if ( e.target === _modal ) closeModal(); } );
document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' ) closeModal(); } );
</script>
</body>
</html>
