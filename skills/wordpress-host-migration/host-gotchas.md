# Host-side gotchas

Symptom -> cause -> fix, grouped by where they bite. Figures are observed values from a real
move and routine work on both hosts in 2026 (old side: an Israeli managed-WordPress host on
nginx with FTP only; new side: Cloudways with Varnish behind Cloudflare). Treat them as orders
of magnitude, not contracts.

## Old side: restricted shared host

**Site looks fully down from everywhere you test.**
Every path (`/`, `/wp-admin/`, `/wp-login.php`, `/readme.html`) returns HTTP 500 with the
stock nginx error page, byte-identical across URLs (170 bytes for HEAD, 572 for GET). Headers
are only `server: nginx`, `date`, `content-type`, `content-length`: no `x-powered-by`, no CDN
markers. Five probes in ten seconds give the same answer with the same timing. Changing
`X-Forwarded-For` or `Accept-Language` changes nothing.
-> Observed during a PHP upgrade: the host blocked non-local source IPs at the network layer
for the maintenance window. Local visitors saw a working site.
-> Ask someone inside the host's country to open the site in a private window with a
cache-buster, or use a local uptime checker or VPN exit. Generic "is it down" services count
any HTTP answer, 500 included, as "up", so they mislead in both directions. A real PHP-FPM
crash usually leaks something: `x-powered-by`, a longer WordPress error page, cookies, or
varying timing. Check this before declaring an outage, mid-migration or not.

**Your IP gets dropped for a quarter of an hour.**
-> The host's firewall bans bursty clients: parallel and cache-busted requests got an IP
dropped for about 18 minutes.
-> Keep probes light and sequential. Run all bulk reads from the new server through the
exporter, not from your workstation.

**Ports 80, 443 and 21 refused for everyone on the shared server.**
-> Observed twice during bulk FTP with 4 to 5 parallel connections plus a backup job, for
about 2 to 3 minutes each. Not proven causal; treat the host as fragile.
-> At most 2 parallel FTP connections. Connection refused from every node of a multi-location
checker (for example check-host.net) means server level, not an IP block; compare with
another site on the same server.

**Bulk FTP takes hours.**
-> Every file transfer and every directory listing opens a new TLS data channel: about 1.7 s
per file. A 1,800-file plugin took about 50 minutes on one connection.
-> Use the exporter for whole-site moves. When FTP is unavoidable: make uploads resumable by
comparing `SIZE` per file (control channel only, cheap) and skipping matches; on EOF
reconnect with a long backoff (20 s or more), never fast retries.

**A directory listing hangs forever.**
-> MLSD on a directory that is mid-delete can time out.
-> Delete by the known file list from your local copy (`DELE` is control-channel only), then
`RMD` bottom-up.

**Replacing a plugin or theme folder over FTP.**
Maintenance on an FTP-only host, before or instead of a move. During a move the old host
stays read-only.
-> There is no atomic copy without SSH.
-> Upload the new version to `wp-content/upgrade/<name>-<ver>` (outside `plugins/` and
`themes/`, so WordPress never sees two copies). Then on one connection rename live ->
`wp-content/upgrade/<name>-<old>-old` and staged -> live: 0.4 to 0.9 s, plugin stays active.
Expect at most one uncached request to hit a critical error from stale opcache paths right
after the swap. Check twice before rolling back.

**PHP version change "not applied".**
-> The panel promised one minute; the change landed about 8 minutes after "Save".
-> Wait, then test lightly (see the firewall item above).

**FTP or panel access refused from automation machines.**
-> FTP is IP-allowlisted in the host panel, and the host firewall blocked an always-on
automation machine.
-> Upload the exporter once from an allowlisted IP; run every later call from the new server.

## New side: managed cloud host

**`Permission denied (publickey,password)` on SFTP, credentials correct.**
Rule out your own tooling first, in this order:
1. `sshpass` with `sftp -b <batchfile>`: batch mode disables prompting, so sshpass has no
   prompt to answer and the password is never sent. Same error text as a ban. Test with a
   plain `ssh <user>@<host> exit` using the same credentials; if that authenticates (even with
   "shell access disabled"), feed sftp its commands on stdin instead:
   ```bash
   sshpass -f <password-file> sftp -o PreferredAuthentications=password -o PubkeyAuthentication=no <user>@<host> <<'EOF'
   cd public_html/wp-content/mu-plugins
   put <local-file>
   bye
   EOF
   ```
2. Stale credentials: app-level SFTP users get deleted or rotated; read the current ones in
   the host panel.
3. Only then fail2ban: about 4 connections in 5 minutes locked the source IP for about 30
   minutes. Every retry resets the timer, so never retry in a loop. Allowlisted IPs are exempt
   (Cloudways: server security settings), which beats waiting. Connect once, do all the work,
   disconnect.
For PHP code drops, wp-admin "Upload Plugin" (a zip with a plugin header) goes over HTTPS
and never touches fail2ban.

**Port 22 times out, 443 answers.**
-> Server SSH and SFTP are IP-allowlisted (a hard timeout, not a fail2ban reject).
-> The account owner adds your current egress IP (`curl -s https://api.ipify.org`).

**Password read from an env file doesn't work.**
-> Values with inline comments (`KEY=value    # note`) pick up the whitespace and comment.
-> Strip the prefix, then three or more spaces plus `#` and the rest; a single `#` can be
part of a password.

**`scp` fails "No such file or directory" on a path that exists.**
-> The default SFTP-subsystem scp failed against a Cloudways master account, which also
could not write to `/tmp`.
-> `scp -O` (legacy protocol) to a path under the account's home; or for one file,
`base64 < file | ssh <host> 'base64 -d > <path>'`.

**rsync exits 23, "failed to set times".**
-> The master user cannot set times on app-owned directories.
-> `--omit-dir-times`, then reset ownership to the app user.

**WP Rocket pages blank or erroring after the move.**
-> `wp-content/advanced-cache.php` hardcodes the old absolute docroot.
-> Rewrite it to the path PHP-FPM sees (Cloudways:
`/home/<server>.cloudwaysapps.com/<app>/public_html`). The master user's
`/home/master/applications/<app>/...` view is outside `open_basedir`.

**Every page 500s on a copy.**
-> Per-app PHP-FPM settings are not copied: the source ran `memory_limit=2048M` in its app FPM
config, the copy got 256M.
-> Read the source values and set them on the new app (Cloudways: application PHP settings,
or the API's FPM settings get/update calls).

**A staging clone is not a mirror.**
-> WordPress auto-updates ran about 30 s after a clone finished (core, WooCommerce,
Elementor), leaving a `.maintenance` file and a 503. The host's mail relay also sends real
mail from the clone.
-> Before using a clone (or a pre-cutover copy) for anything beyond GETs, add to
`wp-config.php`: `DISABLE_WP_CRON`, `WP_HTTP_BLOCK_EXTERNAL` (stops webhooks, conversion
APIs, newsletter syncs), `AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`,
`WP_REDIS_DISABLED`, all `true`, plus an mu-plugin on `pre_wp_mail` that only lets
allowlisted recipients through. Skip HTTP Basic auth: it breaks WordPress REST cookie auth.
To resync after the self-update: `rsync -a --no-owner --no-group --delete` the live docroot
(excluding `wp-config.php`, `.maintenance`, `wp-content/cache/`, `wp-content/mu-plugins/`),
`wp db export --single-transaction --quick` on live, import, then `search-replace` the https,
http and www URLs and the JSON-escaped `https:\/\/<domain>` form that Elementor stores.
Elementor's local Google Fonts CSS (`wp-content/uploads/elementor/google-fonts/css/*.css`)
keeps the live domain and fails CORS; `sed` it. Reset ownership last.
Remove every guard before the copy goes live.

**A custom cookie never reaches PHP.**
-> Varnish strips request cookies on front-end pages unless the name matches its allowlist
(`wordpress_logged_in`, `resetpass`, `wp-postpass`, `wordpress_*`, `comment_*`,
`woocommerce_cart*`, `woocommerce_session*`; on Cloudways in `/etc/varnish/recv/wordpress.vcl`).
`/wp-json/`, `/wp-admin/` and POSTs pass with cookies intact.
-> Name the cookie so it matches (for example containing `resetpass`), or use REST.

**Stale page after a change; `curl -X PURGE` returns 400.**
-> Cloudflare does not pass PURGE through. A cache-buster purges nothing; it only fetches a
different cache key. `wp cache flush` clears the object cache only.
-> From a shell on the origin, BAN and PURGE against the local Varnish, on both `/` and
`/index.php` (a BAN on `/` alone left the object cached):
```bash
for m in BAN PURGE; do for p in / /index.php; do
  curl -s -o /dev/null -w "$m $p %{http_code}\n" -X "$m" -H 'Host: <domain>' -H 'X-Forwarded-Proto: https' "http://localhost:8080$p"
done; done
```
Port 8080 on most servers; one answered only on port 80 (8081 returned 501). A 200 does not
prove a flush: re-request the bare URL and check `age:` dropped to 0 and `x-cache` is a MISS.
Varnish also caches static files and directory URLs uploaded into the docroot, so purge after
editing a file without bumping its `?ver`. Inside WordPress, `do_action('breeze_clear_varnish')`
and `do_action('breeze_clear_all_cache')` work; `wp breeze purge` has printed Success while
`age:` kept climbing, and on some apps fataled with an `ftp_nlist()` TypeError because WP-CLI
fell back to the FTP filesystem. Detection: `cache-provider: CLOUDWAYS-CACHE-<region>`,
`x-cache: HIT`, non-zero `age:`.

**WP Rocket keeps serving old HTML.**
-> Its static files sit under Varnish, and the `wp rocket` CLI is often not registered, so
`wp rocket clean` fails silently. Breeze, Varnish and Cloudflare purges don't touch them.
-> `rm -rf wp-content/cache/wp-rocket/<domain>/<path>` (or the whole host dir), then purge
Varnish for the path. The cache dir is owned by the app user, so the master user gets
"Permission denied"; use the app user's SSH.

**Webshell risk in uploads.**
-> Apache (behind nginx on Cloudways) honours `.htaccess`.
-> `wp-content/uploads/.htaccess` denying `*.php` (the build script writes it). It blocks
direct HTTP execution only, not `include`, so legitimate included PHP keeps working. Verify:
an `uploads/*.php` URL returns 403, a static upload returns 200.

**Database falls over during heavy work.**
-> A full DB dump was one of the loads stacked right before a plugin update on a 4 GB
server when the database fell over.
-> On a host with daily app backups, those are the rollback; don't add an extra dump on a
small server, and don't schedule heavy work over the nightly backup window. On hosts without
backups, keep your own dump.

## WordPress

**Permalinks 404 on the new host, home page fine.**
-> The nginx-only source never needed a `# BEGIN WordPress` block in `.htaccess`.
-> Append the new host's WordPress block (the build script does).

**PHP 8 fatals that an offline scan missed.**
An offline lint plus removed-function grep of every plugin came back clean, and PHP 8 fatals
still reached the live site. Causes and fixes:
- Premium plugins' public sources omit `vendor/`. The fatal was in a bundled minifier
  (`implode()` with reversed arguments). Scan the live install's `vendor/`, not a repo tag.
- WP Rocket runs minify and cache writes only on plain, cacheable URLs. Test every check
  page twice plain and twice cache-busted, and confirm the cache actually writes.
- Log with a temporary mu-plugin that sets
  `ini_set('error_log', WP_CONTENT_DIR . '/php-errors-<random-hex>.log')`. It cannot see code
  that runs before mu-plugins (`advanced-cache.php`); worker crashes show only as nginx 502s.
  Remove it afterwards.
- OPcache can serve the old compiled file for 60 to 90 s after a patch. Wait before retesting.

**Site-wide 500 after an Elementor auto-update, PHP 8, site behind Cloudflare.**
`Uncaught TypeError: substr(): Argument #1 ($string) must be of type string, int given` in
WP Rocket's `inc/ThirdParty/Plugins/CDN/Cloudflare.php` (line 562 in 3.20.3 and 3.21.1). Front end, wp-admin, wp-login and REST all 500.
-> An Elementor hook callback id is a numeric string, PHP 8 turns it into an int array key,
and WP Rocket's Cloudflare add-on calls `substr()` on it on every request. It needs the WP
Rocket Cloudflare add-on active: a same-stack site not behind Cloudflare was not hit. Moving a
site behind Cloudflare and turning the add-on on exposes it.
-> Quick fix: back up the file, change `substr( $key,` to `substr( (string) $key,`, `php -l`,
then `rm -rf wp-content/cache/wp-rocket/*` and `wp cache flush`. The next WP Rocket update
wipes that patch. Durable fix: an mu-plugin that on `init` priority 1 removes WP Rocket's
`unregister_cloudflare_clean_on_post` callback and redoes its cleanup with string-safe keys.
Turn off auto-updates for WP Rocket and Elementor on production
(`wp plugin auto-updates disable <slug>`). Verify on the bare URL.

**Script tags vanish or render as page text after a wp-cli write.**
-> `wp eval-file` or `wp post meta update` without `--user` runs as user 0, which lacks
`unfiltered_html`; Elementor's meta sanitiser strips `<script>` from Custom Code and HTML
widgets.
-> Pass `--user=<admin-id>` (find one with `wp user list --role=administrator`; user 1 often
does not exist). Read the meta back and check it still starts with `<script` before publishing.

**Edited a form but the page still behaves the old way.**
-> A global widget's settings live in its template post; pages hold stale copies that are not
used.
-> Edit only the template post. Back up `_elementor_data` first and save with a
`wp eval-file` script that writes `wp_slash(wp_json_encode($data))`. Then `wp elementor
flush-css` and `wp cache flush`. Elementor also caches rendered element HTML in the
`_elementor_element_cache` post meta; delete it on the edited template if the old markup
persists.

**A healthy page 404s only in automated checks.**
-> The cache-buster is a reserved WordPress query var (`w`, `p`, `m`, `s`, `cat`, `page`).
-> Use `?cachebust=<epoch>`.

**`wp plugin status | grep -i active` says a plugin is active when it isn't.**
-> The pattern also matches "Inactive".
-> `wp plugin get <slug> --field=status` and compare to `active` exactly.

## Cloudflare

**curl or Playwright gets 403 "Just a moment".**
-> A managed challenge on the zone.
-> Test the origin directly: `curl -k --resolve <domain>:443:<origin-ip> ...`, or Chromium
with `--host-resolver-rules=MAP <domain> <origin-ip>` and certificate errors ignored. In a
real browser session, a second navigation usually passes the challenge. In zsh, write curl
options inline; an unquoted `$OPTS` does not word-split.

**API error 9109 reading `/zones/<zone_id>/ssl/...`.**
-> The token lacks SSL read scope.
-> `GET /zones/<zone_id>/settings/ssl` carries `certificate_status`, enough for a preflight.

**Can't create the zone with an existing token.**
-> Zone creation needs `Zone:Zone:Edit` on all zones of the account.

**Free plan: bot bursts saturate a shared origin.**
Three rules per zone, verified shapes:
1. Cache rule (`http_request_cache_settings`): `cache: true`, edge TTL `override_origin`
   1800 s, browser TTL respects origin. Expression: the host, and not paths starting
   `/wp-admin`, `/wp-login`, `/wp-json`, `/xmlrpc.php`, and not query containing `wc-ajax`,
   `add-to-cart`, `elementor`, `preview`, `customize_changeset`, `nocache`, and not cookies
   containing `wordpress_logged_in_`, `comment_author`, `wp-postpass_`. WooCommerce sites also
   exclude `/cart`, `/order`, `/checkout`, `/my-account` and cookies `woocommerce_`,
   `wp_woocommerce_session_`. The edge TTL override also caches the trailing-slash 301 and
   strips origin `Set-Cookie`.
2. Rate limit (`http_ratelimit`), action block:
   `{"characteristics":["ip.src","cf.colo.id"],"period":10,"requests_per_period":30,"mitigation_timeout":10,"requests_to_origin":true}`
   with expression `(not cf.client.bot) and not starts_with(http.request.uri.path,"/wp-admin/") and not starts_with(http.request.uri.path,"/wp-json/") and not starts_with(http.request.uri.path,"/wp-content/") and not starts_with(http.request.uri.path,"/wp-includes/")`.
   The Free plan rejects `http.cookie` in rate-limit expressions, so logged-in editors are
   protected only by the path exclusions.
3. Free plan allows 5 WAF custom rules. When full, PATCH an existing block rule to
   `(old) or (new)` instead of adding one.
Verify: a second anonymous curl shows `cf-cache-status: HIT`; a curl with
`Cookie: wordpress_logged_in_x=y` shows `DYNAMIC`. Cloudflare does not purge on WordPress
updates, so 1800 s is the staleness ceiling unless a purge-on-publish plugin is installed.

**Every paid-ad click is a cache MISS.**
-> `fbclid`, `gclid`, `utm_*` and friends make each URL a new cache key.
-> A Transform Rule (`http_request_transform`) that rewrites the query with
`remove_query_args(http.request.uri.query, "fbclid", "gclid", "gclsrc", "wbraid", "gbraid", "dclid", "gad_source", "msclkid", "ttclid", "twclid", "li_fat_id", "epik", "srsltid", "_ga", "_gl", "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "utm_id")`,
paired with the cache rule. The browser URL keeps the parameters, so client-side analytics
still read them. Skip it if anything server-side reads those parameters. Verify with five
different `?fbclid=` values: first MISS, the rest HIT.

**About 150 CSS and JS 404s per page after every purge.**
-> Breeze minify or combine is on while Cloudflare caches HTML: a purge deletes the minified
files, the edge keeps serving HTML that references them, and they only regenerate on a fresh
PHP render.
-> Keep Breeze file optimization off while Cloudflare caches HTML without purge sync.

**Facebook share cards show a blank image.**
-> A country-based managed challenge also challenges `facebookexternalhit`, which fetches
from many regions; the image fetch gets the 403 page.
-> Exempt the verified crawler from the challenge only, so a spoofed user agent is still
challenged:
`... and not ((lower(http.user_agent) contains "facebookexternalhit" or lower(http.user_agent) contains "facebot" or lower(http.user_agent) contains "meta-externalagent") and (cf.client.bot or ip.geoip.asnum eq 32934))`.
You cannot verify this with curl (you are not the crawler); use Facebook's Sharing Debugger,
"Scrape Again", after purging the page and image URLs.
