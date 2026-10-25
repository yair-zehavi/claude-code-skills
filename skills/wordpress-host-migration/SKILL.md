---
name: wordpress-host-migration
description: >
  Move a live WordPress site from a restricted shared host (FTP only, no SSH or exec,
  IP allowlists, geo-blocking, rate limits) to a managed cloud host with Cloudflare in
  front, without losing leads, email or rankings. Covers the pre-move inventory, a
  token-gated PHP exporter for hosts where mysqldump and tar are disabled, server-to-server
  pulls with manifest and row-count checks, building the new app, Cloudflare zone setup
  and DNS cloning, transactional email DNS (SPF, 2048-bit DKIM, DMARC, default sender),
  a gated cutover script with automatic DNS rollback, post-cutover checks and
  decommissioning. Use when a site is changing hosts, when planning or running a DNS
  cutover, or when a freshly moved site shows 404 permalinks, unsigned mail, stale caches
  or 500s. Not for same-host staging deploys, domain or URL changes, or non-WordPress sites.
---

# WordPress host migration

## Why this exists

A host move rarely fails loudly. It fails in the corners: permalinks that 404 because the
old nginx host never needed a WordPress block in `.htaccess`, form notifications that now
leave from the new host's temporary domain and fail DMARC, a cache layer that keeps serving
the old page while every cache-busted check passes, a DNS flip with no scripted way back.
This skill turns the move into gated phases. The old host stays read-only throughout, every
irreversible step runs only after checks pass, and the cutover script puts DNS back by
itself if the new origin does not answer correctly through Cloudflare.

## Non-negotiable rules

1. **The old host is read-only until decommission.** Read it through the exporter only.
   Never edit, update or tidy it during the move. While it is untouched, rollback is one
   DNS change.
2. **A host move changes no URLs.** Same domain, same permalinks, same canonicals. Do not
   bundle redirects, slug changes or page retirement into the move. Retiring or 301-ing a
   live page is the client's decision and a separate task. If two pages compete, a
   `rel=canonical` on the old one is the stopgap, and any "this page holds ranking equity"
   claim gets checked in Search Console before anyone acts on it.
3. **An agent never submits a production lead form.** Not even "intercepted": a request
   route that was removed before the browser closed let a second submit through into the
   client's real lead pipeline. See
   [Forms without a fake lead](#forms-without-a-fake-lead).
4. **Gate every irreversible step.** Preflight changes nothing. A sync failure leaves DNS
   untouched. A post-flip failure rolls DNS back. A failed rollback stops with its own exit
   code that means "a human, now".
5. **Verify the bare URL.** Query strings bypass WP Rocket's static HTML and the other
   page caches, so `?cachebust=` shows a fixed page that visitors never get.
   Cache-busters only prove the origin renders. Never use a reserved WordPress query var
   as one (`?p=`, `?s=`, `?m=`, `?w=`, `?cat=`, `?page=`): `?w=123` routes to a week
   archive and returns a 404 page with HTTP 200.
6. **An auth failure is your own bug first.** Before telling anyone a password changed,
   rule out your tooling (see [Gotchas](#gotchas)).
7. **Secrets stay out of command strings, URLs and chat.** The exporter token travels as a
   header read from a file that exists only on the new server. Never build
   `CMD="... $PASS ..."`: zsh does not word-split it and prints the whole string, secret
   included, in `command not found`. A password passed as an argument shows up in `ps`.
8. **Mail is part of the site.** The move is not done until a message sent from the new
   server arrives with `dkim=pass` for the site's own domain.

## Procedure

Phases run in order. The full checklist with commands is in [runbook.md](runbook.md). The
scripts are in [scripts/](scripts/); each one documents its own config at the top.

### 0. Inventory (changes nothing)

- **DNS:** every record at the current provider (A, AAAA, CNAME, MX, TXT, SRV), including
  verification TXT records. Note who is authoritative today; restricted hosts often run the
  DNS as well.
- **Mail:** where mailboxes live (MX), how WordPress sends today (PHP `mail()` through the
  host, or an SMTP plugin) and how the new host sends. A managed host may relay every app on
  the server through one provider (a Cloudways server with the Elastic Email add-on relays
  through Elastic Email), which decides what SPF and DKIM must say.
- **Which form is actually live.** Count real entries per form plugin table, not form
  definitions: Elementor `{prefix}e_submissions`, Gravity Forms `{prefix}gf_entry`.
  Observed: a Gravity form had collected only spam for weeks while the real lead form was an
  Elementor global widget.
- **Where each form sends:** Elementor `submit_actions`, `email_to`, `email_to_2`,
  `webhooks`. Record every webhook URL and who owns the receiving account. A webhook can point
  at an automation account you do not control: note it, don't assume.
- **Plugins and cron:** page-cache plugins (WP Rocket, Breeze), object cache, SMTP and
  security plugins, premium plugins with bundled `vendor/` code, WP-cron or host cron.
- **Redirects and server rules:** where each redirect lives. Plugin redirects are in the DB
  and travel; host-panel and nginx rules do not. An nginx-only host ignores `.htaccess`, so
  its copy has no `# BEGIN WordPress` block and may hold rules that never ran. On an
  Apache-based new host they become live.
- **Old-host access:** FTP allowlist, firewall behaviour under load, geo-blocking, PHP
  version and disabled functions.

### 1. Export without SSH

Bulk FTP is too slow and too fragile for a whole site. Each file and each directory listing
opens a new TLS data channel (about 1.7 s per file observed), and four or five parallel
connections plus a backup job coincided with a whole shared server refusing HTTP and FTP
for minutes. Upload one PHP file instead and pull everything server to server.

- [scripts/exporter.php](scripts/exporter.php) streams a GNU tar of the docroot and a
  PHP-native SQL dump from a consistent snapshot (InnoDB tables), because hosts that
  disable `exec` and `proc_open` leave no `mysqldump` or `tar`. Modes: `info`, `list`
  (manifest), `files`, `db`, `selfdelete`, plus `since=<epoch>` for delta runs.
- It answers only requests with the right `X-Mig-Token` header (it stores only the SHA-256),
  returns a bare 404 to everything else, and deletes itself on the first request after its
  `EXPIRES` epoch. Give it a random filename.
- Generate the token on the new server and keep it there. Upload the exporter by FTP from an
  allowlisted IP: one file.
- [scripts/pull-site.sh](scripts/pull-site.sh) runs on the new server, pinned to the old
  origin with `--resolve`, so the same command still works after the DNS flip. It checks the
  dump ends with its completion marker and that the extracted files match the manifest by
  path and size.

### 2. Build the new app

[scripts/build-app.sh](scripts/build-app.sh), run on the new server against the latest pull:

- First run: save the host's own `wp-config.php`, salt file and `.htaccess`, then drop the
  fresh install's tables.
- rsync the pulled site into the docroot, excluding config, `.htaccess`, cache dirs and the
  new host's object-cache drop-in. `--omit-dir-times` is required: rsync as the server's
  master user into app-owned dirs fails "failed to set times" with exit 23. Reset file
  ownership to the app user afterwards.
- Merge config with [scripts/merge-wp-config.php](scripts/merge-wp-config.php): keep the new
  host's DB credentials and Redis settings; take all eight salts (or abort), the table
  prefix, `WPLANG` and memory limits from the source.
- Rebuild `.htaccess` as the source rules plus the new host's `# BEGIN WordPress` block.
  Without it every permalink 404s on Apache.
- Rewrite WP Rocket's `advanced-cache.php`, which hardcodes the old absolute docroot. Use the
  path PHP-FPM sees on the new host (Cloudways: `/home/<server>.cloudwaysapps.com/<app>/public_html`,
  not the master user's `/home/master/applications/...` view), or `open_basedir` blocks it.
- Import the DB and compare per-table row counts between the dump and the live DB. Flush the
  object cache, Elementor CSS and WP Rocket cache dirs.
- Write `.well-known/origin-marker.txt` so later checks can prove which host answered.
- Match PHP-FPM settings by hand. Per-app values such as `memory_limit` live in the host
  panel, not in WordPress: a copy that got a 256M default while the source ran 2048M
  returned 500 on every page.
- If the new host runs a newer PHP major than the old one, the move is also a PHP upgrade:
  run the PHP 8 checks in [host-gotchas.md](host-gotchas.md).

Until cutover the new app is a second live copy with real webhooks and a real mail relay.
Keep pre-cutover testing to page GETs. To exercise cron or forms there, add the staging
guards from [host-gotchas.md](host-gotchas.md) first and remove them before cutover.

### 3. Verify on the new origin before DNS

No temporary URL needed: send the real domain to the new IP.

```bash
curl -sk --resolve <domain>:443:<new-origin-ip> -o /dev/null -w '%{http_code}\n' "https://<domain>/?cachebust=$RANDOM"
curl -sk --resolve <domain>:443:<new-origin-ip> "https://<domain>/.well-known/origin-marker.txt"
```

Pass: every check page returns 200, no body mentions the host's temporary domain (for
example `cloudwaysapps.com`), the marker matches, `www` 301s to the canonical host,
`blog_public` is 1, and the active plugin count matches the source.
`scripts/cutover.sh <config> pages <new-origin-ip> -k` runs the page checks. For a visual
check, map the domain in headless Chromium (`--host-resolver-rules=MAP <domain> <new-origin-ip>`,
ignore certificate errors). WP Rocket's Delay JS keeps lazy images, logo and hero blank in
headless screenshots until a scroll or mouse move, so simulate one before judging.

### 4. DNS and email prep

- Create the Cloudflare zone. A token that creates zones needs `Zone:Zone:Edit` on all
  zones of the account, not just DNS edit on one zone.
- Mirror every old record **DNS-only**, so the zone answers exactly like the old DNS.
  Compare answers name by name between the old nameserver and Cloudflare's
  ([runbook.md](runbook.md) has the loop).
- Add the transactional email set: SPF include, 2048-bit DKIM, tracking CNAME, DMARC
  `p=none`. Details, and why the old shared 1024-bit key fails, in [email-dns.md](email-dns.md).
- Set SSL mode Full and Always Use HTTPS (inert until records are proxied).
- A human switches nameservers at the registrar. Some ccTLD registries apply NS changes only
  at fixed times of day and delegate with a 24 h TTL (observed on `.il`): switch NS first,
  flip A records a day later.

### 5. Gated cutover

[scripts/cutover.sh](scripts/cutover.sh): `preflight` changes nothing, `run` does the rest.

1. Preflight: zone active, edge certificate active, TLD registry delegates to Cloudflare,
   three public resolvers see Cloudflare NS, A records still on the old IP, MX served by
   Cloudflare, new app answers wp-cli, exporter reachable from the new server.
2. Final delta pull (DB plus files changed since the first full pull, with a 1 h margin) and
   build. Any failure: exit 2, DNS untouched. The delta never deletes: files removed on the
   old host after the full pull, and any plugin auto-update applied to the pre-cutover copy,
   survive on the new host. Freeze plugin and core updates on both sides between the full
   pull and the cutover, and diff the plugin list after.
3. Page checks against the new origin IP. Failure: exit 2.
4. Flip apex and `www` A records to the new IP, proxied.
5. After 30 s, resolve the domain at the zone's own nameserver. The answer must be a
   Cloudflare edge IP (neither origin), and the page checks must pass through it. Three
   attempts, 30 s apart.
6. On failure: flip back to the old IP, DNS-only, TTL 60, exit 3. If that API call fails:
   exit 4.
7. On success: tell the exporter to delete itself and confirm it now 404s.

| exit | meaning |
|---|---|
| 0 | live on the new host |
| 1 | preflight failed, nothing changed |
| 2 | sync, build or origin check failed, DNS untouched |
| 3 | post-flip checks failed, DNS rolled back |
| 4 | rollback failed: a human, now |

Run `preflight` by hand first. Run `run` from an always-on machine as a one-shot scheduled
job at a quiet hour, logging to a file, and remove the job afterwards.

### 6. Post-cutover

- **Caches:** purge every layer (host Varnish, WP Rocket files, object cache, Cloudflare),
  then check the bare URL through the public edge. See [Verification](#verification).
- **Email:** add and verify the domain in the host's relay add-on, set the app's default
  sender to a mailbox on the domain, run the relay test ([email-dns.md](email-dns.md)).
- **Forms:** see below.
- **Admin access:** `wp user create <login> <mailbox>@<domain> --role=administrator --porcelain`
  (porcelain prints no password), then send a reset link by calling
  `retrieve_password('<login>')` from a `wp eval-file` script; `wp user reset-password` sent
  no link. Change the admin email with `wp option update admin_email <mailbox>@<domain>` plus
  `wp option delete new_admin_email` to skip the confirmation mail for a pending change. WordPress
  notifies the old address.
- **Edge hardening,** once stable: the Cloudflare Free-plan cache and rate-limit rules,
  and the rule that strips tracking parameters, in [host-gotchas.md](host-gotchas.md).

### 7. Decommission

Remove the scheduled job. Confirm the exporter returns 404 on the old host (its `EXPIRES`
epoch is the backstop). Keep the old host untouched for a week as the rollback target.
After the old host is gone, delete the token file and the pull run dirs on the new server:
each run holds a full DB dump.

## Forms without a fake lead

- Read-only first: compare each form's actions, recipients and webhooks with the inventory.
- Test front-end JS without submitting: inject it, and route-abort **every** POST to the
  site in a browser context that stays routed until `close()`. Never `unroute`. Fill fields
  that drive lead routing with a value that cannot match (for example a non-existent
  postcode), so a leak cannot reach a real recipient.
- The mail leg is proven by the relay test, not by a form.
- End to end: one test entry that the client expects and that is labelled as a test. Check
  the submissions row and the action log (Elementor `e_submissions_actions_log`). A "success"
  there only means the email, redirect or webhook was handed off; the client confirming the
  inbox is the real pass.

## Verification

```bash
# Origin, past Cloudflare (use when the edge returns a 403 "Just a moment" challenge)
curl -sk --resolve <domain>:443:<origin-ip> -I "https://<domain>/"

# Public edge, bare URL, twice: second response should be a HIT, age should be small after a purge
curl -sI "https://<domain>/" | grep -iE 'cf-cache-status|x-cache|^age|cache-provider'

# Redirects
curl -sI "http://<domain>/"       | grep -i '^location'   # -> https://<domain>/
curl -sI "https://www.<domain>/"  | grep -i '^location'   # -> https://<domain>/

# DNS
dig +short NS <domain> @one.one.one.one
dig +short A <domain> @<zone-nameserver>        # a Cloudflare edge IP once proxied
dig +short MX <domain> @<zone-nameserver>
dig +short TXT api._domainkey.<domain>
dig +short TXT _dmarc.<domain>
```

Mail: the received relay-test message must show `dkim=pass header.i=@<domain>` in its
`Authentication-Results`. Forms: submissions row plus action log plus the client's inbox.

## Gotchas

Symptom -> cause -> fix. The full list, grouped by host side, is in [host-gotchas.md](host-gotchas.md).

- **Every permalink 404s, home page fine** -> the nginx-only source had no WordPress block
  in `.htaccess` -> merge the new host's `# BEGIN WordPress` block.
- **Old host "down" from every place you test: identical stock nginx 500 on every URL, only
  `server`, `date`, `content-type`, `content-length` headers** -> the restricted host may
  geo-block foreign IPs during maintenance -> confirm from inside the host's country with a
  cache-buster before calling an outage.
- **`Permission denied (publickey,password)` with correct credentials** -> `sshpass` with
  `sftp -b` (batch mode never prompts, so the password is never sent), or fail2ban after
  about 4 connections in 5 minutes -> run a plain `ssh ... exit` with the same credentials.
  If it works, feed sftp commands on stdin. If both fail, the IP is locked for about 30
  minutes: get it allowlisted, and don't retry in a loop (each retry resets the timer).
- **Port 22 times out while 443 answers** -> server SSH is IP-allowlisted -> the account
  owner adds your current egress IP.
- **Mail arrives unsigned or fails DMARC** -> domain not verified in the relay add-on,
  default sender still on the host's temporary domain, or a 1024-bit DKIM key ->
  [email-dns.md](email-dns.md).
- **Old content keeps showing after a purge while `?cachebust=` looks right** -> host
  Varnish or WP Rocket static files -> purge each layer, verify the bare URL's `age:` is 0.
- **`curl -X PURGE` returns 400** -> Cloudflare rejects the method -> purge Varnish from the
  origin's own localhost (host-gotchas.md).
- **Every page 500s on the new app** -> per-app PHP-FPM settings not carried over -> copy
  `memory_limit` and friends from the source.
- **Site-wide 500 after an Elementor auto-update, PHP 8, behind Cloudflare** -> WP Rocket's
  Cloudflare add-on throws a `substr()` TypeError -> cast or guard, see host-gotchas.md.
- **Elementor custom code renders as visible page text after a wp-cli write** -> wp-cli ran
  as user 0 without `unfiltered_html`, so `<script>` was stripped -> pass `--user=<admin-id>`
  and read the meta back.
- **One critical error right after an FTP folder swap** (maintenance on an FTP-only host,
  never during a move) -> stale opcache paths -> check twice before rolling back.

## When to stop and ask a human

- Nameserver changes at the registrar, or anything that changes who controls the domain.
- Cloudflare token scope changes (zone creation, WAF edits).
- The agent's permission system blocks the build (rsync `--delete` plus a DB import on a
  production server) or a WAF write, even after a "go" in chat. Ask for a narrow allow rule
  for exactly that script path. Never route around the block.
- Exit 4 from the cutover script.
- A webhook or automation from the inventory that lives in an account you don't control.
- DKIM stays red after the 2048-bit key is published and the domain re-verified.
- Any real form submission, any page retirement or redirect, any restore from a host backup.
- SSH or SFTP IP allowlisting on either host.

## Files

- [runbook.md](runbook.md): the phase checklist with commands, config files and rollback plan.
- [email-dns.md](email-dns.md): SPF, DKIM, DMARC, relay verification, default sender, mail logs.
- [host-gotchas.md](host-gotchas.md): old-host, new-host, WordPress and Cloudflare gotchas in full.
- `scripts/`: `exporter.php`, `pull-site.sh`, `build-app.sh`, `merge-wp-config.php`,
  `cf-api.py`, `cutover.sh`.
