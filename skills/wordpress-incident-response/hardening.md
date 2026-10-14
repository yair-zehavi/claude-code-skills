# Hardening

## 1. Block PHP execution in uploads

The standard defense against a webshell dropped into uploads. Put this in
`wp-content/uploads/.htaccess`:

```apache
<Files "*.php">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</Files>
```

- It blocks **direct HTTP execution** only, not PHP `include()`/`require()`. Legitimate
  PHP that other code includes from uploads (PDF library fonts, security-plugin data,
  optimization-plugin test files) keeps working, so breakage risk is close to zero. It
  also means a backdoor elsewhere can still include a file from uploads: this is one
  layer, not a cleanup.
- It needs Apache, or Apache behind nginx, honoring `.htaccess` (Cloudways does). An
  nginx-only host ignores `.htaccess` entirely; ask the host for the equivalent rule.
- Verify: an existing PHP file under uploads returns 403, a static file in uploads
  returns 200, the homepage is unaffected.
- Fleet survey, apps that lack it:

```bash
f="$WP_PATH/wp-content/uploads/.htaccess"
if [ -f "$f" ] && grep -qi 'php' "$f" && grep -qiE 'deny|denied|Require all' "$f"; then
  echo "ok"; else echo "MISSING"; fi
```

Observed in 2026: about half a fleet already had it from an earlier standard deploy,
the rest did not. Survey; do not assume.

## 2. Isolation between apps on a shared server

Measured on a Cloudways server in 2026, and worth measuring on yours:
- Every `wp-config.php` was world-readable (`664`) and app home folders were `755`
  (traversable). At the filesystem level, every site's DB credentials were readable by
  every other app on the box.
- What actually contains an infected app is PHP configuration:
  - `open_basedir` confines each app's PHP to its own folder.
  - `disable_functions` blocks `exec`, `system`, `passthru` and similar, so a webshell
    cannot shell out and `cat` a sibling's world-readable config, which would skip
    `open_basedir` entirely.
- The server-level SSH user could not read the PHP-FPM pool configuration, so these
  could not be verified from the shell. Check them in the host console (application
  PHP settings) or ask support.
- Tightening `wp-config.php` to `600` fights the host's shared web-group model and
  breaks WP-CLI for the server-level user. Prefer `open_basedir` plus
  `disable_functions`, plus separate servers for crown-jewel sites.
- On a fleet server, a management dashboard connected to most of the apps is a bigger
  fan-out risk than any neighbour app (section 3). Do not host it beside them.

## 3. Fleet management tools

A management dashboard with a child plugin on every site is a remote code-push channel
into all of them. Compromise the dashboard and you own the fleet; it also explains
"it is on all sites" and "it comes back after cleaning".
- If you do not need it, remove the child plugin everywhere and delete the dashboard.
- If you keep it, treat the dashboard as the most sensitive asset you run: its own
  server, 2FA, minimal admins, updates first.
- Check the child plugin's options on each site (connected server, public key,
  connected admin, site id). The site id roughly tells you how many sites that
  dashboard manages.

## 4. Edge rules (Cloudflare)

- **Block the loader endpoint** on every zone, as a WAF custom rule with action Block:

```
(http.request.uri.path contains "<relay-path>")
```

  `<relay-path>` is the same-origin endpoint, planted by the attacker, that the
  fake-verification loader POSTs to.

- **Turn on the managed ruleset.** It blocks known plugin-exploit patterns in front of
  the entry vector, not only the symptom. The full Cloudflare Managed Ruleset needs a
  paid plan; Free zones get the smaller Free Managed Ruleset.
- **Free plan limits** (observed in 2026): at most 5 WAF custom rules per zone. When the
  slots are full, fold a new block expression into an existing block rule as
  `(<old expression>) or (<new expression>)` with a PATCH instead of adding a rule.
  Rate-limiting rules on Free reject `http.cookie` in the expression.
- **Bot bursts saturating a shared origin**: an edge HTML cache rule plus an
  origin-only rate limit absorbs them. Shapes that worked:
  - Cache rule: cache eligible, edge TTL overriding the origin at 1800 s, browser TTL
    respecting the origin. Exclude `/wp-admin`, `/wp-login`, `/wp-json`,
    `/xmlrpc.php`; queries containing `wc-ajax`, `add-to-cart`, `elementor`,
    `preview`, `customize_changeset`, `nocache`; cookies containing
    `wordpress_logged_in_`, `comment_author`, `wp-postpass_`. WooCommerce sites also
    exclude `/cart`, `/order`, `/checkout`, `/my-account` and the `woocommerce_` and
    `wp_woocommerce_session_` cookies.
  - Rate limit: characteristics IP plus colo, 30 requests per 10 s, block for 10 s,
    counting requests to origin only; skip verified bots and the `/wp-admin/`,
    `/wp-json/`, `/wp-content/`, `/wp-includes/` paths.
  - Verify: a second anonymous request shows `cf-cache-status: HIT`; a request with a
    logged-in or cart cookie shows `DYNAMIC`.
  - An edge cache that overrides the origin TTL keeps serving whatever it cached, an
    infected page included. Purge after every cleanup.
- **Your own tooling behind challenges**: a Skip rule placed first, keyed on a secret
  header and limited to the paths your automation needs, lets scripts through without
  weakening the zone for everyone else. That header value is a credential; rotate it
  if it leaks. On the Free plan, plain Bot Fight Mode ignores Skip rules.
- **Next layer:** lock the origin to accept only CDN IP ranges, put a header or access
  gate in front of `wp-admin` and `wp-login.php`, and add security headers.
- **Making rule changes as an agent**: ad-hoc API writes to WAF rules get refused by
  Claude Code's auto-mode safety filter as security-weakening. Route them through one
  reviewed script with a narrow allow rule; it should list, add, delete, enable and
  disable rules and keep the token out of the command line.

## 5. CDN account hygiene

A compromised CDN account gives edge injection on every zone (Workers, Snippets, Zaraz,
transform rules) and keeps the attacker in after the sites are clean.
- 2FA for every member; remove members you cannot name.
- Scoped API tokens only; list them, roll any you cannot account for, and remember CLI
  OAuth grants are tokens too.
- Review the audit log after any incident, even when "only I have access".

## 6. Plugins and advisories

- Remove file-manager plugins. Keep upload-capable plugins (form builders with file
  fields, page builders, membership plugins, sliders) current: the entry vectors seen in
  2026 were old builds of exactly these.
- For an advisory: confirm the affected and fixed versions independently (vendor
  changelog or advisory, a reputable security publication), not from the alert email;
  audit the fleet ([scripts/fleet-advisory-audit.sh](scripts/fleet-advisory-audit.sh));
  review
  administrators at the same time, since many advisories end in "attacker creates an
  admin".
- Premium plugins are invisible to wp.org update checks and checksum verification;
  track them separately.

## 7. Accounts and server access

- 2FA on every WordPress administrator. Know that some security plugins disable
  application passwords site-wide when they harden, which breaks REST automation; plan
  for WP-CLI over SSH.
- SSH: key auth instead of passwords; allowlist admin IPs per server where the host
  supports it. A server with port 22 open to the world logged thousands of brute-force
  attempts a week in 2026; a firewall rule limited to admin IPs ends that.
- Turn on the host's automatic security updates where it offers them.
- Adjacent servers running containers (automation, CRM, chat tools): confirm the
  Docker API is not exposed on TCP (port 2375), no container runs privileged, and none
  mounts `docker.sock`.

## 8. Monitoring

- Subscribe to the host's malware and vulnerability alert events.
- Watch Search Console security issues for every property.
- A scheduled fleet render sweep (origin, Windows user agent) catches the next loader
  before visitors report it.

## 9. Backups and rollback

Backups are the rollback. Decide which layer you will use before you need it.

| Layer | What it gives you | Caveat |
|---|---|---|
| Host daily backups (observed: daily, 29-day retention, overnight window) | Whole-app restore | Rolls back to last night; drops the day's leads, orders and edits |
| Folder copy of what you are about to change | One-minute rollback of a plugin or theme | Cheap; take it every time |
| Off-host encrypted database dumps | Survives a host account compromise | Needs its own key management |
| VM snapshot (self-managed servers) | Rollback point before risky remediation | Take it before the first disruptive step |

- **No extra DB dump before routine changes** on a host with daily backups. A large dump
  on a small server, stacked right before a heavy plugin update, contributed to a
  database crash in 2026. On hosts without managed backups, take the dump.
- **Pick the restore point with the timeline.** Retention bounds how far back you can
  go, and a backup taken after the intrusion carries the backdoor. Date the intrusion
  first (file mtimes, admin registration dates, logs).
- **Off-host dump pattern that held up across 100+ apps:**
  - Stream `mysqldump | gzip` over SSH under `nice`/`ionice`; nothing is written on the
    client server.
  - Read DB credentials by parsing `wp-config.php` with PHP's tokenizer, because WP-CLI
    fatals on many apps under the server-level user.
  - Wait before each dump when load is high (load1 at or above 2x the CPU count: retry
    every 60 s, up to 5 times). Run before the host's own backup window, not during it.
  - Encrypt symmetrically (gpg, AES256); keep the passphrase in a file only the backup
    job can read, plus a copy in a password manager.
  - Count a dump as good only after decrypting it and finding the dump-completed
    marker at the end.
  - Upload to object storage with a bucket lock (30 days), so a stolen write key cannot
    delete history. Never set an indefinite lock: it also blocks lifecycle deletes.
    Give every run its own key prefix, because a locked bucket refuses overwrites and a
    same-day re-run would otherwise fail.
  - Lifecycle: daily copies expire after about 35 days, monthly ones after about 400.
  - Anyone holding a token with bucket-admin rights can remove the lock. Keep that
    token separate from the upload key.
- **Restore** a dump with:

```bash
gpg --batch --pinentry-mode loopback --passphrase-file <passphrase-file> -d <dump>.sql.gz.gpg \
  | gunzip > app.sql
```

  then import it into that app's database.
