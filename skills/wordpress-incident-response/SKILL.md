---
name: wordpress-incident-response
description: >
  Defensive incident response for a compromised or suspicious WordPress site, or a
  fleet of them on shared hosting: detection signals, triage, scoping (render sweep,
  file and database backdoor hunt, mu-plugins, uploads), containment, cleanup,
  credential rotation, hardening, rollback and evidence logging. Includes the
  ClickFix / EtherHiding fake-verification pattern and the fan-out risk of a
  site-management dashboard. Use when visitors report a fake "verify you
  are human" page, a site is defaced, Search Console flags deceptive pages, the host
  or a security plugin raises a malware alert, an admin account nobody recognises
  appears, an infection "comes back after cleaning", or a plugin advisory needs a
  fleet-wide exposure check. Not for sites you do not own or manage, not for
  penetration testing, and not for cleaning the PC of a visitor who ran the payload.
---

# WordPress incident response

## Why this exists

A compromised WordPress site rarely looks compromised from where the operator stands.
The loader may render only for real Windows browsers that got through a CDN
challenge. The payload may live on a blockchain, so grepping files and the database
for the text the visitor saw finds nothing. The backdoor may sit in `mu-plugins`,
which the plugin list never shows, and the host's cleanup may reinstall core without
touching `wp-content`. Server-side scans come back clean while visitors are being
infected. This skill is an order of work that finds what those scans miss on a fleet
of managed sites on shared hosts.

## Non-negotiable rules

1. **Nobody follows the on-screen steps.** A "verify you are human" page that asks for
   Win+R or Win+X, paste, Enter is the payload: it makes the visitor run a PowerShell
   or mshta command that infects their Windows PC. Tell the reporter and the client
   team this before anything else. Any PC where someone already did it is a separate
   incident.
2. **Preserve evidence before you change anything.** Save the rendered HTML, the
   loader, the network requests, every suspect file (copy, owner, mtime, size, hash)
   and the admin list. Attackers clean up after themselves: in the EtherHiding case
   the contract storage was wiped later and the payload could no longer be
   pulled. Quarantine (rename out of the load path) before you delete.
3. **Do not trust the dashboard, the scanner or the host's cleanup.** `mu-plugins` and
   dot-prefixed plugin folders never appear in the plugin list. A CDN
   anti-enumeration rule can make a REST user audit come back empty. A license-gated
   premium plugin can look up to date when its updater no longer serves builds. A
   host's core reinstall never touches `wp-content`. "Only I have access" to the CDN
   account is not a check; its members list, API tokens and audit log are. Verify
   against the filesystem, the database and the rendered page.
4. **Clean origin and edge, then purge every cache layer.** A cleaned origin behind a
   stale page cache or CDN cache is still infecting visitors.
5. **Know your rollback before touching anything.** Backups are the rollback. Confirm
   the host's backup schedule and retention, any off-host copies and VM snapshots
   first, and copy the folders you are about to change. Do not stack a heavy database
   dump on a struggling server.
6. **No secrets in command strings, argv, chat or logs.** Never build a command as a
   string that contains a secret; reference it only inside the command that actually
   runs, through a function or the environment. A password passed as a CLI argument
   is readable by any user on the host via `ps`. Rotated secrets never cross the chat.
7. **Read-only first, one connection per server.** Recon is grep, find, ls, curl and
   `wp ... list`. Writes come after the evidence is saved and the scope is known.
   Managed hosts can run aggressive fail2ban and per-server IP allowlists: one agent per
   server, never parallel sessions against the same host.
8. **Cut the fan-out channel before cleaning the sites.** If the infection is "on all
   sites" or "comes back", something with write access to all of them is still live:
   a management dashboard, a shared CDN account, a shared server. Cleaning sites first
   is wasted work.
9. **Defensive scope only.** Sites you own or manage. Confirm a backdoor by reading it,
   never by using it.

## Procedure

Work the phases in order. Each phase points to the reference file with the commands.

### 1. Detect
Signals seen in real incidents (first checks per signal in
[triage-checklist.md](triage-checklist.md)):
- Fake verification overlay, Windows only, invisible to server-side curl, "comes back
  after cleaning".
- Search Console "Deceptive pages" with no sample URLs.
- A defacement page; unknown PHP files or folders in the docroot.
- An administrator nobody recognises; zero-byte files in uploads (upload probes).
- A Search Console verification file in the docroot that is not yours (lets someone
  claim the property).
- Bursts of large POST responses to a core bootstrap file (observed once in 2026:
  `wp-load.php` with a random query string, gigabytes out to dozens of cloud-provider
  IPs) while the core file itself is unmodified. That points to something hooked into
  bootstrap emitting a payload: treat it as a lead and hunt.
- Host malware or vulnerability alerts; security-plugin emails about file changes.
- A plugin advisory. Verify it independently (vendor changelog or advisory, a reputable
  security publication), not from the alert email, then audit the fleet
  ([scripts/fleet-advisory-audit.sh](scripts/fleet-advisory-audit.sh)).

### 2. Triage
Answer these in the first hour ([triage-checklist.md](triage-checklist.md)):
- **What kind?** Client-side injection (loader in the rendered HTML), server-side
  backdoor or webshell, account compromise (rogue WP admin, CDN account), abuse
  traffic, or a mix.
- **One site or many?** List what the affected sites share: server, CDN account,
  management dashboard, plugin set. A shared factor is the fan-out suspect.
- **What can you roll back to?** Restore points and their dates.
- **What access do you have?** Server SSH (is your IP allowlisted?), app-level SFTP,
  the WP-CLI path, a CDN API token. Check the secrets store before declaring anything
  missing.
- Open the evidence log now, not at the end.

### 3. Scope
Find every foothold, not the first one. Commands: [hunt-commands.md](hunt-commands.md).
1. **Render sweep.** Fetch pages from the origin, skipping the CDN, with a Windows user
   agent, and grep the output for loader patterns. This catches render-time and
   obfuscated injection that a file grep misses. Sweep several page types per site,
   not only the homepage. Then load the site in a real browser through the CDN and
   dump inline scripts and network requests, because bots get the CDN challenge, not
   the page behind it.
2. **Backdoor hunt, literal and behavioral.** Grep for the IOC strings and for
   behavior: `eval`, `assert`, `create_function` or `call_user_func` near request
   superglobals or decoders, `require_once(hex2bin(...))`, blockchain RPC hostnames,
   `REQUEST_URI` routing to rpc or proxy names. Handlers are often obfuscated, so a
   literal grep alone misses them.
3. **Check these first:** `mu-plugins` (auto-loaded, absent from the plugin list);
   plugin folders diffed against `wp plugin list`; the tail of every theme's
   `functions.php`, active and inactive; near-core-named files in `wp-content` and the
   docroot; `wp_ajax_nopriv_` hooks; uploads (`*.php`, zero-byte files, form-upload
   temp folders); autoloaded options.
4. **Integrity.** `wp core verify-checksums` and `wp plugin verify-checksums --all`
   (wp.org plugins only); diff premium plugins and child themes against pristine
   copies. List PHP files modified since a date and explain every cluster: a core
   update touches many core files at once, security plugins write their own logs.
5. **Accounts.** Every administrator with registration date and email. Flag recent
   accounts, empty profiles, made-up email domains and free-mail addresses on a
   business site. Security services such as MalCare and BlogVault create temporary
   support admins, so an odd admin is a question for the owner, not an automatic
   delete.
6. **Entry vector by cross-similarity.** Inventory active plugins on every hacked site
   and diff against a known-clean site on the same stack. Plugins present on the
   hacked sites and absent from the clean one are the entry candidates; plugins on
   both are not the vector. Entry candidates observed in 2026: old WPBakery
   (`js_composer`), Gravity Forms 2.4.x, Ultimate Member, WP File Manager, Elementor
   Pro form file-upload. Old Revolution Slider builds were on the patch list too.
7. **Fan-out channels.** Management child-plugin options; CDN account members, API
   tokens, Workers, Snippets, Zaraz, rules and the audit log.
8. **False-positive discipline.** A soft-404 (error page served with HTTP 200) makes
   every shell-scanner probe look like a live webshell. Check the response-size
   distribution: one dominant identical size is a soft-404, not a shell.

### 4. Contain
- **Edge block first.** A CDN WAF custom rule, added on every zone, that blocks the
  loader's server-side endpoint cuts loader-to-payload before any file is touched.
  Turn on the CDN's managed ruleset to block known plugin-exploit patterns at the edge.
- **Cut fan-out.** Remove the management child plugin from every site and delete the
  dashboard if nothing needs it. If the CDN account is the attacker's access, rotate
  it now (phase 6), not after cleanup.
- **Quarantine** what you cannot remove yet: rename the file, or its parent folder, out
  of the load path.

### 5. Clean
Details, including app-owned files: [cleanup-and-rotation.md](cleanup-and-rotation.md).
- Remove the injector and the server-side handler, mu-plugin backdoors, docroot
  webshells and attacker folders, uploads webshells and probes, the attacker's
  verification file, unknown plugins and themes. Restore a defaced `index.php` from a
  pristine copy.
- Remove rogue admins once the owner confirms.
- Purge the page-cache plugin and the CDN cache, then re-run the render sweep.
- Run the residue sweep: anything with write access to the site could have pushed
  more than you found. Re-check admins, recently added plugins and themes, and
  checksum failures.
- On shared hosts, files the web app wrote are owned by the app user, often inside
  app-owned 755 folders, and the server-level SSH user cannot delete them. Use the
  application-level SFTP user.
- Patch or replace the entry plugins. Major jumps that run database migrations are not
  cleanly reversible: stage them.

### 6. Rotate
Rotate everything the attacker could have read or used: the CDN account password,
every API token including CLI OAuth grants, unknown members removed, 2FA enforced; WP
admin passwords with 2FA on every admin (check that a 2FA plugin exists at all); SSH
and SFTP passwords, preferably by moving to key auth. On a shared server where you
cannot confirm per-app isolation, include the secrets in sibling apps'
`wp-config.php`. A human enters new values in a silent prompt; they never pass through
chat. Handing secrets to another person:
[cleanup-and-rotation.md](cleanup-and-rotation.md#secure-secret-transfer).

### 7. Harden
Full list in [hardening.md](hardening.md). Minimum after any incident:
- Block PHP execution in `wp-content/uploads`. This stops direct HTTP execution of a
  dropped webshell; it does not block `include`/`require`.
- Confirm `open_basedir` and `disable_functions` per app on shared servers; move
  crown-jewel sites to their own server.
- Keep the edge block rule and the managed ruleset; lock down the CDN account.
- No management plugin with fleet-wide push unless its dashboard is treated as the
  most sensitive asset you own.
- Remove file-manager plugins; keep form-upload and page-builder plugins current.

### 8. Verify
Run the checklist below on every affected site.

### 9. Report
- Close the evidence log: timeline, IOCs, files and accounts with the action taken,
  entry vector and the evidence for it, rotations (what and when, never values),
  verification output.
- Put open follow-ups in a per-site task file so they survive the session: residue
  sweeps not yet run, plugins still to patch, rotations waiting on someone else.
- The client write-up is plain language: what happened, what was done, what they must
  do (passwords, 2FA). No tool names, no internal notes. A human sends it.

## Verification

"Clean" is a claim you prove per site, with output saved to the evidence log:
- [ ] Origin render sweep with a Windows user agent, several page types: 0 loader hits.
- [ ] Real-browser load through the CDN after the purge: no unknown inline script, no
      request to an unknown host or to the relay path (the same-origin endpoint the
      loader POSTs to).
- [ ] POST to the relay path at the origin gives the same status and size as a random
      path that does not exist (a 404, or the site's soft-404).
- [ ] Literal and behavioral grep: every hit explained.
- [ ] Every file in `mu-plugins` explained; plugin folders match `wp plugin list`; no
      dot-prefixed or unknown folders.
- [ ] `wp core verify-checksums` passes; `wp plugin verify-checksums --all` passes for
      wp.org plugins; premium plugins and themes diff clean against pristine copies.
- [ ] Uploads: no `*.php` that is not known-legit; an existing PHP file under uploads
      returns 403; static uploads return 200; the homepage is unaffected.
- [ ] Every administrator is known to the owner.
- [ ] Autoloaded options hold no injected script; management child-plugin options gone.
- [ ] CDN: no unknown Workers, Snippets, Zaraz tools, rules, members or tokens; audit
      log reviewed for the incident window.
- [ ] Every site that shares the vector got the same checks, not only the one reported.
- [ ] The sweep is re-run after a full cache cycle. A reappearance means a write
      channel is still open: go back to phase 4.

## Gotchas

Symptom -> cause -> fix.

- **Server-side curl is clean, visitors still see the overlay** -> `curl --resolve` to
  the origin skips the CDN and hits the already-cleaned origin, while browsers get a
  stale CDN or page-cache copy; automated fetches often get only the CDN challenge ->
  purge every cache layer and confirm in a real browser. Playwright got through the
  challenge on some zones and not on others.
- **Grepping for "Verify you are human" finds nothing** -> the payload is stored
  on-chain and fetched at runtime -> grep for the loader and the relay handler, not
  for the visible text.
- **A scanner reports dozens of live webshells** -> soft-404 -> check the response-size
  distribution.
- **Server-level SSH cannot delete the backdoor** -> the file and its parent folder are
  owned by the app user -> app-level SFTP, or rename the parent if its own parent is
  group-writable, then recreate it empty.
- **`Permission denied (publickey,password)` with correct credentials** -> `sshpass`
  with `sftp -b` cannot inject the password (batch mode has no TTY) -> feed sftp
  commands on stdin. If plain `ssh ... exit` with the same credentials authenticates,
  it is this quirk, not a ban.
- **First SFTP call works, the next ones fail for about 30 minutes** -> host fail2ban ->
  one session, all the work, disconnect; allowlist your IP in the host console; never
  retry in a loop, each retry resets the timer.
- **Port 22 times out while 443 answers** -> per-server SSH IP allowlist -> add the
  current egress IP.
- **`wp plugin status | grep -i active` says active** -> it also matches "Inactive" ->
  `wp plugin get <slug> --field=status` and compare exactly.
- **`wp plugin update` says "already updated" on an ancient premium plugin** -> the
  license updater is not serving builds -> forced zip install, staged.
- **A REST user audit returns nothing or 403** -> a CDN anti-enumeration rule on
  `/wp/v2/users` -> query the database over SSH.
- **REST auth stopped working after hardening** -> a security plugin disabled
  application passwords site-wide -> use WP-CLI over SSH.
- **WP-CLI fatals as the server-level user** -> `wp-config.php` requires a file by
  relative path, or the non-owner user cannot boot the app -> run from inside the
  docroot; for read-only DB access, parse `wp-config.php` with PHP's tokenizer instead
  of executing it.
- **A broad grep hangs** -> no time limit -> run it on the server under Linux `timeout`,
  one app at a time; macOS has no `timeout`.
- **The agent's delete is refused** -> Claude Code's auto-mode safety filter blocks
  remote writes that look like webshell deployment, and bulk `mv`/`rm` of shell-named
  files, even during legitimate IR -> do not rephrase the command to get past it; add
  a narrow allow rule for a reviewed script path, or the human runs the exact
  one-liner. Read-only recon is not blocked.
- **A secret shows up in a "command not found" error** -> zsh does not word-split an
  unquoted `$CMD`, so it tries the whole string as one command name and prints it ->
  never build commands as strings; rotate the leaked secret.
- **Admin logins from residential IPs in your own country** -> often your own team ->
  check before attributing them to an attacker or the client's developer.
- **A cleaned site is reinfected overnight** -> the fan-out channel is alive -> phase 4.

## When to stop and escalate

Stop and hand over to a human (owner, client or host) when:
- The CDN, registrar or hosting account itself is compromised: rotation, 2FA and the
  member review need the account owner.
- Evidence is two-sided, such as an admin that may be a vendor support account: the
  owner decides.
- The fix is a restore: the human picks the restore point, because it drops everything
  that came in since (leads, orders, edits).
- The fix is a major plugin upgrade with database migrations, or a disruptive step on
  shared infrastructure (a proxy or VM restart that blips other services): stage it
  and get an explicit go/no-go per step.
- Isolation settings (`open_basedir`, `disable_functions`) cannot be read from your
  shell: the host console or support confirms them.
- Root or `sudo` is needed: a non-interactive SSH session cannot answer the prompt.
- A rotated secret has to be set: the human types it into a silent prompt.
- A visitor's PC ran the pasted command: that is an endpoint incident, out of scope.
- Host support reports the site "cleaned": verify it yourself (rule 3). A host core
  reinstall can leave an mu-plugin admin backdoor in place.

## Reference files

- [triage-checklist.md](triage-checklist.md): detection signals, first-hour checklist,
  evidence log template
- [hunt-commands.md](hunt-commands.md): render sweep, backdoor grep, mu-plugins,
  uploads, database, checksums, cross-similarity
- [cleanup-and-rotation.md](cleanup-and-rotation.md): removal order, app-owned files
  over SFTP, management plugin removal, rotation list, secret-safe commands, secure
  secret transfer
- [hardening.md](hardening.md): uploads PHP block, shared-server isolation, CDN rules,
  plugin and account policy, backups and rollback
- [worked-cases.md](worked-cases.md): anonymised patterns: ClickFix / EtherHiding, an
  external-loader variant, a management-dashboard fan-out, a plugin-advisory audit
- [scripts/fleet-advisory-audit.sh](scripts/fleet-advisory-audit.sh): read-only plugin
  version and admin count across many sites
