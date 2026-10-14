# Worked cases (observed in 2026)

Four anonymised incident patterns from a fleet of managed WordPress sites on shared
hosting behind Cloudflare. Site, client and attacker-artefact details are removed;
what is left is the pattern and the order of work.

## Case 1: ClickFix overlay delivered by EtherHiding

### What was reported
A fake Cloudflare-style page: "Verify you are human: press Win+X, open Terminal,
Ctrl+V, Enter". Reported as on all the sites, Windows only (not on Mac), comes back
after cleaning, and invisible to server-side curl and grep.

Following those steps is the infection: the visitor pastes and runs a PowerShell or
mshta command on their own PC.

### Mechanism
1. An injected `<script>` in `<head>`, emitted at `wp_head` render time, POSTs a small
   JSON body to a same-origin relay endpoint, `<relay-path>` below. The body names a
   smart-contract address and the 4-byte selector `0x6d4ce63c`, which is `get()`.
2. `<relay-path>` is a server-side PHP handler the attacker planted on the site itself,
   so the fake-verification loader only ever talks to the site's own origin. It relays
   the request as an `eth_call` to a public blockchain RPC node.
3. The contract's `get()` returns the ClickFix payload, base64-encoded. The loader takes
   the `signature` field of the response, decodes it with `atob`, and appends it to
   `<head>` as a new `<script>`.
4. That script draws the fake verification page.

The payload lives on-chain. No file or database row contains "Verify you are human",
so grepping for the visible text finds nothing. Some time after the incident the
attacker cleared the contract storage, and `eth_call` returned `0x`: capture the
payload early if you want it as evidence.

Search tokens: the relay path itself (read it off the loader's POST in the browser's
network log), `GET_SIGNATURE`, `data.signature`, `0x6d4ce63c`, `eth_call`, `jsonrpc`,
RPC hostnames such as `bsc-dataseed`, `publicnode`, `llamarpc`.

### Why the server-side checks looked clean
1. `curl --resolve <host>:443:<origin-ip>` skips Cloudflare, and by then it often hit an
   origin whose injector had already been removed.
2. The loader is emitted at render time. After the origin was cleaned, it survived in
   stale page-cache (Breeze) and CDN-cache copies that real browsers were still served.
3. Automated fetches get the genuine Cloudflare challenge and never see the page
   behind it. A real browser that gets through the challenge is the only way to see
   what visitors see. Playwright got through on some zones and not on others.

### Detection order that worked
1. Render live in Playwright; dump inline scripts and network requests; grep for the
   tokens.
2. Render-sweep each site from the origin with a Windows user agent and grep the
   output. Zero hits: origin clean. Any hit: a live injector.
3. Hunt the relay handler, literal and behavioral (often obfuscated). Check
   `mu-plugins` first, diff plugin folders against the plugin list, read the tail of
   every theme's `functions.php` (active and inactive), autoloaded options, near-core
   file names in `wp-content`, `admin-ajax` and `wp_ajax_nopriv_` hooks.
4. POST to `<relay-path>` at the origin. A 404 (same as a random path) means the
   handler is gone and only caches remain: purge page cache and CDN. A 200 means a live
   handler: find and remove it.
5. Before believing a shell scanner, check for a soft-404. One site served its error
   page with HTTP 200, which made 78 scanner probes look like live webshells; the
   identical response size gave it away. Then `wp core verify-checksums`,
   `wp plugin verify-checksums` and a pristine diff of premium plugins and themes.

### Entry vector
Cross-similarity: active plugins on every hacked site, diffed against a known-clean
site on the same stack. Present on hacked sites and absent on the clean one: WPBakery
(`js_composer`, old 4.x and 7.x builds), Gravity Forms 2.4.x, Ultimate Member, all
classes with unauthenticated file-upload or RCE history. Elementor, the Jet plugins and
Breeze ran on the clean site too, so they were ruled out.

### Persistence and spread
- **A management child plugin** connected to a dashboard the attacker could use: a
  remote push channel to every managed site. This is the "on all sites, comes back"
  mechanism.
- **A shared Cloudflare account.** Treat a shared CDN account as attacker access until
  its members, tokens and audit log prove otherwise. With it, an attacker can inject at
  the edge (Workers, Zaraz, Snippets) on every zone, and keeps that access after the
  origins are clean. "Only I have access" is a belief, not a check.

### Remediation order used
1. Cloudflare WAF custom rule, action Block, on every zone:
   `(http.request.uri.path contains "<relay-path>")`. Instant edge shield that cuts
   loader from payload everywhere.
2. Managed ruleset on, to block known plugin-exploit patterns at the edge.
3. Origin injector removed; Breeze and CDN caches purged.
4. Vulnerable plugins updated or replaced. Gravity Forms 2.4 to 2.9 is a five-year jump
   with database migrations that are not cleanly reversible, so it was staged. Old
   premium builds needed a forced zip install because their updater no longer served
   them.
5. The management child plugin removed everywhere.
6. Cloudflare password rotated, all API tokens rolled including the Wrangler OAuth
   grant, unknown members removed, 2FA enforced; WP admin passwords rotated with 2FA.
   Check that every site has a 2FA plugin at all; one did not.

## Case 2: External loader variant, no CDN in front

A site on a different host, not behind Cloudflare. The loader was a plain
`<script async src="https://<lookalike-of-a-public-js-cdn>/<name>.js">`, printed twice:
once right after the site-icon output in `<head>`, once as the last line of
`wp_footer`. It was identical for every user agent, so a plain curl saw it. The domain
imitated a well-known public JavaScript CDN; the payload was about 165 KB of
VM-obfuscated JavaScript.

- Search Console reported the site under "Deceptive pages" with no sample URLs.
- Likely entry: WP File Manager plus a rogue administrator.
- Fleet sweep: curl every homepage in the fleet and count matches for the loader
  domain and script name.

## Case 3: A compromised management dashboard

A compromised site-management dashboard can push plugins, themes, code and admin users
to every connected site. Two sites on different servers showed what that looks like.

**Site A** (black "Hacked" defacement):
- `index.php` overwritten; webshells in the docroot; an attacker working folder; an
  attacker's Search Console verification file.
- The real persistence: an mu-plugin with an innocuous, infrastructure-sounding file
  name that logged the caller in as an administrator when a specific query parameter
  was present.
- Host support reinstalled core and removed the docroot shells, but missed the
  mu-plugin. A core reinstall never touches `wp-content`.
- The mu-plugin was app-owned in an app-owned folder; the server-level user could not
  delete it. Removed over the application-level SFTP user.
- The suspected entry, a file-manager plugin, had already been removed.

**Site B** (different server):
- An obfuscated webshell, `require_once(hex2bin(...))`, under
  `uploads/elementor/forms/.../temp/`, plus two zero-byte upload probes.
- Entry: an Elementor Pro form file-upload field.
- Otherwise clean: core checksums passed, no rogue admin. Removed over app-level SFTP.

**Fleet actions:**
- The child plugin removed from every site that had it. Some deletes left files
  behind because they were app-owned; those were finished over app-level SFTP. The
  dashboard was deleted.
- The uploads PHP-execution block deployed fleet-wide. About half the sites already had
  it from an earlier standard deploy.

**After the first pass (easy to drop once the sites look clean):**
- [ ] Residue sweep across the fleet: rogue admins, recently added plugins and themes,
      checksum failures. The dashboard could have pushed things a shell scan would not
      flag.
- [ ] WP admin passwords rotated and 2FA on every affected site.
- [ ] Edge hardening for a defaced site: managed rules, origin locked to CDN IPs, a gate
      on `wp-admin` and the login page, security headers.
- [ ] Old vulnerable plugins patched on the sites that were not hit (old WPBakery,
      Gravity Forms and Revolution Slider builds are typical).

**Lessons:**
- A fleet management tool is a single point of fan-out. Its dashboard is the most
  sensitive thing you run: isolate it from the sites it manages (its own server, 2FA,
  minimal admins), never as one more app beside them.
- "The host cleaned it" is a starting point. Hunt `wp-content` yourself, `mu-plugins`
  first.
- Isolation between apps on a shared server is weak by default (see
  [hardening.md](hardening.md)).
- Order: cut the channel, clean the sites, then sweep for residue.

## Case 4: Plugin advisory, no compromise found

An alert email claimed a page-builder plugin (Elementor 4.3.0 and 4.3.1, fixed in
4.3.2) let a CSRF skip REST nonce validation, so a logged-in admin who opened a
malicious link would create an attacker-controlled admin account.

1. The claim was verified at a reputable security publication before acting on the
   email.
2. A read-only fleet audit pulled the plugin version and the admin count from every
   site over REST
   ([scripts/fleet-advisory-audit.sh](scripts/fleet-advisory-audit.sh)). No site was in
   the affected range; one was already on the fixed version.
3. The admin review turned up one anomalous administrator: empty profile, an email on a
   domain that does not exist, created recently, on a site with only three users. That
   is the shape of a backdoor account. The site also ran MalCare and Wordfence, and
   MalCare/BlogVault create temporary support admins. With the evidence two-sided, the
   account was not deleted; the question went to the owner.
4. Files modified since shortly before the account's creation date were checked: one
   core-update cluster plus the security plugin's own logs, nothing else.
5. Coverage gaps found while auditing, each of which makes a "clean" result
   meaningless for that site:
   - One site's REST credential was still a placeholder, so REST had never worked
     there. Its version came from the plugin's public `readme.txt` instead.
   - Base URLs were inconsistent: some included `/wp-json`, some did not, so any
     script concatenating paths returned 404 on exactly those sites.
   - Cloudflare blocked `/wp-json/wp/v2/users` on many zones (anti-enumeration), so
     the admin review for those sites had to go through the database over SSH.
