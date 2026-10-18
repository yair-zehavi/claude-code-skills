# API recipes

Generic payloads for each step in `SKILL.md`. All observed working on Graph API
v21 to v24 during 2026; pin the version your account's scripts already use. Values
in angle brackets are placeholders. Python uses `requests`; Meta's official SDK
(`facebook_business`) takes the same parameter names.

## Contents

1. Helper that surfaces the real error
2. Preflight reads
3. Campaign
4. Ad set (placements, budget, bid, Australia)
5. Media upload (image, video, thumbnail)
6. Creatives (single image, video, multi-ratio, existing post)
7. Ad
8. Creative swap without an accidental launch
9. Re-review nudge for a spurious "Image Not Found"
10. Orphan cleanup
11. Build script skeleton with a state file
12. Share link -> post id
13. Custom audiences
14. Catalog (dynamic product) ads
15. Repairing a stuck `location_types`
16. Delivery monitoring query
17. Meta's official ads CLI

---

## 1. Helper that surfaces the real error

Graph explains every 400 in the response body. `str(HTTPError)` is only
"Bad Request", which hid a verification problem in one account.

```python
import json, os, time, requests

GRAPH = "https://graph.facebook.com/v22.0"
TOKEN = os.environ["ACCESS_TOKEN"]          # loaded from the client's env file, never printed
ACT = os.environ["AD_ACCOUNT_ID"]
ACT = ACT if ACT.startswith("act_") else f"act_{ACT}"   # env files often store the bare number

class GraphError(RuntimeError):
    pass

def _call(method, path, **params):
    params["access_token"] = TOKEN
    for k, v in list(params.items()):
        if isinstance(v, bool):
            params[k] = "true" if v else "false"
        elif isinstance(v, (dict, list)):
            params[k] = json.dumps(v)      # nested specs go form-encoded as JSON strings
    try:
        r = requests.request(method, f"{GRAPH}/{path}",
                             params=params if method != "POST" else None,
                             data=params if method == "POST" else None, timeout=60)
    except requests.RequestException as ex:
        # connection errors quote the request URL, and a GET URL carries the token
        raise GraphError(f"{method} {path}: {type(ex).__name__}") from None
    body = r.json()
    if "error" in body:
        e = body["error"]
        raise GraphError(f"{e.get('error_user_title') or ''} | "
                         f"{e.get('error_user_msg') or e.get('message')} "
                         f"[code {e.get('code')}/{e.get('error_subcode')}]")
    return body

get = lambda path, **p: _call("GET", path, **p)
post = lambda path, **p: _call("POST", path, **p)
delete = lambda path, **p: _call("DELETE", path, **p)
```

## 2. Preflight reads

```python
get(ACT, fields="name,currency,timezone_name,account_status")
get("me/accounts")                                   # pages this token can act on
get(f"{ACT}/connected_instagram_accounts")           # IG accounts usable by this ad account
get("<page_id>", fields="instagram_business_account{id,username}")
get("<creative_id>", fields="asset_feed_spec,object_story_spec,url_tags")   # a top performer to mirror
get("<id>", fields="name,status,campaign{name,status,objective}")         # every id you will reuse
```

## 3. Campaign

```python
camp = post(f"{ACT}/campaigns",
    name="<Type> | <Brand> | <Geo> | <Month YYYY>",
    objective="OUTCOME_LEADS",            # or OUTCOME_SALES, OUTCOME_TRAFFIC, ...
    status="PAUSED",
    special_ad_categories=[],             # empty only when no special category applies
    buying_type="AUCTION",
    is_adset_budget_sharing_enabled=False # required on ABO; True lets Meta share up to 20% across ad sets
)
# CBO instead: drop is_adset_budget_sharing_enabled, add
#   daily_budget="<minor units>", bid_strategy="LOWEST_COST_WITHOUT_CAP"
```

## 4. Ad set

```python
targeting = {
    "geo_locations": {"countries": ["<CC>"]},   # no location_types: Meta fills ["home","recent"]
    "age_min": 25, "age_max": 65,
    "flexible_spec": [{"interests": [{"id": "<interest_id>", "name": "<name>"}]}],
    "targeting_automation": {"advantage_audience": 0},   # 0 keeps the targeting strict; 1 needs age_max 65
    "publisher_platforms": ["facebook", "instagram"],
    "facebook_positions": ["feed", "story", "facebook_reels"],
    "instagram_positions": ["stream", "story", "reels"],
    "device_platforms": ["mobile", "desktop"],
}
adset = post(f"{ACT}/adsets",
    name="<Geo or Audience> | <Goal>",
    campaign_id=camp["id"],
    status="PAUSED",
    daily_budget="<minor units>",               # ABO only; or lifetime_budget + end_time
    billing_event="IMPRESSIONS",
    optimization_goal="OFFSITE_CONVERSIONS",    # LEAD_GENERATION for instant forms, LANDING_PAGE_VIEWS for traffic
    bid_strategy="LOWEST_COST_WITHOUT_CAP",     # required on ABO; bid-cap accounts also need bid_amount
    promoted_object={"pixel_id": "<pixel_id>", "custom_event_type": "LEAD"},
    targeting=targeting,
    start_time="<ISO 8601>",
)
post(adset["id"], is_multi_advertiser_ads_enabled="false")   # write-only; keep the success:true
```

Targeting details:

- Exclude a city by key only, no radius:
  `"excluded_geo_locations": {"cities": [{"key": "<city_key>"}]}`.
- Find keys: `get("search", type="adgeolocation", location_types='["city"]', q="<name>")`.
  Some small towns are not in Meta's geo database at all.
- Included cities with a radius need at least about 17 km (10 miles); below that the
  create fails (#1487110, observed 2026).
- Set the attribution window in this create call. To match an existing ad set, read
  `get("<adset_id>", fields="attribution_spec")` and pass the same value. Once set it
  cannot be changed (subcode 1504040); an ad set created without one accepted a
  single later set.

**Ad sets that target Australia** (observed from September 2026 on every such ad
set, not only regulated categories) need a verified advertiser, at create or as a repair on an
existing ad set:

```python
post("<adset_id>",
     regional_regulated_categories=["VOLUNTARY_VERIFICATION"],
     regional_regulation_identities={"universal_beneficiary": "<verified_business_id>",
                                     "universal_payer": "<verified_business_id>"},
     daily_budget="<unchanged value>")   # sending the budget in the same call worked
```

Meta stores it back as `AUSTRALIA_UNIVERSAL`. Keys named `australia_universal_*` are
rejected. The page id is not needed. The business must first be verified in Security
Center, but that alone does not fix the ad sets.

## 5. Media upload

```python
# Image -> hash
with open("<path>.jpg", "rb") as f:
    r = requests.post(f"{GRAPH}/{ACT}/adimages", files={"filename": f},
                      data={"access_token": TOKEN}).json()
image_hash = next(iter(r["images"].values()))["hash"]

# Video -> id, then poll until ready (10-30 s for short clips)
with open("<path>.mp4", "rb") as f:
    vid = requests.post(f"{GRAPH}/{ACT}/advideos", files={"source": f},
                        data={"access_token": TOKEN}).json()["id"]
while True:
    s = get(vid, fields="status")["status"]["video_status"]   # processing | ready | error
    if s == "ready":
        break
    if s == "error":
        raise SystemExit("video processing failed")
    time.sleep(10)

# Thumbnail: either Meta's preferred one ...
thumbs = get(f"{vid}/thumbnails", fields="uri,is_preferred")["data"]
thumb_url = next((t["uri"] for t in thumbs if t.get("is_preferred")), thumbs[0]["uri"])
# ... or a frame you extract (ffmpeg -ss 1.5 -i in.mp4 -vframes 1 thumb.jpg) uploaded as an image hash.
```

Cache `{local_path: video_id}` in a JSON file and check it before uploading.

## 6. Creatives

**Single image** (non-dynamic ad set). Use `link_data`, not a one-image
`asset_feed_spec`, which fails with "Dynamic Creative Ad Set allows at most one ad".

```python
cr = post(f"{ACT}/adcreatives",
    name="<Brand> | <Ad name>",
    object_story_spec={
        "page_id": "<page_id>",
        "instagram_user_id": "<ig_user_id>",
        "link_data": {
            "image_hash": image_hash,
            "link": "<canonical landing URL, no UTMs>",
            "message": "<primary text>",
            "name": "<headline>",
            "call_to_action": {"type": "LEARN_MORE", "value": {"link": "<same URL>"}},
        },
    },
    url_tags="utm_source=facebook&utm_medium=paid_social&utm_campaign=<key>"
             "&utm_term={{adset.id}}&utm_content={{ad.id}}",
)
```

**Single video.** The link goes inside `call_to_action.value.link`; a top-level
`link_url` in `video_data` is rejected.

```python
"object_story_spec": {
    "page_id": "<page_id>", "instagram_user_id": "<ig_user_id>",
    "video_data": {
        "video_id": vid,
        "image_hash": "<thumbnail hash>",          # or "image_url": thumb_url
        "title": "<headline>", "message": "<primary text>",
        "call_to_action": {"type": "SHOP_NOW", "value": {"link": "<landing URL>"}},
    },
}
```

**Two ratios in one ad** (square to feeds, vertical to stories and reels). Labels
can be shared by both rules; there is no need to duplicate the text assets.

```python
asset_feed_spec = {
    "images": [{"hash": "<square_hash>",   "adlabels": [{"name": "img_square"}]},
               {"hash": "<vertical_hash>", "adlabels": [{"name": "img_vertical"}]}],
    # for video use "videos": [{"video_id": ..., "adlabels": [...]}] and "video_label" in the rules
    "bodies":    [{"text": "<primary text>", "adlabels": [{"name": "body"}]}],
    "titles":    [{"text": "<headline>",     "adlabels": [{"name": "title"}]}],
    "link_urls": [{"website_url": "<landing URL>", "adlabels": [{"name": "link"}]}],
    "call_to_action_types": ["SHOP_NOW"],
    "ad_formats": ["AUTOMATIC_FORMAT"],          # SINGLE_VIDEO for video
    "optimization_type": "PLACEMENT",
    "asset_customization_rules": [
        {"customization_spec": {"publisher_platforms": ["facebook", "instagram"],
                                "facebook_positions": ["story", "facebook_reels"],
                                "instagram_positions": ["story", "reels"]},
         "image_label": {"name": "img_vertical"}, "body_label": {"name": "body"},
         "title_label": {"name": "title"}, "link_url_label": {"name": "link"}, "priority": 1},
        {"customization_spec": {},               # empty = catch-all for the remaining placements
         "image_label": {"name": "img_square"}, "body_label": {"name": "body"},
         "title_label": {"name": "title"}, "link_url_label": {"name": "link"}, "priority": 2},
    ],
}
cr = post(f"{ACT}/adcreatives", name="<name>",
          object_story_spec={"page_id": "<page_id>", "instagram_user_id": "<ig_user_id>"},
          asset_feed_spec=asset_feed_spec, url_tags="<utm string>")
```

Any `instagram_positions` in the rules requires `instagram_user_id` in
`object_story_spec`.

**Existing page post** (keeps likes and comments). Adding a CTA to the post works:

```python
cr = post(f"{ACT}/adcreatives", name="<name>",
          object_story_id="<page_id>_<post_id>",
          call_to_action={"type": "APPLY_NOW", "value": {"link": "<landing URL>"}},
          instagram_user_id="<ig_user_id>",
          url_tags="<utm string>")
```

Do not send `degrees_of_freedom_spec.creative_features_spec.standard_enhancements`;
it is deprecated and rejected. When cloning a creative, copy the rest of
`creative_features_spec` but drop that key.

## 7. Ad

```python
ad = post(f"{ACT}/ads",
    name="Ad <n> | <hook>",
    adset_id=adset["id"],
    creative={"creative_id": cr["id"]},
    status="PAUSED",
    multi_advertiser_ads={"ads_enabled": "OPT_OUT"},   # write-only, cannot be read back
)
```

## 8. Creative swap without an accidental launch

```python
before = get(ad_id, fields="status,updated_time")
post(ad_id, creative={"creative_id": new_creative_id}, status=before["status"])
after = get(ad_id, fields="status,effective_status")
if after["status"] != before["status"]:
    post(ad_id, status=before["status"])           # e.g. PAUSED, as its own call
    assert get(ad_id, fields="status")["status"] == before["status"]
```

Keep a state file of `{ad_id: old_creative_id, new_creative_id, status}` so a
rollback can point every ad back at its original creative.

## 9. Re-review nudge for a spurious "Image Not Found"

Error 2446386 on a freshly created video ad is usually transient. Confirm all three
before acting: another ad on the same `creative_id` is clean, the creative's
`thumbnail_url` returns HTTP 200, and the flag does not clear on its own after a
few minutes. Then:

```python
post(ad_id, creative={"creative_id": same_creative_id})
```

The ad moves `WITH_ISSUES -> IN_PROCESS -> PENDING_REVIEW` within about a minute.
Its status stayed unchanged when this was observed, but read it back anyway, since a
swap to a different creative does reset it (section 8). Make the repair idempotent:
scan the run's state file and nudge only the flagged ads. Deleting, re-uploading or pinning a thumbnail were all
unnecessary.

## 10. Orphan cleanup

```python
for cid in state.get("creatives_without_ad", []):
    delete(cid)
```

Run it at the end of every failed or partial run, before reporting success.

## 11. Build script skeleton with a state file

```python
from pathlib import Path

# CAMPAIGN, ADSET and ADS hold the payloads from sections 3, 4 and 6
STATE = Path("data/<campaign-key>.json")
state = json.loads(STATE.read_text()) if STATE.exists() else {"ads": {}}
save = lambda: STATE.write_text(json.dumps(state, indent=2, ensure_ascii=False))

if not state.get("campaign_id"):
    state["campaign_id"] = post(f"{ACT}/campaigns", **CAMPAIGN)["id"]; save()
if not state.get("adset_id"):
    state["adset_id"] = post(f"{ACT}/adsets", campaign_id=state["campaign_id"], **ADSET)["id"]; save()
for key, spec in ADS.items():
    a = state["ads"].setdefault(key, {})
    if not a.get("creative_id"):
        a["creative_id"] = post(f"{ACT}/adcreatives", **spec["creative"])["id"]; save()
    if not a.get("ad_id"):
        a["ad_id"] = post(f"{ACT}/ads", adset_id=state["adset_id"],
                          creative={"creative_id": a["creative_id"]}, status="PAUSED",
                          multi_advertiser_ads={"ads_enabled": "OPT_OUT"})["id"]; save()
print("all levels PAUSED")
```

One script means one approval prompt instead of dozens, and the state file is what
lets you resume after the failures you will hit. Image and video uploads are slow;
give the run a timeout of several minutes.

## 12. Share link -> post id

Clients send `facebook.com/share/p/<code>/` links. The Graph API cannot resolve
them: the pfbid query returns "(#12) singular statuses API is deprecated" and the
page post edges need a page token an ads token does not have.

```bash
curl -s -o /dev/null -w '%{redirect_url}' 'https://www.facebook.com/share/p/<code>/'
# -> https://www.facebook.com/<page>/posts/pfbid.../
```

Plain curl on the permalink gets a small login wall. A headless browser, logged out,
gets the full page with the numeric id in the HTML:

```python
import re
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    page = p.chromium.launch().new_page()
    page.goto(PERMALINK, wait_until="networkidle")
    html = page.content()
m = re.search(r'"(?:post_id|story_fbid|top_level_post_id)":"(\d+)"', html)
object_story_id = f"<page_id>_{m.group(1)}"
```

## 13. Custom audiences

- Two separate terms of service, each accepted by an admin in the UI: the general
  Custom Audience terms (unlocks website and engagement audiences and lookalikes,
  error #2663) and the customer-list terms (subcode 1870090). Accepting one does not
  accept the other.
- Website audience: no `subtype` parameter (rejected). Send `rule` (JSON with
  `event_sources` pointing at the pixel) and `prefill=true`. `subtype` is only for
  customer-file (`CUSTOM`) and `LOOKALIKE` audiences.
- Customer-list upload by API: SHA-256 each field after normalizing (email lowercased
  and trimmed, phone as digits in E.164 with country code, names lowercased). Upload
  in chunks so partial progress persists, and retry 403, 429 and 5xx with backoff.
  Several upload sessions to the same audience append.
- Value-based list for the UI upload: see `playbook.md`.

## 14. Catalog (dynamic product) ads

1. Campaign: `objective=OUTCOME_SALES`,
   `promoted_object={"product_catalog_id": "<catalog_id>"}`. The catalog goes on the
   campaign; the legacy `PRODUCT_CATALOG_SALES` objective is rejected.
2. Ad set: `optimization_goal=OFFSITE_CONVERSIONS`,
   `promoted_object={"product_set_id": "<product_set_id>", "custom_event_type": "PURCHASE"}`.
3. Creative: top-level `product_set_id` plus `object_story_spec.template_data` using
   `{{product.name}}` and `{{product.price}}`.

Wrong placements of these ids produce misleading errors; see `gotchas.md`.

## 15. Repairing a stuck `location_types`

```python
t = get(adset_id, fields="targeting")["targeting"]
t["geo_locations"].pop("location_types", None)
post(adset_id, targeting=t)          # Meta restamps ["home","recent"]
```

## 16. Delivery monitoring query

```python
from datetime import date, timedelta

rows = get(f"{ACT}/insights", level="account", date_preset="last_14d",
           time_increment=1, fields="spend")["data"]
yesterday = date.today() - timedelta(days=1)     # last_14d ends yesterday, in the account's timezone
cutoff = (yesterday - timedelta(days=7)).isoformat()
recent = sum(float(r["spend"]) for r in rows if r["date_start"] > cutoff)
prior  = sum(float(r["spend"]) for r in rows if r["date_start"] <= cutoff)
went_dark = prior > 0 and recent == 0        # both zero = baseline, stay silent

camps = get(f"{ACT}/campaigns", fields="effective_status", limit=500)["data"]
with_issues = sum(c["effective_status"] == "WITH_ISSUES" for c in camps)
```

Missing days in `rows` are zero-spend days; summing whatever comes back is correct.

## 17. Meta's official ads CLI

- The package is `meta-ads` on PyPI and the binary is `meta`. `meta-ads-cli` is an
  unrelated third-party package with a binary named `meta-ads`. Confirm the install
  pulls in `facebook-business`, Meta's SDK.
- It reads `ACCESS_TOKEN`, `AD_ACCOUNT_ID` and `BUSINESS_ID`. A small wrapper that
  activates the venv, sources the client's env file and maps prefixed names keeps
  each call to one line and lets you allowlist read commands only:

```bash
#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/.venv/bin/activate"
set -a; source "$DIR/.env"; set +a
export ACCESS_TOKEN="${META_ACCESS_TOKEN:?missing}"
export AD_ACCOUNT_ID="${META_AD_ACCOUNT_ID:?missing}"
export BUSINESS_ID="${META_BUSINESS_ID:-}"
exec meta "$@"
```

- Creates default to paused. Status changes go through
  `<level> update <id> --status paused|active|archived`; there is no separate pause
  or activate subcommand.
- Not exposed (observed in 1.0.x): city, ZIP, age, interest and audience targeting
  (only a country list flag), placement-specific assets, bid strategy. Use the
  Graph API for those.
- Video through the CLI is unreliable in 1.0.x: single-video mode puts `link_url` at
  the wrong level, and multi-video mode creates the creative before the video
  finishes processing, then re-uploads on every retry. Upload and build video
  creatives with the API (section 5 and 6) and use the CLI for the ad if you like.
- `WARNING:root:parent_id is being deprecated` lines are SDK deprecation notices, not
  failures.
- `adaccount list` can return "No results" while everything else works; not a blocker.
