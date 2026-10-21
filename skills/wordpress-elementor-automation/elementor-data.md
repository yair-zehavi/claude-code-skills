# Elementor data: changing it without breaking it

Versions named here are the ones the behavior was observed on in 2026. Re-check on a
different build before relying on a version-specific detail.

## Where things live

| What | Where | Notes |
|---|---|---|
| Page or template content | post meta `_elementor_data` | JSON string. A tree of nodes with `id`, `elType`, `widgetType`, `settings`, `elements` |
| Page settings | post meta `_elementor_page_settings` | Schema-locked on REST on observed installs: a PATCH returns HTTP 400 |
| Rendered widget HTML cache | post meta `_elementor_element_cache` | Element caching was on by default on observed builds (3.34 and later). Raw writes do not invalidate it |
| Compiled CSS | post meta `_elementor_css` and `/wp-content/uploads/elementor/css/post-<post_id>.css` | Rebuilt after a flush |
| Site Kit (global colors and fonts) | option `elementor_active_kit` -> that post's `_elementor_page_settings` (`custom_colors`, `custom_typography`, keyed by `_id`) | Ids are specific to each site |
| Theme Builder conditions | option `elementor_pro_theme_builder_conditions` plus template meta `_elementor_conditions`, `_elementor_template_type`, `_elementor_edit_mode` | The option is what Elementor Pro reads |
| Pro Custom Code | post meta `_elementor_code` | Sanitized unless written by a user with `unfiltered_html` |
| Pro form submissions | table `<prefix>e_submissions` | Also `GET /wp-json/elementor/v1/form-submissions` |
| `post_content` | not used to render an Elementor post | Elementor renders from its own data. Writing `post_content` changes nothing visible |

## Read-modify-write over REST

Works where the post type exposes `_elementor_data` to REST (observed on pages and posts,
not on some custom post types).

```python
import json, time, requests

s = requests.Session()
s.auth = (WP_USERNAME, WP_APP_PASSWORD)
s.headers[BYPASS_HEADER_NAME] = BYPASS_HEADER_VALUE
base = f"{SITE_URL}/wp-json"
params = {"context": "edit", "_fields": "id,meta"}   # add "lang": "<code>" on WPML sites

def walk(nodes):
    for n in nodes:
        yield n
        yield from walk(n.get("elements", []))

raw = s.get(f"{base}/wp/v2/pages/{POST_ID}", params=params).json()["meta"]["_elementor_data"]
with open(f"backup-{POST_ID}-{int(time.time())}.json", "w") as f:
    f.write(raw)

tree = json.loads(raw)
before = sum(1 for _ in walk(tree))
node = next(n for n in walk(tree) if n.get("id") == ELEMENT_ID)
node["settings"]["editor"] = NEW_HTML

r = s.post(f"{base}/wp/v2/pages/{POST_ID}",
           json={"meta": {"_elementor_data": json.dumps(tree, ensure_ascii=False)}})
r.raise_for_status()
s.delete(f"{base}/elementor/v1/cache").raise_for_status()   # not optional

after = json.loads(s.get(f"{base}/wp/v2/pages/{POST_ID}", params=params).json()["meta"]["_elementor_data"])
assert sum(1 for _ in walk(after)) == before
```

What did not make a stale page refresh after a REST write, so don't spend time on it:
touching `_elementor_css` in the same request, sending `title` to force a fuller save,
sending HTTP PURGE or BAN to the public URL (Cloudflare and the server answer 400 or 405),
cache-buster query strings, or varying the user agent. Only the Elementor cache endpoint
(plus the element cache meta, see below) did.

## Read-modify-write over WP-CLI

Run from inside the WordPress root (`cd "$WP_PATH"`). Some `wp-config.php` files include
other files by relative path, and `wp --path=...` from elsewhere then fails with
"Failed opening required".

```php
<?php
// wp eval-file patch-elementor.php --user=<admin_user>
$post_id    = <post_id>;
$element_id = '<element_id>';

$raw  = get_post_meta($post_id, '_elementor_data', true);
$data = json_decode($raw, true);
if (!is_array($data)) { WP_CLI::error('could not decode _elementor_data'); }
file_put_contents('<backup-dir>/elementor-' . $post_id . '-' . time() . '.json', $raw);

function patch_node(array &$nodes, string $id, callable $fn): bool {
    foreach ($nodes as &$n) {
        if (($n['id'] ?? '') === $id) { $fn($n); return true; }
        if (!empty($n['elements']) && patch_node($n['elements'], $id, $fn)) { return true; }
    }
    return false;
}

$found = patch_node($data, $element_id, function (array &$n) {
    $n['settings']['title'] = 'New heading text';
});
if (!$found) { WP_CLI::error('element not found'); }

update_post_meta($post_id, '_elementor_data',
    wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
delete_post_meta($post_id, '_elementor_element_cache');
delete_post_meta($post_id, '_elementor_css');
WP_CLI::success('patched ' . $post_id);
```

Then `wp elementor flush-css`, `wp cache flush`, and the rest of the purge sequence.

Why `wp_slash`: `update_post_meta` runs `wp_unslash` on the value before storing it, which is
why Elementor's own save slashes first. Pass raw JSON and any `\"` inside a value loses its
backslash, the stored JSON is invalid, and the page renders empty with no PHP error
(`get_content()` returns length 0). Data with no escaped quotes survives unslashed, which
hides the bug until the first dynamic tag arrives. REST meta writes and
`wp post meta update` slash for you.

For a whole-value replace, `wp post meta update <post_id> _elementor_data` with the value
omitted reads it from STDIN, which avoids shell length and quoting limits.

## Element ids

- **Keep existing ids** when copying, duplicating or migrating. Per-element styling is keyed
  to `.elementor-element-<id>`. Regenerating every id detaches that CSS and the page falls
  back to the Kit's defaults (headings and buttons change color).
- Give genuinely new nodes fresh ids in the same format the page already uses (7 lowercase
  hex characters on observed sites) and check they don't collide with any id on the page.

## Links and dynamic tags

- **Container links need `html_tag: "a"`.** The container only adds link attributes when its
  link control is active, and that control has the condition `html_tag == "a"`. Setting
  `settings.link.url` alone stores the URL and renders a plain `<div>`. Set both to make a
  whole card clickable, and strip the inner widgets' own links: no nested `<a>`.
- **`__dynamic__` wins over the static value.** A widget can carry `settings.link.url` and
  `settings.__dynamic__.link` (an `[elementor-tag ... name="internal-url" ...]`). The dynamic
  tag always wins. If its target post was deleted it resolves to empty, and the element
  renders with no `href` at all: "the button doesn't work". Delete `settings.__dynamic__.link`
  so the static link takes over. Duplicated and migrated pages are where these come from,
  so audit every migrated page's buttons and menus for stale `internal-url` tags. A custom
  field tag from a field plugin can override a link the same way.
- **CSS Classes control is `_css_classes`** (leading underscore). `css_classes` is silently
  ignored, so the class never renders.
- **Popup trigger links don't survive REST.** A button `link.url` of
  `#elementor-action:action=popup:open&settings=<base64>` written over REST renders as
  `href=""`, even with the popup published and its conditions set. It only survives an
  editor save. Headless workaround: give the button a class via `_css_classes`, then add an
  HTML widget with a script that delegates clicks:
  `e.target.closest('.<trigger-class>')` -> `elementorProFrontend.modules.popup.showPopup({id: <popup_id>})`.
  Poll every ~50ms until `elementorProFrontend.modules.popup` exists: with WP Rocket's Delay
  JS, Elementor's frontend only loads on the first interaction.

## Page-scoped CSS

In order of preference:

1. The widget's or element's own custom CSS setting. It stays visible in the editor and
   survives later UI edits.
2. Over REST, `_elementor_page_settings` returns 400, so prepend an `html` widget whose
   `settings.html` is a `<style>` block. Scope every selector with
   `body.page-id-<post_id>` and wrap the block in a marker comment such as
   `/*AGENT-EQUAL-HEIGHT*/` so a re-run can strip and replace it instead of stacking copies.
3. Never put a `<style>` block in plain post content. `wpautop` inserts `<br />` inside it and
   the CSS parser drops every rule after the first.

## Code, HTML widgets and Custom Code

- WP-CLI runs as user 0 unless told otherwise. User 0 lacks `unfiltered_html`, so the
  `<script>` tags are stripped and the JavaScript prints as plain text at the bottom of every
  page. Observed on `_elementor_code`; treat HTML widgets and other code fields the same way.
- Pass `--user=<admin_user>`. Look the id up with
  `wp user list --role=administrator --fields=ID,user_login`; user 1 often doesn't exist.
- After saving, read the meta back and check it still starts with `<script`. Keep the
  Custom Code entry in draft until it does.
- After publishing site-wide code, check the cached HTML at the origin on the bare URL, then
  purge Cloudflare.

## Editor JS API

Use when you need to add a container or widget to a live page and hand-editing the JSON is
risky (large, versioned schema where one wrong byte breaks the render). Drive the real
editor with Playwright.

Login: application passwords don't work for wp-admin form login when 2FA is on. Open a
headed browser on `<site-url>/wp-admin/`, prefill the username, let a human type the
password and 2FA code, then save the browser storage state and reuse it in later runs
(sessions last until WordPress expires them). Add the Cloudflare bypass header to the
context's `extraHTTPHeaders`. Never commit storage state files.

```js
// Runs inside the editor page, as the body of an async function passed to page.evaluate().
// Open <site-url>/wp-admin/post.php?post=<post_id>&action=elementor first, then poll until
// elementor.documents.getCurrent().container.children.length > 0 (can take 3-5 s).
const doc = elementor.documents.getCurrent();
const root = doc.container;

// idempotency: skip if the block already exists in the preview
const preview = document.querySelector('#elementor-preview-iframe').contentWindow.document;
if (preview.body.innerText.includes('<UNIQUE-MARKER-TEXT>')) return { skip: true };

const box = $e.run('document/elements/create', {
  container: root,
  model: {
    elType: 'container',
    settings: {
      content_width: 'boxed',
      flex_direction: 'column',
      flex_gap: { unit: 'px', size: 16, sizes: [] },
      padding: { unit: 'px', top: '40', right: '24', bottom: '40', left: '24', isLinked: false },
    },
  },
});

$e.run('document/elements/create', {
  container: box,
  model: {
    elType: 'widget',
    widgetType: 'text-editor',
    settings: { editor: '<h3>Related guides</h3><ul><li><a href="<site-url>/<path>/">Anchor</a></li></ul>' },
  },
});

await $e.run('document/save/default');
```

- After the save promise resolves, navigating away still raises a `beforeunload` dialog from
  the preview iframe. Accept it; the page is already saved server-side.
- Template detection: if `container.children.length === 0` while the live URL clearly has body
  content, a Theme Builder template renders this page. Adding to the page does nothing.
- Then flush Elementor's cache and purge as usual.
- Prototype on one page interactively, then move the working `$e.run` code into a script
  when you need it on three or more pages.

## Theme Builder templates via WP-CLI

Building a single-post template without the editor:

- Store `_elementor_data` with `wp_slash` (see above). This is where the blank-render bug
  appears first, because dynamic tags contain escaped quotes.
- Dynamic tag format:
  `[elementor-tag id=\"<7-char-id>\" name=\"post-title\" settings=\"%7B%7D\"]`. The
  `settings` value is URL-encoded JSON (`%7B%7D` is `{}`), not base64 (`e30%3D` fails
  silently). Copy the exact format from a working template on the same site.
- Verify widget and tag names before using them:
  `wp eval 'print_r(array_keys(\Elementor\Plugin::instance()->widgets_manager->get_widget_types()));'`
  and `...->dynamic_tags->get_tags()`. The post info widget is `post-info`, not
  `theme-post-info`.
- Observed on one Elementor Pro build: `theme-post-title` rendered the heading placeholder
  and adding `__dynamic__.title` to it blanked the render. A plain `heading` widget with
  `__dynamic__.title` bound to `post-title` worked; the excerpt worked as a `text-editor`
  with `__dynamic__.editor` bound to `post-excerpt`. `theme-post-content` resolved on its own.
  `theme-post-featured-image` showed the placeholder even with a thumbnail set; rendering the
  image as a container background worked: `__dynamic__.background_image` bound to
  `post-featured-image`, background size `cover`, a `min_height` (or it collapses), a border
  radius, and empty `elements`. Copy the `background_background` value from an existing
  image-background container on the site.
- Register the condition in both places: the option
  `elementor_pro_theme_builder_conditions` (`$o['single'][<template_id>] = ['include/singular/post'];`,
  use `include/singular/page` for pages) and the template meta `_elementor_template_type`
  (`single-post`), `_elementor_conditions` (JSON `["include/singular/post"]`) and
  `_elementor_edit_mode` (`builder`).
- Verify without logging in: publish a throwaway post, fetch it, check the DOM, delete it.
  Or from the CLI:
  `setup_postdata(get_post(<post_id>)); echo strlen(\Elementor\Plugin::instance()->documents->get(<template_id>)->get_content());`
  A length of 0 means a blank render.
- Match the site's look by reading computed fonts and colors from the live page
  (`getComputedStyle`). The Kit can name a font the theme never renders.

Author bio pages on sites where `/author/<slug>/` is a Theme Builder archive: hooks such as
`loop_start` or the archive description filter land in the template's data, not the visible
DOM; a shortcode widget collapses block-level HTML; an author box widget dropped into the
archive template sat in layout limbo. What worked: a regular page (for example
`/about/<author-slug>/`) with the bio and photo, then set the user profile `url` field to
that page. Yoast emits it on the Person schema. Leave the archive template alone.

## Popups and scoped templates via REST

- **Display conditions:** writing `_elementor_conditions` meta over REST stores and reads
  back fine but does not rebuild Elementor Pro's aggregated conditions, so the template never
  applies. Use the site-editor endpoint, `GET` it first to learn the shape, then:

  ```
  PUT /wp-json/elementor/v1/site-editor/templates-conditions/<template_id>
  {"conditions": [{"type": "include", "name": "singular", "sub_name": "<post_type>", "sub_id": "<post_id>"}]}
  ```

  Conditions are objects, not strings. Strings return 500 "Cannot unset string offsets".
- **Scope a change to a few posts by adding a template,** not by editing the shared one.
  Conditions on specific ids outrank the post-type-wide template, and deleting the new
  template is a clean rollback.
- **Duplicating a template:** copy `_elementor_data` faithfully and keep every element id.
  Only genuinely new nodes get new ids.
- **WP Rocket Remove Unused CSS after a template edit:** on the observed site RUCSS rebuilt
  its per-URL used CSS only when a post was saved, not a template. Real visitors then got CSS pruned
  for the old DOM (empty hero, duplicated header, unstyled sections), while a cache-busted
  fetch bypasses WP Rocket and looks fine. Mobile Safari shows it worse than Chromium. Fix:
  re-save every affected post (`POST /wp/v2/<type>/<post_id>` with its current title fires
  `save_post`), then purge the page cache, Varnish and `DELETE /wp-json/elementor/v1/cache`.
  Verify on the real URL.

## Cross-site migration (copying a page between two installs)

Postmeta copy over WP-CLI, not export and import. HTTP 200 on every image does not mean the
migration worked. The two bugs that pass a status-code sweep:

1. **Attachment ids stored as strings.** Elementor stores an image control's id as a JSON
   number in most widgets but as a string (`"id": "<id>"`) in some. A remap that only matches
   integers leaves those ids stale while the `url` is updated correctly. With
   `image_size: "custom"`, Elementor renders by attachment id, ignores the URL, and generates
   a crop under `/uploads/elementor/thumbs/` from whatever unrelated attachment holds that id
   on the target. The crop returns 200; it's just the wrong photo. Compare `str(id)` to the
   map key regardless of type, and write the new id back in the original type.
2. **Orphaned Kit references.** Widgets point at site-wide styles through
   `settings.__globals__`, e.g. `{"title_color": "globals/colors?id=<kit-id>"}`. Those ids
   belong to the source site's Kit and almost never exist on the target. When an id doesn't
   resolve, Elementor's CSS compiler omits the property entirely: no error, no fallback, a
   heading with no `color:` rule. On one three-page migration this hit about 35 color and 83
   typography references. For every non-system entry, resolve the literal from the source Kit,
   write it into `settings`, and delete the `__globals__` entry. Typography keys carry their
   own prefix (`style_tooltip_typography_typography` -> prefix `style_tooltip_`), so expand
   every field the same way (`<prefix>typography_font_family`, `<prefix>typography_font_size`,
   and so on). System ids (`primary`, `secondary`, `text`, `accent`) exist in every Kit, but
   only leave them if both Kits define the same values: diff both `_elementor_page_settings`.

Transfer mechanics:

- Deduplicate media by content hash, not filename. Generic names like `1.png` collide with
  unrelated files. Filename-only dedup once created 27 needless duplicate attachments.
- `wp media import <file-or-url> --porcelain` returns the new attachment id. Same-named files
  get a `-1` suffix. If `wp db query` returns nothing for no clear reason,
  `wp post list --post__in=<ids> --fields=ID,guid` or `wp post list --s=<term>` still works.
- Rewrite both `url` and `id` in the JSON before writing the meta. Hero backgrounds live in
  CSS, not HTML, so verify them in the stored data or the compiled CSS.
- On WPML targets, a post created with `wp post create` has no language and is invisible in
  every wp-admin language filter. Set it with `wpml_set_element_language_details` (recipe in
  recipes.md).
- Flush Elementor CSS and caches after the write, then verify: grep the compiled
  `post-<post_id>.css` for the expected properties on each changed element, check every image
  crop by eye, and screenshot each section.

Recovering a section a rebuild dropped (often a mobile-only container): look for the old
`_elementor_data` in revisions or in a leftover table set in the same database. Move it out
base64-encoded from the server (`echo base64_encode($wpdb->get_var(...))`), because raw DB
output contains tabs and newlines that break parsing. Splice the container in with its ids,
set responsive visibility (`hide_desktop`, `hide_tablet`, `hide_mobile`), write slashed,
delete `_elementor_css`, purge. When both sets share the uploads folder, full image URLs
stay valid even though attachment ids differ.

## Forms

- Never test by submitting the live form. The safe protocol is in
  [recipes.md](recipes.md#testing-form-javascript-without-submitting).
- **GTM's built-in Form Submission trigger is wrong for Elementor Pro forms.** Elementor
  prevents the native submit and posts over AJAX, so the trigger fires before validation, or
  not at all in popups, and it also fires on search and newsletter forms. One site captured
  about 54% of real submissions this way. Elementor Pro pushes no dataLayer event by default
  (checked up to 4.0.4). Instead, a page-level listener for jQuery `submit_success` on
  `form.elementor-form` that pushes
  `{event: 'elementor_form_success', form_name, form_id, page_path}`, fired once per page load,
  plus a custom-event trigger. Validate against the site's own submissions:
  `GET /wp-json/elementor/v1/form-submissions?status=all&after=<date>&before=<date>&per_page=100`.
  A healthy setup lands within about 10%.
- Elementor's Webhook form action can be enabled with an empty URL. It looks configured in the
  data and does nothing. Check the URL value.
- Browser-side CRM collection scripts lose submissions to declined consent, ad blockers and
  script errors (about 13% on one site). A server-side hook on
  `elementor_pro/forms/new_record` that posts to the CRM's forms API does not.
- Count leads from the submissions table, not from analytics events.

## Editor freezes on one page

- **Symptom:** the editor loader clears, then the tab locks on one page while others open
  fine. Server logs are clean. Safe mode, toggling experiments and updating Elementor don't
  help.
- **Cause (Elementor 4.2.4):** `elementor.helpers.getInlineSvg` handles every icon with
  `library: "svg"`. It fetches the icon and then calls `view.render()` on the whole widget,
  with no tracking of requests already in flight. With N uploaded SVG icons in one slow widget
  (nested tabs, about 0.5 s per render) that is about N(N+1)/2 downloads and as many full
  re-renders. 14 icons meant 105 downloads and about 3.5 minutes frozen.
- **Diagnose headless,** not in a shared visible browser: `Profiler.start` can't run on a busy
  main thread but `Debugger.pause` can; map `scriptId` to URL via `Debugger.scriptParsed`;
  name the element with `Debugger.evaluateOnCallFrame` on `this.model`; hook
  `elementor.getContainer(<element_id>).view` and log `before:render`; count `.svg` requests
  and compare with N(N+1)/2.
- **Fix:** swap the uploaded SVGs for font icons (the data-level fix), or, if the design needs
  them, an editor-only inline script enqueued after the `elementor-editor` handle
  (`elementor/editor/after_enqueue_scripts`) that replaces `getInlineSvg`, dedupes in-flight
  ids and debounces `view.render()` per view. This is one of the few cases where code is
  justified: no setting avoids the path.
- WordPress 7.1's `wp-polyfill` replaces native `structuredClone` with a slower one. It makes
  renders more expensive but does not cause the freeze.

## Editor save side effects

Saving a page in the Elementor editor was observed to reset the post title to an older value
and empty the Yoast title, description and focus keyphrase, while the body survived. After
any editor save on a page you manage programmatically, re-apply title and Yoast meta through
REST and verify the rendered head. Entering the values once in the editor's own Yoast box
made them stick.
