# Gotchas: symptom -> cause -> fix

Grouped by layer. Versions and figures are as observed in 2026. Elementor data procedures
behind several of these are in [elementor-data.md](elementor-data.md); commands are in
[recipes.md](recipes.md).

## Access and authentication

- **REST returns 403 with an HTML body titled "Just a moment..."** -> Cloudflare's bot
  challenge stops the request before WordPress sees the credentials -> send a secret bypass
  header matched by a Cloudflare custom rule that skips security for `/wp-json` (recipe).
  Check whether the zone already has such a rule before adding one. The header goes on every
  request, including browser contexts.
- **401 that looks like a wrong password, and the shell printed "command not found"** -> the
  application password in the env file is unquoted. It is six space-separated groups, so
  `source` exports only the first and tries to run the rest -> wrap it in double quotes.
  Check quoting before assuming the password is wrong.
- **401 `invalid_username`** -> the password is fine, the username is wrong -> admin
  usernames differ per site; confirm which account generated the application password.
- **`rest_not_logged_in` with correct credentials, `/wp-json/` index shows
  `"authentication": []`** -> a security plugin disabled application passwords site-wide ->
  use WP-CLI over SSH.
- **Env values with `!`, `$` or `#` arrive mangled** -> double quotes let the shell expand
  them -> single-quote those values. Env var names can't contain hyphens, so store a header
  name like `x-something-key` as a value, not as a variable name.
- **A credential parsed with `cut -d=` includes trailing junk** -> an inline comment after
  the value -> strip the prefix, then strip whitespace plus `#` and the rest.
- **wp-login.php and /wp-admin/ return 404 when logged out** -> a hide-login plugin moved
  the login URL -> ask for the custom URL before any browser-driven admin work.
- **SSH works once, then `Permission denied (publickey,password)` with unchanged
  credentials** -> the managed host's fail2ban blocked your IP after a few connections in
  minutes (cleared after about 30 minutes) -> first rule out wrong credentials and the
  `sshpass` plus `sftp -b` quirk (batch mode gets no TTY, same error). Then whitelist the IP
  in the host panel or wait without retrying (every retry resets the timer). Connect once, do
  all the work, disconnect.
- **Port 22 times out while 443 answers** -> SSH is IP-allowlisted at the server, not
  fail2ban -> someone with panel access adds your current egress IP.
- **`scp` fails with "No such file or directory" on a path that exists** -> the host's SFTP
  subsystem is chrooted or picky -> try `scp -O` (legacy protocol), or pipe the file through
  an SSH command (`base64 < file | ssh ... 'base64 -d > <dest>'`).
- **Need to deploy PHP but SFTP is blocked** -> package it as a regular plugin (folder, main
  file with a plugin header, zipped) and use wp-admin -> Plugins -> Upload Plugin, which goes
  over HTTPS. Re-uploading the same slug needs deactivate and delete first. The core REST
  plugin install endpoint only installs from wordpress.org by slug.

## Caching

- **Bare URL stale while `?cachebust=` shows the fix** -> query strings bypass WP Rocket's
  static HTML file and get separate Varnish entries -> delete
  `wp-content/cache/wp-rocket/<host>/<path>/`, BAN and PURGE on the origin, verify the bare
  URL's `age:`.
- **`curl -X PURGE <public-url>` returns 400** -> Cloudflare does not pass PURGE through ->
  purge from inside WordPress (Breeze actions) or on the origin against Varnish on localhost.
- **Origin BAN returned 200 but `age:` keeps climbing** -> a 200 doesn't mean the object was
  flushed. A BAN on `/` alone left the home page cached -> send BAN and PURGE, on `/` and
  `/index.php`, then recheck. The Varnish port differs per server: 8080 on most observed,
  80 on another (a different port answered 501), so try 8080, then 80.
- **`wp breeze purge` or a files-manager call fatals with an `ftp_nlist()` TypeError** ->
  under WP-CLI WordPress fell back to the FTP filesystem -> `wp cache flush`, delete
  `wp-content/cache/breeze/*`, and do the Varnish part with a localhost BAN.
- **`wp rocket clean` does nothing** -> WP Rocket's CLI is often not registered -> delete its
  cache files directly. If `rm` gets "Permission denied", the cache dir belongs to the app
  user; run it as that user, not the server master account.
- **Layout change saved, page still loads old combined CSS** -> WP Rocket's combine/minify
  cache in `wp-content/cache/min/` -> call `rocket_clean_minify()` as well as
  `rocket_clean_domain()`. Cache-busted views hide this one too.
- **`wp breeze purge --cache=varnish` printed Success and nothing changed** -> it didn't
  reach the per-URL Varnish object -> PURGE the path on the origin with
  `Host: <host>` and `X-Forwarded-Proto: https`.
- **A field written with `wp post meta update` doesn't show in a cached listing** -> meta
  updates fire no `save_post`, so the page cache plugin never purges -> touch the page that
  embeds it (`POST /wp/v2/pages/<post_id>` with `{"status": "publish"}`).
- **Mega-menu edits never appear, even after every cache is cleared and a direct origin
  request** -> JetMenu stores rendered menu HTML in its own `<prefix>jet_cache` table ->
  delete the row for that template. Map a menu item to its template via the item's
  `jet-menu-item` postmeta.
- **Header or footer template edit doesn't appear** -> `_elementor_element_cache` sits on the
  template post, not the page -> delete it on the template post too. Clearing Elementor's
  files cache clears CSS only, not this meta.
- **Static folder uploaded into the web root still serves the old copy** -> Varnish caches
  the directory URL (`/<folder>/`), not just `index.html` -> PURGE the directory path.
- **Theme CSS edit invisible until purge, even with fresh HTML** -> Varnish caches each
  versioned asset URL separately; purging `/` doesn't touch `style.css?ver=<n>` -> change
  the version query string.
- **A custom cookie set on a front-end page never reaches PHP** -> Varnish strips request
  cookies that don't match its WordPress allowlist (observed:
  `wordpress_logged_in|resetpass|wp-postpass|wordpress_|comment_|woocommerce_(cart|session)`)
  -> name the cookie to match the allowlist, or use `/wp-json/` and POSTs, which pass cookies
  through.

## Elementor

- **New CSS loaded, old markup and old element ids rendered** -> `_elementor_element_cache`
  (on by default on observed builds, 3.34 and later) isn't invalidated by raw writes -> delete
  it per post on every deploy.
- **REST write verified, page unchanged, cache headers MISS** -> Elementor's render cache ->
  `DELETE /wp-json/elementor/v1/cache` after every REST write to `_elementor_data`, and after
  writes to `post_content` on an Elementor post.
- **Blank render, no PHP error, `get_content()` length 0** -> `_elementor_data` passed to
  `update_post_meta` unslashed lost the backslash in an escaped quote -> write with `wp_slash`.
- **Container link set, renders a `<div>`** -> link control is conditional on
  `html_tag: "a"` -> set both; remove nested links.
- **Button with no `href` at all** -> `__dynamic__.link` to a deleted post wins over the
  static link -> delete the dynamic entry. Same pattern when a link keeps going to an old
  URL after you changed `link.url`: a custom field dynamic tag is overriding it.
- **Class never renders** -> written to `css_classes` -> the key is `_css_classes`.
- **Popup button has `href=""`** -> popup action links written over REST are stripped ->
  class plus delegated click script calling `showPopup`.
- **Template with conditions set never applies** -> conditions written to meta only -> use
  the site-editor conditions endpoint, or also write the aggregated option over WP-CLI.
- **Duplicated template lost its styling (headings and buttons in Kit default colors)** ->
  element ids were regenerated -> copy faithfully, keep ids.
- **Migrated text invisible or unstyled** -> orphaned `__globals__` Kit ids -> hardcode the
  resolved values.
- **Migrated image shows another photo, URL looks right** -> string-typed attachment id left
  stale, `image_size: "custom"` renders by id -> remap ids compared as strings.
- **`_elementor_data` is half the size after removing one widget** -> `wp-widget-*`
  wrappers (for example a Gravity Forms widget) store about 20k characters of rendered HTML
  in `settings` -> count nodes, not characters.
- **Edits to a page do nothing** -> a Theme Builder template renders it (editor shows zero
  root elements) -> edit the template.
- **Editor freezes on one page** -> SVG icon re-render cascade -> see elementor-data.md.
- **SEO title and Yoast meta reset after an editor save** -> observed side effect of the
  editor save -> re-apply via REST after each save.
- **Page settings PATCH returns 400** -> `_elementor_page_settings` is schema-locked on REST
  -> scoped `<style>` in an HTML widget, or the element's own custom CSS.
- **Local Google Fonts fail on a cloned site** -> Elementor's local font CSS under
  `wp-content/uploads/elementor/google-fonts/css/` still names the old domain, so CORS blocks
  them -> rewrite the domain in those files.
- **A script that calls Elementor's frontend API fails on page load** -> WP Rocket Delay JS
  loads Elementor's frontend only on the first interaction -> poll until the module exists.

## REST quirks

- **WPML: `GET /wp/v2/pages?search=...` returns `[]` for pages that exist** -> WPML filters
  REST queries to the default language -> add `?lang=<code>` on reads and on writes.
- **Menu reorder loop dies halfway with `rest_url_required`** -> a custom menu item with an
  empty URL (a dropdown label) can't be updated over REST -> don't renumber `menu_order` at
  all. The walker orders items within each parent, so a new child can share a number with a
  top-level item with no effect. Back up the item list first.
- **ACF repeater rows not on REST** (`?_fields=acf` returns `[]`, no ACF route) -> rows are
  index-based postmeta -> never edit the raw postmeta (it breaks the indexing). Use ACF's
  `delete_row()` behind a narrow, capability-gated route, or WP-CLI (recipe).
- **Polylang language set over REST has no effect** -> Polylang's `language` taxonomy is not
  REST-exposed -> a one-off route calling `pll_set_post_language()`. On the observed site the
  post dropped to draft afterwards; check and republish. Remove the write route after use.
- **Custom field written over REST silently ignored** -> a field plugin's meta box field
  (observed with JetEngine) isn't registered for REST -> write it over WP-CLI. A listing widget
  bound to an empty field disappears entirely from the card.
- **Custom post type meta comes back `{}` with `context=edit`** -> not REST-exposed -> WP-CLI,
  or add a template scoped to those posts instead of editing per post.
- **WooCommerce REST ignored `_fields` and rejected comma-separated `status`** (observed on
  one store) -> the `/wc/v3` API differs from `/wp/v2` -> fetch whole objects, one status per
  call.
- **Pagination loop errors at the end** -> `rest_post_invalid_page_number` (HTTP 400) past the
  last page -> stop on that code, or read `X-WP-TotalPages` from the first response.
- **REST has no toggle for plugin auto-updates and no plugin upgrade endpoint** -> core
  doesn't expose them -> WP-CLI (`wp plugin auto-updates disable <slug>`), or a small
  capability-gated plugin that wraps the upgrader.

## Yoast and structured data

- **Yoast keys missing from `meta` on REST** -> Yoast doesn't register its post meta for REST
  -> on hosts with WP-CLI, `wp post meta update <post_id> _yoast_wpseo_title "<title>"` (the
  native route); REST-only, deploy `seo-meta-rest-bridge.php`. On one install the keys were
  exposed for posts but not pages.
- **Term title or description written, page still renders the old one** -> Yoast 14+ renders
  from its `<prefix>yoast_indexable` table; the `wpseo_taxonomy_meta` option alone is not
  enough -> update the indexable through Yoast's repository (the bridge plugin does this).
  Post meta written with `update_post_meta` syncs on its own through Yoast's meta watcher; no
  reindex needed.
- **Search Console: "Missing field 'item' (in 'itemListElement')" on every page** -> Yoast
  omits the URL on the last breadcrumb entry by design and has no setting for it (open issue
  as of mid-2026). Google's docs call `item` optional on the last entry, so first confirm the
  flagged entry is the last one -> a filter on `wpseo_schema_graph` at priority 99 that fills
  the last entry's `item` from the current URL. Cosmetic (breadcrumb display only), safe,
  removable.
  If your content also embeds its own BreadcrumbList, drop it once Yoast's is fixed or the
  page has two.
- **JSON-LD invalid in the Rich Results Test, page looks fine** -> an unescaped `"` inside a
  string value (Hebrew abbreviations contain one), a curly quote, or stray `</p><p>` between
  the JSON and `</script>` ("Extra data") -> parse every block with a JSON parser after
  writing. Prefer a typographic character (for Hebrew, gershayim U+05F4) over `\"`: a
  backslash escape can be eaten by `wp_slash`/`stripslashes` on later meta saves.
- **JSON-LD audit reports failures on Elementor posts** -> the block lives inside
  `_elementor_data[...].settings.editor`, JSON-escaped -> decode `_elementor_data` first, then
  extract the `<script type="application/ld+json">` blocks from the decoded HTML.
- **Two pages compete for the same query** -> retiring one is the owner's call -> set
  `_yoast_wpseo_canonical` on the old page as the stopgap when asked, verify the old URL
  returns 200 with no redirect, and remove the override
  (`wp post meta delete <post_id> _yoast_wpseo_canonical`) once the content is
  differentiated. Before claiming either page holds ranking value, check Search Console.
- **Noindex control over REST** -> `_yoast_wpseo_meta-robots-noindex`: `'1'` noindex, `'2'`
  index, `''` site default.

## Content and themes

- **Duplicate H1 on posts** -> the single-post template already renders the title as H1 ->
  article HTML gets no `<h1>`.
- **A callout looks unstyled and collides with the next paragraph** -> it uses a class the
  site doesn't define, and a bare `<div>` misses the margin every `<p>` gets -> use plain
  semantic tags that the template already styles.
- **A page has none of the blog typography** -> pages with empty `_elementor_data` fall
  through to the theme's `page.php`, and the theme may hide `.page-header h1` -> pages need
  their own in-content H1; the resulting markup is a theme artifact.
- **Returning visitors see a half-styled page, headless render is perfect** -> stylesheet
  versioned by the theme header `Version`, which nobody bumped, so the browser keeps the old
  file -> version by `filemtime()` (recipe). Server purges don't help: the browser never
  re-requests an unchanged URL. If a build script generates the theme, fix the generator.
- **Block theme renders theme.json colors and fonts, custom classes dead** -> block themes
  don't enqueue `style.css` -> enqueue it on `wp_enqueue_scripts`.
- **Raw `<img>` in a `wp:html` block overflows the layout** -> WordPress only styles
  `wp-block-image` -> a global `img { max-width: 100%; height: auto; }`.
- **Inline `<script type="application/ld+json">` in post content** survived byte-identical
  when written via WP-CLI. Pass `--user=<admin_user>`: as user 0, kses strips `<script>` tags.
  Check whether the site's existing posts already carry JSON-LD that way and match the
  convention.

## Media

- **`wp_get_attachment_url()` returns `/wp-content/uploads/wp-content/uploads/...`, and
  `wp media regenerate` says "Can't find"** -> `wp media import --skip-copy` was given a
  relative path, which it stores verbatim in `_wp_attached_file` -> always pass an absolute
  path (`$PWD/wp-content/uploads/...`). To repair, strip the prefix from `_wp_attached_file`
  and from the `file` key in the attachment metadata, then regenerate. Widgets that embed the
  full URL keep working, which hides the bug.
- **Duplicate attachments after a migration** -> deduped by filename -> dedupe by a hash of
  the file bytes.
- **Uploading media over REST** -> `POST /wp/v2/media` with the raw file bytes as the body
  and a `Content-Disposition: attachment; filename="<name>"` header.
- **Alt text written, image title holds the keyword instead** -> alt and title are separate
  fields; alt is the one search engines and screen readers use -> lead the alt with the
  page's focus keyword, keep the title purely descriptive.

## Plugins and mu-plugins

- **A plugin's features stop after a REST-triggered upgrade, HTTP still 200** ->
  `Plugin_Upgrader::upgrade()` run from REST commonly leaves the plugin inactive (observed
  four out of four: Yoast, Duplicate Page, Redirection, ElementsKit Lite) -> record
  `is_plugin_active()` before, call `activate_plugin()` after if it dropped, and report both
  flags. Then diff a content snapshot: a deactivated Yoast still returns 200, the only signal
  is its schema graph disappearing.
- **`/wp-admin/` and every `/wp-json` route return 500 ("critical error"), the public home
  page still 200** -> a function-defining file was dropped into `mu-plugins/` while the same
  code is an active regular plugin; mu-plugins load first, the plugin redeclares, PHP fatals.
  Caches keep the home page green and uptime monitors that only fetch it stay green, while
  Googlebot gets 500s -> before dropping any mu-plugin, check `wp-content/plugins/` and the
  `/wp-json/` namespace list for a twin. To fix, delete the copy you added; collisions surface
  one function at a time, so recheck after each removal. The PHP error log names both files.
- **`wp plugin status <slug> | grep -i active` says active for an inactive plugin** ->
  "Inactive" contains "active" -> `wp plugin get <slug> --field=status` and compare to exactly
  `active`.
- **`wp plugin update` fails with "Could not create the upgrade-temp-backup directory" or
  "some files could not be copied"** -> plugin files belong to the app user and you're
  connected as a different account -> stage the new version outside `plugins/`, rename the
  live folder to a dot-prefixed backup (`.<slug>-old-<timestamp>`, which `get_plugins()`
  skips), move the staged folder in. On FTP-only hosts, upload to `wp-content/upgrade/` and
  swap with two renames on one connection.
- **A request right after a folder swap shows a critical error once, then the site is fine**
  -> stale opcache paths -> check twice before rolling back.
- **The whole shared server stops answering during bulk FTP work** -> coincided with 4-5
  parallel FTPS connections plus a backup job (not proven causal) -> at most 2 connections,
  resume by comparing file sizes, back off 20 s or more on errors, never retry fast.
- **`wp plugin update` on a premium plugin does nothing** -> licence-gated plugins are
  invisible to WP-CLI and wordpress.org -> vendor updater, or a manual zip swap.
- **Site-wide 500 right after an Elementor auto-update, on sites running WP Rocket with its
  Cloudflare add-on on PHP 8** -> a hook callback id that is a numeric string becomes an int
  array key, and WP Rocket's Cloudflare add-on calls `substr()` on it: TypeError on every
  request (observed with WP Rocket 3.20.3 and 3.21.1, Elementor 4.0.4 and 4.2.3) -> cast the key
  to string in that line (lost on the next WP Rocket update), or an mu-plugin that removes the
  add-on's `unregister_cloudflare_clean_on_post` callback on `init` priority 1 and redoes its
  cleanup safely. Turn off unattended auto-updates for Elementor and WP Rocket on production.
- **A write endpoint you added for a one-off job is still live months later** -> remove
  temporary write routes once the job is done. A mu-plugin is invisible in the normal plugin
  list and overrides what the UI shows.

## Staging clones

- **A fresh clone shows 503 "maintenance" within a minute** -> WordPress auto-updates ran on
  the clone (core, WooCommerce, Elementor) -> add guard constants to the clone's
  `wp-config.php`: `DISABLE_WP_CRON`, `WP_HTTP_BLOCK_EXTERNAL` (stops webhooks, conversion
  APIs, mailing tools), `AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`, and disable the
  object cache. Then resync files and database from live.
- **The clone sends real email** -> the server's mail relay is shared -> a `pre_wp_mail`
  allowlist filter on staging.
- **Links on the clone point at production** -> search-replace missed JSON-escaped URLs in
  Elementor data -> also replace `https:\/\/<domain>`.
- **Every page 500s on the clone** -> per-app PHP-FPM settings (for example a raised
  `memory_limit`) were not cloned -> copy them across.
- **REST auth breaks on staging** -> HTTP Basic auth (htpasswd) in front of the site claims
  the `Authorization` header application passwords use, and broke cookie auth on the observed
  clone -> protect staging another way.

## Verification traps

- **Healthy pages 404 in automated checks only** -> the cache-buster is a reserved query var
  -> `?cachebust=`.
- **The fix "works" in your test and not for the client** -> you verified a cache-busted URL
  or a fresh headless browser; they have the bare URL or a cached asset -> verify the bare
  URL and the asset version.
- **Lazy images, logo and hero blank in a headless screenshot** -> Delay JS waits for user
  interaction -> move the mouse and scroll before capturing.
- **Cloudflare challenges the first headless navigation** -> a second navigation in the same
  browser usually passes; or resolve the host straight to the origin
  (`curl --resolve <host>:443:<origin-ip>`, Chromium `--host-resolver-rules`).
- **Every measurement matches the spec, the page is visibly broken** -> you measured the wrong
  things (a missing icon and mismatched icon sizes passed every number) -> screenshot, crop to
  the changed area, look.
- **A browser tool's screenshot can't be read back** -> drive Playwright from a script that
  writes a PNG to disk, then open the file.
- **The shared automation browser is locked by another session** -> don't kill it (it may be
  someone's real browser) -> run an isolated headless Chromium from a script.
- **A report claims a tag is missing, the page has it** -> claim carried forward from an
  older audit -> curl and grep before every on-page claim. For any "search engines show X if
  you do Y" claim, check the engine's current documentation.
