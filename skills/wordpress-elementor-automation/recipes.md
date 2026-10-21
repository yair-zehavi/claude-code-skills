# Recipes

Generic, with placeholders. Swap in your values; never paste a literal secret into a
command, a log or a chat message.

## Credentials and request pattern

One env file per site, mode 600, outside version control:

```bash
WP_SITE_URL=<site-url>
WP_BASE_URL=<site-url>/wp-json
WP_USERNAME=<admin_user>
WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
WP_CF_BYPASS_HEADER_NAME=<bypass-header-name>
WP_CF_BYPASS_HEADER_VALUE='<bypass-secret>'
```

- The application password goes in double quotes: it is six space-separated groups.
- Values containing `!`, `$` or `#` go in single quotes.
- Env var names can't contain hyphens, which is why the header name is stored as a value.

Pass the credentials and the bypass header to curl as a config on stdin (`-K -`), never as
`-u` or `-H` arguments, so they stay out of the process list and shell history. `printf` is
a shell builtin, so it doesn't expose them either:

```bash
set -a; source <env-file>; set +a
wp_auth() {
  printf 'user = "%s:%s"\nheader = "%s: %s"\n' \
    "$WP_USERNAME" "$WP_APP_PASSWORD" "$WP_CF_BYPASS_HEADER_NAME" "$WP_CF_BYPASS_HEADER_VALUE"
}
wp_auth | curl -sS -K - -A "Mozilla/5.0 (X11; Linux x86_64)" "$WP_BASE_URL/wp/v2/settings"
```

Inside a curl config, `"` and `\` in a value need a backslash escape.

Smoke test: `/wp/v2/settings` answers 200 only for a user with `manage_options`, so a 200
proves administrator auth end to end. If not, in this order:
403 "Just a moment..." -> Cloudflare (next section); 401 `invalid_username` -> wrong
username; 401 bad password -> check the quoting first; `rest_not_logged_in` (on this route,
401 `rest_forbidden`) with `"authentication": []` in the `/wp-json/` index -> application
passwords are disabled, use WP-CLI over SSH; JSON 403 `rest_forbidden` -> authenticated, but
the account is not an administrator.

Python session:

```python
import requests

def wp_session(env):
    s = requests.Session()
    s.auth = (env["WP_USERNAME"], env["WP_APP_PASSWORD"])
    s.headers["User-Agent"] = "Mozilla/5.0 (X11; Linux x86_64)"
    if env.get("WP_CF_BYPASS_HEADER_NAME"):
        s.headers[env["WP_CF_BYPASS_HEADER_NAME"]] = env["WP_CF_BYPASS_HEADER_VALUE"]
    return s

def paginate(s, url, params):
    out, page = [], 1
    while True:
        r = s.get(url, params={**params, "page": page, "per_page": 100}, timeout=90)
        if r.status_code == 400 and "rest_post_invalid_page_number" in r.text:
            break
        r.raise_for_status()
        items = r.json()
        if not items:
            break
        out.extend(items)
        if page >= int(r.headers.get("X-WP-TotalPages", "1")):
            break
        page += 1
    return out
```

A small committed, per-site script with `--dry-run`, a preflight that refuses to write
anything breaking the site's content rules, and a live verification step at the end is
easier to review and rerun than ad-hoc one-off requests.

## Cloudflare bypass rule for REST

One-time per zone. Look at the zone's existing custom rules through the API first; a skip
rule on a secret header may already exist.

Security -> WAF -> Custom rules -> Create rule, expression:

```
(http.request.uri.path contains "/wp-json") and (http.request.headers["<bypass-header-name>"][0] eq "<bypass-secret>")
```

Action **Skip**, ticking: all remaining custom rules, Managed Challenge actions of WAF
managed rules, Bot Fight Mode, Browser Integrity Check, all Super Bot Fight Mode rules,
Security Level. Place it first in the custom rules list so it also skips later rules.
Requests without the header still face the full challenge. On a zone with heavy WAF rules,
agree it with the site owner first.

The rule above only covers `/wp-json`. For front-end checks through Cloudflare, a second
navigation in the same browser usually passes the challenge, or resolve the host to the
origin directly (`curl --resolve <host>:443:<origin-ip>`, or Chromium
`--host-resolver-rules="MAP <host> <origin-ip>"` with certificate errors ignored).

## Purge sequence

Over SSH, from the WordPress root:

```bash
cd "$WP_PATH"
wp post meta delete <post_id> _elementor_element_cache   # every affected post and template
wp elementor flush-css
wp cache flush

# WP Rocket, when its CLI is not registered. Run as the user that owns the cache dir.
rm -rf "wp-content/cache/wp-rocket/<host>/<path>/"
# Breeze, when `wp breeze purge` fatals under WP-CLI
rm -rf wp-content/cache/breeze/*

# Varnish on the origin. A 200 does not prove a flush: check age: afterwards.
for method in BAN PURGE; do
  for path in "/<path>/" ; do          # for the home page use "/" and "/index.php"
    curl -s -o /dev/null -w "$method $path %{http_code}\n" -X "$method" \
      -H "Host: <host>" -H "X-Forwarded-Proto: https" "http://localhost:8080$path"
  done
done
```

Port 8080 answered on most observed managed servers, 80 on another. JetMenu's own store:

```bash
wp db query "DELETE FROM <prefix>jet_cache WHERE source='jet-menu' AND source_id=<template_id>"
```

REST only:

```bash
# wp_auth: see "Credentials and request pattern"
wp_auth | curl -sS -K - -X DELETE "$WP_BASE_URL/elementor/v1/cache"
wp_auth | curl -sS -K - -X POST "$WP_BASE_URL/site-ops/v1/cache-purge"
```

The second call exists only where `seo-meta-rest-bridge.php` is installed. It runs
`wp_cache_flush`, the Breeze clear actions (including its Varnish purge),
`rocket_clean_domain` and `rocket_clean_minify`.

Cloudflare, by prefix:

```bash
printf 'header = "Authorization: Bearer %s"\n' "$CLOUDFLARE_API_TOKEN" \
  | curl -sS -K - -X POST "https://api.cloudflare.com/client/v4/zones/<zone_id>/purge_cache" \
      -H "Content-Type: application/json" --data '{"prefixes":["<host>/<path>"]}'
```

`{"purge_everything": true}` is the blunt fallback. Purge once at the end of a batch, not
after every write.

## Verification

Cache state of the bare URL:

```bash
curl -sI "<site-url>/<path>/" | grep -iE '^(age|x-cache|cf-cache-status|cache-provider):'
```

Head tags as rendered:

```bash
curl -sL "<site-url>/<path>/" \
  | grep -oiE '<title>[^<]*</title>|<meta[^>]*name="description"[^>]*>|<link[^>]*rel="(canonical|alternate)"[^>]*>'
```

Every JSON-LD block parses, and the breadcrumb's last entry has an `item`:

```python
import json, re, sys, urllib.request

html = urllib.request.urlopen(urllib.request.Request(
    sys.argv[1], headers={"User-Agent": "Mozilla/5.0"})).read().decode("utf-8", "replace")
for i, block in enumerate(re.findall(r'<script[^>]*application/ld\+json[^>]*>(.*?)</script>', html, re.S)):
    try:
        data = json.loads(block)
    except json.JSONDecodeError as e:
        print(f"block {i}: INVALID {e}")
        continue
    nodes = data.get("@graph", [data]) if isinstance(data, dict) else data
    for n in nodes:
        if n.get("@type") == "BreadcrumbList":
            print(f"block {i}: breadcrumb", [("ok" if it.get("item") else "MISSING") for it in n.get("itemListElement", [])])
    print(f"block {i}: ok, types", sorted({str(n.get("@type")) for n in nodes}))
```

Elementor compiled CSS has the rule you expect:

```bash
curl -s "<site-url>/wp-content/uploads/elementor/css/post-<post_id>.css" \
  | grep -o '\.elementor-element-<element_id>[^}]*}'
```

Origin health after a plugin or code change:

```bash
wp plugin get <slug> --field=status                                   # exactly: active
curl -s -o /dev/null -w '%{http_code}\n' "<site-url>/wp-json/"        # 200
curl -s -o /dev/null -w '%{http_code}\n' "<site-url>/wp-admin/"       # 302
curl -s "<site-url>/?cachebust=$(date +%s)" | grep -ciE 'critical error|fatal error'   # 0
```

Screenshot you can actually open (Node, Playwright installed in the project):

```js
// set -a; source <env-file>; set +a; node shot.js <url> <out.png> [mobile]
const { chromium, devices } = require('playwright');
const HN = process.env.WP_CF_BYPASS_HEADER_NAME, HV = process.env.WP_CF_BYPASS_HEADER_VALUE;

(async () => {
  const [url, out, mode] = process.argv.slice(2);
  const browser = await chromium.launch({ headless: true });   // isolated profile
  const ctx = await browser.newContext({
    ...(mode === 'mobile'
      ? devices['iPhone 13']                                    // 390 px wide, mobile UA
      : { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 }),
    extraHTTPHeaders: HN ? { [HN]: HV } : {},
  });
  const page = await ctx.newPage();
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.mouse.move(300, 300);                              // wakes delayed JS
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(1500);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(500);
  await page.screenshot({ path: out, fullPage: true });
  await browser.close();
})();
```

Then open the PNG. For detail, screenshot again with a `clip` around the changed section,
and for menus or tooltips hover the trigger first. Describe what you see in the report.

## Testing form JavaScript without submitting

```js
const ctx = await browser.newContext({ extraHTTPHeaders: { [HN]: HV } });
const captured = [];
// Abort EVERY POST for the whole life of the context. Never call unroute().
await ctx.route('**/*', route => {
  const req = route.request();
  if (req.method() === 'POST') {
    captured.push({ url: req.url(), body: req.postData() });
    return route.abort();
  }
  return route.continue();
});
await ctx.addInitScript({ path: '<snippet-under-test.js>' });   // inject, don't deploy
const page = await ctx.newPage();
await page.goto('<site-url>/<form-page>/');
// fill with obviously fake data and a postcode or region that can't match a real recipient
// ... trigger the form's client-side logic, then inspect `captured` and the dataLayer
await ctx.close();   // the route dies with the context, never before it
```

Never submit a live production form as a test. Test the form's JavaScript this way, without
submitting, or use a staging copy with mail and CRM hooks disabled when the form's submit
path itself is what changes.

## Bulk SEO meta (titles, descriptions, focus keyphrases)

A pattern that took one store from mixed meta to 341 URLs verified live in one session.

1. **Pull and store snapshots:** Search Console 90 days per page and per page+query
   (`rowLimit` 25000, paginated); the WordPress inventory via REST (`context=edit`, published
   posts, pages, products, product categories, paginated); WooCommerce product data
   (description, short description, categories, attributes); and the **rendered head** of
   every URL with impressions, fetched in parallel (8 workers) with a non-reserved cache-buster
   and the bypass header. The rendered head is the truth: Yoast may render from an option, a
   product field or a template rather than the stored meta.
2. **Label each URL's issues:** no meta, no title, archive template leaking ("X Archives"),
   a template variable rendered literally (`%%title%%`), typo, title or meta too long or too
   short, site brand in the meta.
3. **Prioritize by impressions.** Work in batches of 20 to 25 from the top. Below about 50
   impressions in 90 days, templates beat hand-writing.
4. **Write proposals per URL** from the product and category data. Ranges used: title 36 to
   65 characters, meta description 120 to 159. Rules applied consistently: site brand only on
   the home page; the manufacturer's brand leads product titles; categories own the head
   terms and products don't mirror them (cannibalization).
5. **Write in batches over REST:** `POST /wp/v2/<type>/<post_id>` with
   `{"meta": {"_yoast_wpseo_title": "...", "_yoast_wpseo_metadesc": "...", "_yoast_wpseo_focuskw": "..."}}`
   (needs the keys registered for REST); terms through the bridge's
   `POST /site-ops/v1/term-yoast` with `{taxonomy, term_id, title, description, focuskw}`.
   Sleep about 0.25 s between writes, purge after each batch.
6. **Verify:** re-fetch the rendered head of every URL, diff against the pre-write snapshot,
   save the result, then spot-check bare URLs.
7. **Write a changelog:** counts per surface, the rules applied, the URLs expected to move
   first, and the comparison windows for measuring the effect.

Observed throughput: a batch of 25 writes with purge about 10 s end to end; 341 rendered
heads in about 22 s. Agent reasoning per URL (30 to 60 s) dominates the wall clock.

## Bulk alt text

Three steps, each a script that writes its output to disk so the next step and a human can
review it. Dry-run by default.

1. **Audit:** page through `/wp/v2/media?media_type=image&_fields=id,date,title,alt_text,source_url,mime_type,post`.
   Record per image: missing alt, generic filename (`IMG_1234`, `DSC_`, `screenshot-`,
   `image-`, `untitled`, bare digits), and whether it has a parent post. Write JSON and CSV.
2. **Reverse index** (image id -> where it is used), from: WooCommerce product `images[]`
   (featured and gallery, the authoritative source), WooCommerce category `image.id` (hero
   images), post and page `featured_media`, rendered content (`wp-image-<id>` classes and
   upload filenames in `src`), and `_elementor_data` id/url pairs. Then sort each image with
   no alt into a bucket:

   | Bucket | Meaning | Alt |
   |---|---|---|
   | A | used on exactly one product | product name |
   | A2 | hero of exactly one category | category name |
   | B | used on exactly one page or post | that page's focus keyphrase, else its title |
   | C | used on several products in one category group | a themed alt from a per-group pool, picked by `image_id % len(pool)` so reruns are stable |
   | D | several products across groups | skip, ambiguous |
   | E | several non-product pages | skip, or a vision pass |
   | orphan | not found anywhere | skip |

3. **Write:** `POST /wp/v2/media/<id>` with `{"alt_text": "..."}`, a short sleep between
   calls, every result logged to CSV, one cache purge at the end.

Lessons from the first full run (1,035 alts, zero failures):

- Pull category hero images; skipping them misfiled about 5% of real usage as orphans.
- Add an ordinal to the second and later images of one source (` - image 2`, ` - image 3`,
  sorted by id). Without it a product with 20 gallery images gets 20 identical alts. This
  raised unique alts from 215 to 910 out of 911.
- Elementor data is an unreliable usage source: on one site the id/url pattern matched none
  of 25 Elementor pages. Product galleries and content classes covered 95%+ of real usage.
- About a third of a mature media library is genuinely unused. Leave orphans alone.
- Throughput about 30 writes per minute (network-bound). Run large batches in the
  background.
- On a site without a store, buckets A, A2 and C come out empty and B plus orphans remain.
- When writing alt text by hand, lead with the page's focus keyword, then describe the
  image. Keep the image title descriptive without the keyword.

## Menu item over REST

```bash
# back up first
wp_auth | curl -sS -K - "$WP_BASE_URL/wp/v2/menu-items?menus=<menu_id>&per_page=100" > menu-<menu_id>-backup.json
# add a category link under a parent item
wp_auth | curl -sS -K - -X POST "$WP_BASE_URL/wp/v2/menu-items" -H "Content-Type: application/json" \
  --data '{"type":"taxonomy","object":"category","object_id":<term_id>,"parent":<parent_item_id>,"menu_order":<n>,"menus":<menu_id>,"status":"publish"}'
```

Don't shift other items' `menu_order` (see gotchas).

## WPML: give a CLI-created post a language

```php
<?php
// wp eval-file set-lang.php
do_action('wpml_set_element_language_details', [
    'element_id'           => <post_id>,
    'element_type'         => 'post_page',     // post_post for posts
    'trid'                 => false,
    'language_code'        => '<lang_code>',
    'source_language_code' => null,
]);
```

## Polylang: set a post's language through a one-off route

```php
add_action('rest_api_init', function () {
    register_rest_route('site-ops/v1', '/set-post-language', [
        'methods'             => 'POST',
        'permission_callback' => fn($req) => current_user_can('edit_post', (int) $req['post_id']),
        'callback'            => function ($req) {
            $lang = sanitize_key($req['lang']);
            if (!in_array($lang, pll_languages_list(), true)) {
                return new WP_Error('bad_lang', 'unknown language', ['status' => 400]);
            }
            pll_set_post_language((int) $req['post_id'], $lang);
            return ['ok' => true, 'lang' => pll_get_post_language((int) $req['post_id'])];
        },
    ]);
});
```

On the observed site the post became a draft afterwards: check its status and republish with
`{"status": "publish"}`. Delete the file when done.

## ACF repeater rows

Rows are index-based postmeta. Editing that meta directly breaks the indexing. The clean
shape is a narrow route gated by `current_user_can('edit_post', <post_id>)`, which is a
content operation rather than arbitrary code:

- `GET /site-ops/v1/acf-repeater?post_id=<post_id>&field=<field>` -> `get_field()` lists
  the rows so you inspect, never guess.
- `POST /site-ops/v1/acf-repeater/delete-row` with
  `{post_id, field, match: {subfield, value}, dry_run}` -> find the row, call
  `delete_row(<field>, <index + 1>, <post_id>)` (ACF rows are 1-indexed and it re-indexes the
  rest), purge. Dry-run first.

Designed and reviewed, not yet run in production; test on staging. On hosts with WP-CLI the
same ACF functions can run from `wp eval-file` without a route.

## Yoast meta on pages via WP-CLI

```bash
cd "$WP_PATH"
wp post meta update <post_id> _yoast_wpseo_title "<title>"
wp post meta update <post_id> _yoast_wpseo_metadesc "<description>"
wp post meta delete <post_id> _yoast_wpseo_canonical      # remove an override
```

`update_post_meta` triggers Yoast's indexable sync for posts; no reindex needed. Verify with
a cache-busted fetch of the head, then the bare URL after the purge.

## Plugin upgrade with a reactivation check

```php
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$plugin     = '<plugin-dir>/<plugin-file>.php';
$was_active = is_plugin_active($plugin);                 // capture BEFORE
$upgrader   = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$result     = $upgrader->upgrade($plugin);

$reactivated = false;
if ($result && !is_wp_error($result) && $was_active && !is_plugin_active($plugin)) {
    $act = activate_plugin($plugin);
    $reactivated = !is_wp_error($act);                   // false + error = broken plugin
}
```

Before: snapshot the home page and one key article (status, body size, Yoast schema types,
breadcrumb count). After: same snapshot, diff, scan for "critical error". Report
`from -> to`, `was_active`, `reactivated`. Keep a copy of the plugin folder from before the
update; it is a one-minute rollback that keeps the day's data.

## Theme stylesheet versioned by file time

```php
add_action('wp_enqueue_scripts', function () {
    $css = get_stylesheet_directory() . '/style.css';
    $ver = file_exists($css) ? (string) filemtime($css) : wp_get_theme()->get('Version');
    wp_enqueue_style('theme', get_stylesheet_uri(), [], $ver);
});
```

Block themes need this enqueue anyway, because they don't load `style.css` on their own.

## Media import that keeps library URLs intact

```bash
cd "$WP_PATH"
wp media import "$PWD/wp-content/uploads/<yyyy>/<mm>/<file>" --skip-copy --porcelain   # absolute path
wp media import "<source-url>" --porcelain                                              # sideload, prints the new id
wp post list --post_type=attachment --post__in=<id1>,<id2> --fields=ID,guid
```

## Reading form submissions from the database

Messages contain newlines, so line-based output splits one entry over several rows. Ask the
database for JSON:

```bash
wp db query "SELECT JSON_ARRAYAGG(JSON_OBJECT('id', id, 'created', created_at_gmt, 'form', form_name)) FROM <prefix>e_submissions WHERE created_at_gmt >= '<yyyy-mm-dd>'" \
  --skip-column-names --batch --raw
```

Without `--raw`, every backslash is doubled and a value containing a quote breaks
`json.loads`. Timestamps in that table are UTC: convert with `calendar.timegm`, not
`time.mktime`. Spam-flagged is not the same as spam; keep a reason with every exclusion.
