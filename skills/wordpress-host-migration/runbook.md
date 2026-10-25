# Runbook: restricted shared host -> managed cloud host behind Cloudflare

Tick every box in order. Placeholders: `<domain>`, `<old-origin-ip>`, `<new-origin-ip>`,
`<zone_id>`, `<account_id>`, `<prefix>` (WordPress table prefix). `$CF_API_TOKEN` is set in
the environment of whoever runs `cf-api.py`; it is never typed into a command.

## Phase 0: inventory

On an FTP-only host there is no wp-cli. Collect what you can from the panel and the
exporter's `info` call now, and run the DB queries below against the first full pull once it
is built on the new app (phase 2): it is a faithful copy.

- [ ] DNS: export or copy every record from the current DNS panel (A, AAAA, CNAME, MX, TXT,
      SRV). Note the current authoritative nameservers: `dig +short NS <domain>`.
- [ ] Mail: MX provider; how WordPress sends (PHP `mail()` or an SMTP plugin and which
      account); which From address forms use; how the new host relays outbound mail.
- [ ] Live forms, by real entries:
      ```sql
      SELECT form_name, COUNT(*), MAX(created_at) FROM <prefix>e_submissions GROUP BY form_name;
      SELECT form_id, COUNT(*), MAX(date_created) FROM <prefix>gf_entry GROUP BY form_id;
      ```
      Open a sample of recent entries per form: a busy form can be all spam.
- [ ] Form routing: find Elementor forms in `_elementor_data` and record `submit_actions`,
      `email_to`, `email_to_2`, `webhooks` per form. A global widget's settings live in its
      template post (`elementor_library`), not in the pages that embed it.
      ```sql
      SELECT post_id FROM <prefix>postmeta WHERE meta_key = '_elementor_data' AND meta_value LIKE '%submit_actions%';
      ```
- [ ] Webhooks: for each URL, who owns the receiving account. Flag any you don't control.
- [ ] Plugins: page cache (WP Rocket, Breeze), object cache, SMTP, security, premium plugins
      with `vendor/`. `wp plugin get <slug> --field=status` for exact status; grepping
      `wp plugin status` for "active" also matches "Inactive".
- [ ] Cron: WP-cron only, or a host cron job calling `wp-cron.php`.
- [ ] Redirects: plugin (DB, travels), `.htaccess` (file, travels, and goes live on Apache),
      host panel or nginx config (does not travel, recreate deliberately).
- [ ] `.htaccess`: read it. On an nginx-only source its rules never ran.
- [ ] Old host: is FTP IP-allowlisted in the panel? Does its firewall drop bursty clients?
      Does it geo-block during maintenance?
- [ ] Choose the check pages: home, the top landing pages, one service or product page, one
      blog post, `/sitemap_index.xml` (or your sitemap), `/robots.txt`.

## Phase 1: export

- [ ] Create the new app (empty WordPress) first; the pulls run on its server.
- [ ] Create a private dir outside the web root on the new server (Cloudways apps have
      `private_html/`), e.g. `<app>/private_html/mig`, copy the scripts there and
      `chmod 700` the `.sh` files.
- [ ] Generate the token on the new server. Only the hash leaves it:
      ```bash
      umask 077
      t=$(openssl rand -hex 32)
      printf 'X-Mig-Token: %s\n' "$t" > <mig-dir>/token.hdr
      printf %s "$t" | sha256sum | cut -d' ' -f1    # paste into exporter.php TOKEN_SHA256
      unset t
      ```
- [ ] In `exporter.php` set `TOKEN_SHA256` and `EXPIRES` (an epoch shortly after the planned
      cutover). Save it as `mig-<random-hex>.php`.
- [ ] Upload it to the old docroot by FTP from an allowlisted IP. One file, one connection.
- [ ] Write `<mig-dir>/mig.env` on the new server (read by `pull-site.sh` and `build-app.sh`):
      ```bash
      DOMAIN=<domain>
      OLD_IP=<old-origin-ip>
      MIG=<mig-dir>
      DOCROOT=<new docroot as the shell sees it>
      OLD_ROOT=<old absolute docroot, from the exporter info "root">
      FPM_ROOT=<new docroot as PHP-FPM sees it>
      MARKER=<any unique string, e.g. newhost-<app>>
      OBJECT_CACHE_PLUGIN=<slug of the host's object-cache plugin, or empty>
      ```
- [ ] Sanity check, from the new server: PHP and MySQL versions, prefix, table count, disabled
      functions:
      ```bash
      curl -sS --resolve <domain>:443:<old-origin-ip> -H @<mig-dir>/token.hdr "https://<domain>/mig-<hex>.php?m=info" | jq '{php, mysql, prefix, disabled, root, tables: (.tables | length)}'
      ```
- [ ] Full pull: `set -a; . <mig-dir>/mig.env; set +a; <mig-dir>/pull-site.sh mig-<hex>.php`.
      Require `verify: OK` and the DB completion marker.
- [ ] Record the pull time. `SINCE` for the cutover delta = that epoch minus 3600.

If the export must go over FTP after all (exporter blocked), read the bulk-FTP section of
[host-gotchas.md](host-gotchas.md) first: at most 2 connections, resumable by `SIZE`, long
backoff on disconnect.

## Phase 2: build

- [ ] `build-app.sh <mig-dir>/latest` (full mode, with `mig.env` loaded as for the pull).
      Require `row counts match` and a final line with `home https://<domain> | blog_public 1 |`.
- [ ] Reset file ownership to the app user (Cloudways: "reset permissions" with owner = app
      user, in the panel or API).
- [ ] PHP-FPM: copy per-app settings that the source needed (`memory_limit` and other
      limits) into the new app's PHP settings.
- [ ] PHP version: if newer than the source, run the PHP 8 checks in host-gotchas.md.
- [ ] Now run the phase 0 DB queries on this copy.
- [ ] Do not submit forms or trigger cron here unless the staging guards are in place.

## Phase 3: verify on the new origin

- [ ] `cutover.sh <config> pages <new-origin-ip> -k` passes (all pages 200, no temporary
      domain in any body, marker matches, www 301s to the apex).
- [ ] Each check page twice as a plain URL and twice with `?cachebust=<epoch>`; confirm the
      page cache actually writes (WP Rocket adds an HTML footer comment to cached pages).
- [ ] wp-admin loads (browser with `--host-resolver-rules=MAP <domain> <new-origin-ip>`).
- [ ] Optional: a temporary mu-plugin error logger with a random filename while you browse;
      remove it afterwards.
- [ ] Visual pass: scroll or move the mouse before judging lazy-loaded images.

## Phase 4: DNS and email prep

- [ ] Token scope: `Zone:Zone:Edit` on all zones of the account (to create the zone), DNS
      edit and zone settings edit on the new zone.
- [ ] Create the zone and note the nameservers Cloudflare gives it:
      ```bash
      cf-api.py POST /zones '{"name":"<domain>","account":{"id":"<account_id>"},"type":"full"}'
      cf-api.py GET /zones/<zone_id> | jq -r '.result.name_servers[]'
      ```
- [ ] Mirror every record DNS-only (`"proxied":false`), one call per record:
      ```bash
      cf-api.py POST /zones/<zone_id>/dns_records '{"type":"A","name":"<domain>","content":"<old-origin-ip>","proxied":false,"ttl":1}'
      ```
- [ ] Add the email set from [email-dns.md](email-dns.md) (SPF include merged into the one
      existing SPF record, 2048-bit DKIM, tracking CNAME, DMARC `p=none`).
- [ ] Compare old and new answers. Expected differences: only the email records you added.
      If the zone's nameserver does not answer for a pending zone, compare against the API
      listing (`GET /zones/<zone_id>/dns_records`) instead.
      ```bash
      OLD_NS=<old-authoritative-ns>
      CF_NS=<zone-nameserver>
      while read -r name type; do
        a=$(dig +short "$type" "$name" @"$OLD_NS" | sort)
        b=$(dig +short "$type" "$name" @"$CF_NS" | sort)
        if [ "$a" = "$b" ]; then echo "same $type $name"; else echo "DIFF $type $name"; diff <(echo "$a") <(echo "$b"); fi
      done <<'EOF'
      <domain> A
      www.<domain> A
      <domain> MX
      <domain> TXT
      _dmarc.<domain> TXT
      EOF
      ```
- [ ] SSL mode Full and Always Use HTTPS:
      ```bash
      cf-api.py PATCH /zones/<zone_id>/settings/ssl '{"value":"full"}'
      cf-api.py PATCH /zones/<zone_id>/settings/always_use_https '{"value":"on"}'
      ```
- [ ] Human: switch NS at the registrar. Check the registry's schedule; some ccTLDs apply NS
      changes only at fixed times and delegate with a 24 h TTL.
- [ ] Wait until the zone is active and the edge certificate is active. Plan the A-record
      flip for the next day when the registry delegation TTL is long.

## Phase 5: cutover

- [ ] Write the cutover config (fields listed at the top of `cutover.sh`; no secrets in it).
- [ ] `cutover.sh <config> preflight` by hand. All eight checks `ok`.
- [ ] No managed challenge or Bot Fight Mode on the new zone yet. The post-flip checks curl
      through Cloudflare, and a challenge reads as a failure: exit 3, a good cutover rolled back.
- [ ] Get explicit approval for the run. If the agent's permission system blocks the script,
      ask for a narrow allow rule for exactly this script path.
- [ ] Schedule `cutover.sh <config> run` once, on an always-on machine, at a quiet hour,
      output to a log file.
- [ ] Read the log. Exit 0: continue. Exit 1 or 2: fix and reschedule, nothing changed.
      Exit 3: DNS is back on the old host; read the failed check. Exit 4: a human, now.

## Phase 6: post-cutover

- [ ] Purge: host page cache (Varnish), WP Rocket files, object cache, Cloudflare. Then the
      bare URL twice through the public edge.
- [ ] HTTPS: `http://` 301s to `https://`, `www` 301s to the apex, certificate valid.
- [ ] Email: add and verify the domain in the relay add-on (all checks green), set the
      default sender, run the relay test, read `Authentication-Results`. See email-dns.md.
- [ ] Forms: settings match the inventory; JS tested with all POSTs aborted; one expected,
      labelled test entry; submissions row and action log present; client confirms inbox.
- [ ] Client admin user created, reset link sent, `admin_email` updated.
- [ ] Spot-check a few pages in a real browser on the public URL (Cloudflare may challenge
      the first automated navigation; a second one in the same browser usually passes).
- [ ] If WP Rocket's Cloudflare add-on is on and PHP is 8.x, apply the guard in host-gotchas.md.
- [ ] After a few stable days: edge cache and rate-limit rules, and the rule that strips
      tracking parameters (host-gotchas.md, Cloudflare section).

## Phase 7: decommission

- [ ] Remove the scheduled cutover job.
- [ ] Old host: the exporter URL returns 404.
- [ ] Old host stays untouched for one week.
- [ ] After that, and only with the client's go: cancel the old plan.
- [ ] Delete `<mig-dir>/token.hdr` and the `run-*` dirs (full DB dumps) on the new server.

## Rollback plan

| when | state | how to roll back |
|---|---|---|
| before the NS switch | nothing changed | nothing to do |
| after the NS switch, before the flip | zone mirrors the old DNS, old host still serves | fix records in the zone; reverting NS is a registrar change and can wait for registry timing |
| inside the cutover run | script watches the edge | automatic: A records back to the old IP, DNS-only, TTL 60 (exit 3) |
| after a successful run | new host serves, old host untouched | flip A @ and www back to `<old-origin-ip>`, `"proxied":false`, `"ttl":60` |

Manual flip:

```bash
id=$(cf-api.py GET "/zones/<zone_id>/dns_records?type=A&name=<domain>" | jq -r '.result[0].id')
cf-api.py PATCH "/zones/<zone_id>/dns_records/$id" '{"content":"<old-origin-ip>","proxied":false,"ttl":60}'
# repeat for www.<domain>
```

A rollback after real traffic strands whatever was written on the new host since the flip
(form entries, orders, comments). Export those before flipping back so they are not lost. The
new host's own daily backups cover later breakage on the new side; check their frequency and
retention before cutover instead of stacking an extra DB dump on a small server.
