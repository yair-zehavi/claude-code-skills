---
name: wordpress-elementor-automation
description: >
  Change WordPress and Elementor sites programmatically (REST API, WP-CLI over SSH,
  the Elementor editor JS API) without breaking them, and prove every change on the
  live rendered page. Covers picking the write route, Elementor data writes and the
  cache layers that keep serving old HTML, link and dynamic-tag traps, Theme Builder
  templates and popups, cross-site Elementor migration, Yoast, WPML, ACF, menus and
  media over REST, plugin and mu-plugin safety, and bulk SEO meta and alt-text runs.
  Use when an agent is about to write to a live WordPress site, when a change "saved
  but the page still shows the old version", or when a page renders blank, unstyled
  or without a link after a programmatic edit.
  Not for visual design review (use design-qa), deciding which plugin updates to
  apply, or malware and incident response (use wordpress-incident-response).
---

# WordPress and Elementor automation

## Why this exists

On WordPress a successful write proves very little. The REST call returns 200 and the
database holds the new value, while several cache layers keep serving the old HTML. A
cache-busted URL shows the agent a fresh page that no visitor gets. A plugin that
deactivated itself during an upgrade still returns 200 on every page. A migrated heading
with no color rule passes every status check. The failure this skill prevents is an agent
reporting "done" on a change nobody can see, or on a change that quietly broke something
else. Every change goes through one loop: read state, change, purge, then verify the bare
URL and look at it.

## Non-negotiable rules

1. **Back up, then re-pull before every patch.** Save the current value (`_elementor_data`,
   meta, option, menu item list) to a file before writing. When you make several edits to
   one post, pull fresh data before each one. Patching a stale local copy silently reverts
   the previous fix.
2. **Mutate structure, not strings.** Parse `_elementor_data` as JSON, find the node by its
   element `id`, change it, serialize. Never regex-replace inside the raw meta string.
3. **Verify the bare URL.** Query strings bypass WP Rocket's static HTML and get their own
   Varnish entries, so `?cachebust=` shows a page visitors may not get. Use a cache-busted
   fetch to check the origin, then confirm the bare URL serves the change (`age:` reset).
4. **Never cache-bust with a reserved WordPress query var.** `p`, `s`, `m`, `w`, `cat`,
   `page` and the rest change the routed page: `?w=123` asks for a week archive, which
   rendered the 404 template with HTTP 200 on the observed site. Use `?cachebust=<epoch>`.
5. **Look at it.** A visual change is not done until you have rendered a screenshot, opened
   it, and said what you saw. If the numbers and the picture disagree, the picture wins.
6. **Never submit a live form to test it,** intercepted or not. A leaked test lead reaches
   the owner's inbox, their CRM and whoever their automations route it to.
7. **Grep the live HTML before asserting.** Never write "X is missing" or "X is wrong" about
   a page without fetching it and grepping for X first. An item carried over from an older
   audit is a hypothesis, not a finding.
8. **Native fix before code.** Fix the data, then the widget or page setting, then the
   plugin or theme UI. Write a mu-plugin only after stating why those three fail. "I'm not
   sure the native route works" is not a reason: go and check.
9. **Elementor code writes run as an administrator.** WP-CLI writes to HTML widgets or
   Custom Code take `--user=<admin_user>`, or `<script>` is stripped and the code prints as
   visible page text. Read the value back and confirm it still starts with `<script`.
10. **Don't route around a refusal.** If SSH, a write or a file drop is blocked by a
    permission system, say so and stop. Never reach for an arbitrary-PHP snippet plugin or
    an eval endpoint to get the same effect another way.

## Pick the write route

| Change | Route | Notes |
|---|---|---|
| Post and page fields, media alt text, menus, terms, Yoast meta on posts | REST with an application password | Behind Cloudflare bot protection it needs a bypass header. Yoast keys are not on REST until registered (see `seo-meta-rest-bridge.php`) |
| `_elementor_data`, options, meta that REST does not expose (custom field plugins, Yoast on pages on some installs, some custom post types) | WP-CLI over SSH: `wp post meta update`, `wp eval-file` | The most complete route; protected meta is fully reachable |
| Adding a section or widget to a live Elementor page where hand-editing JSON is risky | Elementor editor JS API driven by Playwright (`$e.run`) | A human logs in once (2FA); see [elementor-data.md](elementor-data.md#editor-js-api) |
| Theme Builder display conditions | `PUT /wp-json/elementor/v1/site-editor/templates-conditions/<template_id>` | Writing `_elementor_conditions` meta alone changes nothing on the page |
| Behavior that must run on every render and has no setting | mu-plugin or a small regular plugin | Last resort. Check for an existing copy first |

Signals that decide the route:

- `rest_not_logged_in` with correct credentials, and the `/wp-json/` index shows
  `"authentication": []` -> a security plugin (observed: Wordfence) disabled application
  passwords. Switch to WP-CLI over SSH rather than loosening the security plugin.
- `401` with `invalid_username` -> the username is wrong, not the password. Admin
  usernames differ per site.
- `GET ...?context=edit` returns `"meta": {}` for a custom post type -> its meta is not
  REST-exposed. Use WP-CLI, or change the template that renders it.
- A REST write to a field that is not registered for REST is silently ignored and still
  returns 200. Read back every write.
- Application passwords authenticate REST only. They do not log into wp-admin, and 2FA
  blocks unattended form login.

## Procedure

### 1. Read state

1. Find out what renders the URL: the page's own `_elementor_data`, a Theme Builder
   template (single, archive, header, footer, popup), a listing template from a
   custom-field plugin, a mega-menu template, or plain post content. In the editor, a page
   with zero root elements but visible content on the live URL is template-rendered: edit
   the template, not the page.
2. Pull current data with `context=edit` (plus `lang=<code>` on WPML sites) and write it
   to a timestamped backup file.
3. Snapshot the rendered HTML of each affected URL: `<title>`, meta description,
   canonical, hreflang, every JSON-LD block, and the element you are about to change.
4. Map the cache stack from response headers (`age`, `x-cache`, `cache-provider`,
   `cf-cache-status`) and the active plugin list (WP Rocket, Breeze, an object cache,
   mega-menu plugins). Step 3 has to clear every layer you find here.

### 2. Change

- Edit in code. For Elementor: parse, find the node by `id`, change `settings`, serialize,
  write. Patterns for REST and WP-CLI are in [elementor-data.md](elementor-data.md).
- Through WP-CLI, store `_elementor_data` slashed:
  `update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))`.
  Unslashed JSON with an escaped quote inside a value (every dynamic tag has one) renders
  as a blank page with no error.
- Push large values through STDIN (`wp post meta update <post_id> <key>` with the value
  omitted reads STDIN) or `wp eval-file`, never as a shell argument.
- Read back after writing. For Elementor, count nodes in the tree (expect exactly N+1 or
  N-1). Don't compare string lengths: `wp-widget-*` wrapper widgets carry around 20k
  characters of rendered HTML each, so a large size drop after removing one is normal.
- In batches, pause briefly between writes, log each write's status to a CSV, and run
  dry-run first.

### 3. Purge, innermost layer first

1. Elementor element cache: `wp post meta delete <post_id> _elementor_element_cache` for
   every affected post, including header, footer and popup templates.
2. Elementor CSS and render cache: `wp elementor flush-css`, or over REST
   `DELETE /wp-json/elementor/v1/cache`.
3. Object cache: `wp cache flush`.
4. Page cache plugin: WP Rocket (`rocket_clean_domain()` and `rocket_clean_minify()` from
   PHP, or delete `wp-content/cache/wp-rocket/<host>/<path>/` when its CLI is not
   registered), Breeze (`breeze_clear_all_cache`).
5. Varnish on the origin: BAN and PURGE both, for the path, and for the home page both `/`
   and `/index.php`.
6. Cloudflare: purge the URL prefix through its API.
7. Plugin stores: a JetMenu mega menu keeps rendered HTML in its own `jet_cache` table.

Exact commands: [recipes.md](recipes.md#purge-sequence).

### 4. Verify on the rendered page

Run every check that applies and report what each one returned:

- `curl -sI <site-url>/<path>/` -> `age:` near 0 or `x-cache: MISS` right after the purge.
- `curl -sL <site-url>/<path>/ | grep -c '<new-string>'` is at least 1, and the same count
  for `<old-string>` is 0.
- Head tags: title, meta description, canonical, hreflang and JSON-LD pulled from the live
  HTML and compared with the intended values. Parse every JSON-LD block with a JSON parser.
- Elementor styling: fetch `/wp-content/uploads/elementor/css/post-<post_id>.css` and grep
  `.elementor-element-<element_id>` for the property you expect (for example `color:`).
  If the site prints CSS inline instead of to files, grep the page HTML.
- Links: every expected `href` is present with the right value. A button with no `href`
  attribute at all is a dynamic-tag problem (see gotchas).
- Screenshot at desktop and at a phone viewport (390x844) from a script that writes a PNG
  you can open, with the bypass header, after a mouse move and a scroll so delayed scripts
  and lazy images load. Open it. Say what you saw.
- After any plugin or code change: `/wp-json/` returns 200, `/wp-admin/` returns 302, the
  plugin's status is exactly `active`, and the markup that plugin injects (Yoast's schema
  graph, a tracking snippet) is still in the HTML. A cached home page returning 200 proves
  nothing about the origin.

Scripts for all of these: [recipes.md](recipes.md#verification).

### Bulk runs (SEO meta, alt text, any write over many URLs)

- Snapshot first: the stored values and the **rendered** head or markup of every target URL.
  What Yoast renders can come from an option, a product field or a template, not the meta
  you are about to write.
- Derive each new value from data the site already holds (product names, category names,
  the page's focus keyphrase), write a preview CSV, and run dry-run before `--apply`.
- Prioritize by Search Console impressions and work in batches of 20 to 25. Low-traffic URLs
  get templates, not hand-written values.
- Write slowly, log every response to CSV, purge once per batch rather than per write.
- Re-fetch every URL afterwards and diff against the snapshot. Report counts per bucket and
  per failure, not "done".

Full workflows: [recipes.md](recipes.md#bulk-seo-meta-titles-descriptions-focus-keyphrases)
and [recipes.md](recipes.md#bulk-alt-text).

## Gotchas that cost the most time

Symptom -> cause -> fix. The full catalogue is in [gotchas.md](gotchas.md).

- **New CSS arrived, page still shows the old layout** -> the Elementor element cache holds
  the previous widget HTML after a raw `_elementor_data` write -> delete
  `_elementor_element_cache`, flush CSS, purge.
- **REST write confirmed, page unchanged, cache headers say MISS** -> Elementor's render
  cache only invalidates on an editor save -> `DELETE /wp-json/elementor/v1/cache`.
- **Bare URL stale, `?cachebust=` correct** -> a WP Rocket static file or a Varnish entry
  -> delete the WP Rocket cache files, BAN and PURGE on the origin, recheck `age:`.
- **Container has a link set, page renders a plain `<div>`** -> container links only render
  when `html_tag` is `"a"` -> set both, and remove the inner widgets' own links.
- **Button renders with no `href`** -> a `__dynamic__.link` tag overrides the static link
  and points at a deleted post -> remove the `__dynamic__` entry.
- **Template renders completely blank, no PHP error** -> `_elementor_data` was passed to
  `update_post_meta` unslashed, which strips the backslash from an escaped quote -> write it
  with `wp_slash`.
- **Migrated heading has no color** -> `__globals__` ids from the source site's Kit don't
  exist on the target, so Elementor omits the property -> resolve to literal values.
- **Migrated image shows a different photo** -> an attachment id stored as a JSON string
  was not remapped and `image_size: "custom"` renders by id -> compare ids as strings.
- **Script prints as text at the bottom of every page** -> WP-CLI wrote Elementor code as
  user 0 and `<script>` was stripped -> rewrite with `--user=<admin_user>`.
- **Editor freezes on one page only** -> many uploaded SVG icons in one widget can trigger
  a re-render cascade (observed on 4.2.4) -> an editor-only dedupe script, or switch to font
  icons.
- **After an editor save the page lost its SEO title and meta** -> the save reset the title
  and Yoast fields (observed) -> re-apply them through REST after every editor save.
- **wp-admin and every `/wp-json` route return 500, home page returns 200** -> a mu-plugin
  redeclares a function from an active regular plugin -> delete the duplicate copy.
- **Plugin upgraded over REST, its output vanished, HTTP still 200** -> it deactivated itself
  during the upgrade -> record `is_plugin_active()` before, reactivate after.
- **Real phones show a broken layout, the cache-busted test looks fine** -> WP Rocket Remove
  Unused CSS kept CSS pruned for the old DOM after a template edit -> re-save each affected
  post, then purge.
- **Returning visitors see half-styled pages after a theme deploy** -> the stylesheet is
  versioned by a hand-bumped theme header -> version it by `filemtime()`.

## When to stop and ask a human

- Retiring, redirecting, unpublishing or noindexing a live page, or merging two pages. Never
  301 and unpublish on your own. If asked to stop two pages competing, `rel=canonical` on
  the old page is the reversible stopgap; the page's fate is the owner's decision.
- Major plugin or core updates, and any update to Elementor, Elementor Pro, WPML or the
  site's security plugin.
- Deploying PHP to production (mu-plugin, plugin upload) after the native routes were
  rejected: show the reason and the file first.
- Deleting files on production, or restoring a host-level backup.
- Any test that would submit a live form, send mail or fire a webhook. If the form path
  itself is changing, ask for a staging copy.
- Adding or changing a Cloudflare security rule, or anything that weakens the WAF for
  all traffic.
- A login that needs 2FA, or a hidden login URL you don't have.
- A permission prompt or policy refusal on a production write: report it in one line and
  wait for the decision.

## Reference files

- [elementor-data.md](elementor-data.md): where Elementor stores things, read-modify-write
  over REST and WP-CLI, element ids, links and dynamic tags, page CSS, code widgets, the
  editor JS API, Theme Builder templates and popups, cross-site migration, forms, and the
  editor freeze.
- [gotchas.md](gotchas.md): symptom -> cause -> fix for access and auth, caching, REST
  quirks (WPML, menus, ACF, Polylang, WooCommerce, custom fields), Yoast and JSON-LD, content
  and themes, media, plugins and mu-plugins, staging clones, and verification traps.
- [recipes.md](recipes.md): credentials and request pattern, Cloudflare bypass rule, purge
  sequence, verification scripts, safe form testing, bulk SEO meta and bulk alt-text
  workflows, and menu, WPML, Polylang, ACF, Yoast, plugin and media recipes.
- [seo-meta-rest-bridge.php](seo-meta-rest-bridge.php): a small mu-plugin that registers
  Yoast meta keys for REST, writes term SEO meta with the indexable sync, and adds a
  cache-purge route. Use it only where WP-CLI is not available.
