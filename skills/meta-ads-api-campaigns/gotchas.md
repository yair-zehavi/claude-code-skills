# Gotchas: symptom -> cause -> fix

Each entry was hit at least once on a real account in 2026. Subcodes are Meta's
`error_subcode`; Meta changes these behaviours, so treat dates as "observed", not as
guarantees.

## Access and setup

**Writes fail with "contact an admin to get permission with Advertiser role or higher"**
-> the token's system user has Analyze on the ad account, which reads but cannot write
-> in Business Settings set the system user to Manage (Advertiser) on the ad account.
The system user should be Admin in the business and App Admin on the app, with
Manage access to the ad account, page, pixel (dataset) and any catalog. Token scopes
used: `business_management`, `ads_management`, `pages_show_list`,
`pages_read_engagement`, `pages_manage_ads`, `read_insights`, plus
`catalog_management` for catalog ads.

**Campaigns and ad sets create fine, creatives fail with "Ads creative post was
created by an app that is in development mode"**
-> a creative creates a page post under the hood, and Meta blocks that from apps in
Development mode -> switch the app to Live. That requires a privacy policy URL, an
icon, a category and a data deletion contact in the app's basic settings.

**Custom audience create fails with #2663, or customer-list upload fails with
subcode 1870090**
-> two separate terms of service: general Custom Audiences, and uploaded customer
lists. Accepting one does not accept the other -> an admin accepts both in the UI.

**Every write to an existing lead ad fails, even a pause: subcode 3390001
"Missing Lead Form"; creating an ad on an existing creative fails with 1885499
"No Advertiser Permission On Page"; `POST /<ad_id>/copies` fails with 1815199
"Ad Account Has No Access To Instagram Account"**
-> the token's business has no role on the page. Any ad-level write re-validates the
whole creative, including the page-owned instant form -> the client adds the
business as a page partner with the Advertise task and authorizes the Instagram
account for the ad account (`GET act_<ad_account_id>/connected_instagram_accounts`
shows what is usable). Insights, reads and campaign or ad set writes are unaffected.
Note that `ads?fields=creative{object_story_spec}` returns empty silently without
page access, while `GET /<ad_id>?fields=creative` and `GET /<ad_id>/adcreatives`
still read.

**URLs built from the env file 404 or hit the wrong object**
-> the ad account id is stored as a bare number -> prepend `act_` when it is missing.

## Campaign and ad set creation

**Subcode 4834011: "You must specify True or False in the field
is_adset_budget_sharing_enabled"**
-> required since mid-2026 on campaigns without campaign budget -> send `false` for
strict per-ad-set budgets, `true` to let Meta move up to 20% between ad sets.

**"Bid Amount Or Bid Constraints Required"**
-> an ABO ad set without `bid_strategy`, or an account whose default strategy is a bid
cap or cost cap -> send `bid_strategy=LOWEST_COST_WITHOUT_CAP`, or a `bid_amount` in
minor units. A starting cap of 1.0 to 1.5 times the historical cost per lead is
reasonable; too low and nothing delivers.

**Subcode 1870189: "Maximum age is too low for Advantage+ Audience"**
-> with `advantage_audience: 1` the age range is only a suggestion and `age_max` must
be 65 -> either keep Advantage+ Audience with 18-65, or set `advantage_audience: 0` to
enforce a narrower range.

**"Advantage Audience Flag Required"**
-> ad sets using a custom audience must state the flag -> set
`targeting_automation.advantage_audience` to 0 or 1 explicitly.

**Ads Manager blocks publishing with #1870194 ("location targeting option that has
been removed"), although the API create succeeded**
-> `geo_locations.location_types` was set to a single value. Around January 2026 Meta
collapsed the options into one, "living in or recently in" = `["home","recent"]`.
`["travel_in"]` fails at once (subcode 1870199), but `["home"]` and `["recent"]` pass
the create and even `execution_options=["validate_only"]`, so the API is not a
reliable check here -> omit `location_types`, or PATCH the targeting without it
(Meta restamps `["home","recent"]`). The banner can stay visible after the fix;
trust `effective_status: ACTIVE` with empty `issues_info`, and confirm next-day
impressions.

**"radius isn't within bounds" on a city exclusion**
-> excluded cities do not take a radius -> exclude by `key` only.

**#1487110 on an included city**
-> radius below Meta's minimum, about 17 km (10 miles) observed in 2026 -> use at
least that, or a region key.

**"Invalid targeting category class"**
-> employer, job title and school (education institution) targeting classes are
retired -> cover the intent with interests, and run employer or job-title targeting
on a channel that supports it natively.

**#1870247 on an interest**
-> the interest was deprecated; Meta suggests an official replacement -> use the
replacement only if it is still on target, otherwise drop it.

**Subcode 1504040 when changing the attribution window**
-> `attribution_spec` is immutable once set (an ad set created without one accepted a
single later set) -> create a new ad set, which restarts learning.

**Catalog ads: "not supported for objective WEBSITE_CONVERSIONS", "Promoted Object
is Required" or "performance goal isn't available"**
-> catalog and product set ids on the wrong level -> `product_catalog_id` on the
campaign `promoted_object`, `product_set_id` plus `custom_event_type` on the ad set,
`product_set_id` again at the top of the creative. See `api-recipes.md`.

**Wanting to change optimization goal, billing event, pixel or conversion event on
an existing ad set**
-> treat these as locked at creation -> build a new ad set.

## Creatives and media

**Error 100 / subcode 1443226: "The field link_url is not supported in the field
video_data of object_story_spec"**
-> the link is at the wrong level (the official CLI's single-video mode does this) ->
put it in `video_data.call_to_action.value.link`.

**"The video is still being processed"**
-> the creative was created before the upload finished processing -> poll
`GET /<video_id>?fields=status` until `video_status` is `ready`.

**Video creative rejected for a missing image, or created without a preview**
-> no thumbnail in `video_data` -> pass `image_hash` (an extracted frame uploaded as an
image) or `image_url` (the video's preferred thumbnail).

**Subcode 1772103 "Instagram Account Is Missing", or "must be a valid Instagram
account id"**
-> Instagram placements without an Instagram identity, or the legacy
`instagram_actor_id` field -> read `instagram_business_account` from the page and pass
its id as `instagram_user_id` in `object_story_spec`.

**Subcode 3858504: "Creative should not include standard enhancements"**
-> `degrees_of_freedom_spec.creative_features_spec.standard_enhancements` is
deprecated -> omit it (when cloning a creative, keep the other feature keys).

**Subcode 1815573 when updating a creative: "Please specify name, status or
associated adlabels for updating"**
-> copy, link, CTA and `url_tags` are immutable after creation -> create a new
creative and swap it onto the ad. In practice copy or image edits on a live creative
are also often ignored, so always use the swap.

**"Dynamic Creative Ad Set allows at most one ad", with `is_dynamic_creative` false**
-> a single-image ad built as a one-image `asset_feed_spec` -> build single-image ads
with `object_story_spec.link_data`.

**A square image shows letterboxed with black bars in Stories or Reels**
-> a single-asset creative plays the same file on every placement, and boosted-post
creatives usually have `adapt_to_placement` opted out -> supply a 9:16 asset through
`asset_customization_rules` at creation (build it with `adapt_placement.py` if the
designer did not). The CLI's multi-image mode treats extra images as A/B variants,
not placement assets.

**Images or video re-uploaded on every rerun**
-> no upload cache -> keep `{local file: image hash or video id}` in a JSON file.

## Ads and edits

**A paused ad is ACTIVE after a creative swap**
-> `POST /<ad_id>` with a new `creative` resets status to ACTIVE even when `status` was
not sent -> send the intended status in the same call, read back, re-pause in a
separate call if needed. See `api-recipes.md` section 8.

**Ads you expected paused read ACTIVE**
-> often a human launched them minutes ago -> check `updated_time` before "fixing";
re-pausing would undo a deliberate launch.

**`GET /<ad_id>?fields=multi_advertiser_ads` returns 400 "nonexisting field"**
-> the field is write-only -> trust the write's `success: true`, keep it in the state
file. Setting it on a creative fails with 1815573; it belongs on the ad (and the
`is_multi_advertiser_ads_enabled` form on the ad set). There is no account-level
default.

**Delivery restarts learning after small edits**
-> budget changes over about 20%, bid changes, creative swaps and targeting changes
all re-enter learning (about 50 conversions); ads paused for more than 7 days reset
the ad set's learning too -> batch edits, and do not touch budget during a test.

**Leftover creatives with no ad after a failed run**
-> each step that succeeded before the failure left its object behind -> delete
orphans (`DELETE /<creative_id>`) before reporting.

**A script quietly writes to an archived ad set**
-> hard-coded ids went stale -> validate every reused id with
`GET /<id>?fields=name,status,campaign{name,status,objective}` before writing.

**Lead automation maps empty fields**
-> instant form field keys differ per form (one form uses `first_name` and
`last_name`, another `full_name`) -> read the real keys before mapping. The form id
is readable from the creative's `asset_feed_spec.lead_gen_form_id` with the ad
account token, even when page endpoints refuse.

## Errors that are not what they look like

**Error 2446386 "Image Not Found" on some freshly created video ads**
-> usually a transient review glitch, not a broken creative. It is per ad: other ads
on the same `creative_id` are clean, and the creative's `thumbnail_url` returns 200
-> re-POST the ad with its own `creative_id` to force re-review. Status stayed
unchanged when observed; read it back anyway. Do not delete, re-upload or rebuild.

**A script only logs "HTTP Error 400: Bad Request"**
-> urllib's exception string drops the body -> read the body and log
`error_user_title`, `error_user_msg`, `code` and `error_subcode`. In one account a
budget script logged only "Bad Request" while the real error was "Advertiser is
missing".

**"Advertiser is missing" (code 100, subcode 3858634) on a plain budget change**
-> from September 2026 every ad set targeting Australia needed a verified advertiser;
verifying the business is necessary but not sufficient -> set
`regional_regulated_categories` and `regional_regulation_identities` on each ad set
(`api-recipes.md` section 4). Paused legacy ad sets will fail the same way the day
someone reactivates them.

**`WARNING:root:parent_id is being deprecated.` during CLI uploads**
-> deprecation notices from the SDK -> not failures; ignore.

**Every account in a collector errors at the same moment (DNS or "fetch failed")**
-> a momentary network drop, not expired credentials -> run the collector directly
before debugging; cache a degraded result for minutes, not the full cache window.

**curl answers instantly but Python calls hang 15-20 s each and time out**
-> the API host resolves to IPv6 first, the network has no working IPv6 route, and
urllib has no Happy Eyeballs fallback -> make the script prefer IPv4 (patch
`socket.getaddrinfo` to return `AF_INET` results first).

**`validate_only` passes, the real publish fails**
-> some checks (the removed location options above) only run at Ads Manager publish
-> verify with `effective_status`, `issues_info` and next-day impressions.

## Reporting and tracking

**Spend or ROAS reported with the wrong currency symbol**
-> currency inferred from the client's market or language -> read `currency` from the
ad account and store it in the client config.

**Month-end spend lower than the real figure**
-> pulled before the account's month closed; an account on a US timezone closes many
hours later than a European working day starts -> read `timezone_name`, pull after
the close plus a few hours, and if an early figure already went out, correct every
document built on it together.

**A spike in GA4's catch-all channel (traffic the default grouping cannot match)**
-> a custom `utm_medium` such as `Facebook` that GA4's default channel grouping does
not recognise -> analyse with `sessionSourceMedium`, and use a standard medium.

**One channel split across two GA4 rows (`facebook / cpc` and `FACEBOOK / CPC`, or an
old and a new scheme)**
-> mixed UTM conventions -> consolidate the rows in reports and keep one scheme per
account.

**ROAS looks too good**
-> `purchase`, `omni_purchase` and `offsite_conversion.fb_pixel_purchase` were summed;
on one account they were the same event -> count one of them (see `playbook.md`).

**Lead counts lower than the CRM**
-> the report reads the generic `lead` action while the campaigns optimise for a custom
conversion, or a stale copy of the reporting script ignores the per-account override
-> define the result action per account and keep a single canonical puller.

**Paid clicks land slowly on a cached site**
-> every `fbclid` or `utm_*` URL is a unique cache key, so each paid visitor misses the
cache -> strip tracking parameters before the cache key is computed (for example a CDN
transform rule). The browser URL keeps them, so the pixel and GA4 still see them.

**Link previews show a blank grey image**
-> a WAF challenge rule blocks Meta's crawler (`facebookexternalhit`) from fetching
the `og:image` -> exempt the crawler from the challenge only when the user agent
matches and the request is a verified bot or from Meta's network. You cannot test
this with curl; use Meta's Sharing Debugger and "Scrape Again".

**Landing URL costs a redirect hop**
-> the ad points at a non-canonical URL that redirects (for example a shop category
path that 308s to its canonical form) -> use the canonical URL in the creative.
