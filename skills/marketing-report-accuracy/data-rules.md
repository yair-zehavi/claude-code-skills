# Data rules per source

Accuracy rules, queries and filters for each data source in a monthly multichannel
report. Most rules here came from a report that shipped wrong or nearly did.
Placeholders: `<property_id>`, `<customer_id>`, `<ad_account_id>`, `<site-url>`,
`<since>` / `<until>` (ISO dates of the report month), `<prefix>` (WordPress table prefix).

## Month closure and settling

**Ad accounts close their month in their own timezone.** Read the reporting timezone
from each account once, store it in config, and compute when the month closed in
your local time. Observed in 2026:
- Accounts on US Pacific time close about 9 hours after midnight Central European time.
- Accounts on New York time close about 6 hours after.
- LinkedIn Campaign Manager counted days in UTC (it showed next-month spend while
  the previous day was still running on Pacific time).
- Accounts on the same timezone as the agency close at midnight.

Pull money figures only after the account's month has closed plus a few hours for
the platform to settle. A scheduled run at 02:00 local missed about 4 hours of the
last day on a New York account. If an early figure already went out (report,
billing sheet, invoice email), re-pull, then correct every artifact that used it
in one pass.

**Google data settles after the month closes.** GA4 can take about 48h to finish
processing a day. Search Console lags about 3 days even when queried with
`dataState: "all"`. Measured on one property, a pull early on the 1st understated GA4
key events, GA4 event counts, Search Console clicks and impressions and Google Ads
conversions, each by roughly 1 to 3%. Months pulled on the 2nd or later matched live
exactly, which is why the failure is easy to miss.

Guard in the pull script:
```bash
# refuse to pull a period that ended less than 3 days ago
END_EPOCH=$(date -j -f %Y-%m-%d "<until>" +%s)   # GNU: date -d "<until>" +%s
DAYS=$(( ( $(date +%s) - END_EPOCH ) / 86400 ))
if [ "$DAYS" -lt 3 ] && [ -z "$PULL_ALLOW_FRESH" ]; then
  echo "Too fresh: period ended $DAYS day(s) ago. Earliest safe pull: 3 days after <until>."
  exit 1
fi
```
`PULL_ALLOW_FRESH=1` is the explicit override for a deliberate mid-month look.
To audit past months, re-pull a shipped month and diff it against its snapshot. Any
difference means that report went out stale.

## Currency

- Meta Graph API returns `spend`, `action_values`, cost per result and ROAS in the
  **ad account's currency**, not USD. Read `currency` from the ad account
  (`GET act_<ad_account_id>?fields=currency`) and cache it in config.
- Google Ads cost comes as `cost_micros` (divide by 1e6); the currency is
  `customer.currency_code`.
- LinkedIn: the invoice currency is authoritative; the accounts endpoint often omits
  a currency field.
- **Never infer currency from market or language.** An English-language,
  US-market brand can bill in ILS; a local-language brand can bill in USD.
- **Verify every cycle against the API, not against your own files.** A puller that
  hardcoded the wrong currency ("confirmed via probe", a wrong probe) made the
  generator divide already-USD spend by an FX rate. The report showed a fraction of
  the real spend, and snapshot, context and HTML all agreed with each other, so the
  reviewer could not catch it.
- **No fixed-rate FX conversion in a report.** An account in a second currency gets
  its own KPI row in its own currency and stays out of the main-currency spend total.
  Its leads still count toward the lead total.
- A card issuer charging a USD account in local currency produces a gap against the
  platform figure. That is FX, not timezone, and it is never explained in the report.
- If a converted figure is needed outside the report (billing), fix one documented
  rule and back-test it against what was actually billed. Observed in 2026: the spot
  rate on the last day of the month reproduced billed figures exactly; a month
  average did not.
- Render with a symbol map (`ILS: ₪, USD: $, AUD: A$, GBP: £, EUR: €`) and one
  `fmt_money(value, currency)` helper. The same symbol for two different currencies
  on one page is a block.

## Ad platforms

### Meta
- Campaign insights: `GET act_<ad_account_id>/insights?level=campaign&fields=campaign_name,spend,impressions,clicks,actions&time_range={"since":"<since>","until":"<until>"}`.
- **The lead number is the Results column the client sees in Ads Manager.** Action
  types overlap: `lead`, `lead_grouped` and the pixel lead are the same conversions,
  so summing them counts one conversion several times. In one account the summed
  figure, the bare `lead` figure (about half the truth) and the right one, the custom
  pixel conversion (`offsite_conversion.fb_pixel_custom`) shown as "Website
  Contacts", were three different numbers. Other accounts' real result was
  `offsite_conversion.custom.<id>`, `purchase` (sales objective), or messaging
  conversations (a messaging campaign with almost no `lead` actions).
- Store the action in config (`ads.meta.lead_action_type`). The default `lead` may use
  an aggregate-with-fallback; any other value is exact-match with no fallback.
- Audit after the first pull and whenever a campaign launches:
  ```python
  import json
  snap = json.load(open("<snapshots>/meta-<since>_<until>.json"))
  for c in snap["campaigns"]:
      if not c["spend"]:
          continue
      print(c["campaign_name"], "leads=", c["leads"])
      for k, v in sorted(c.get("actions", {}).items(), key=lambda x: -x[1]):
          if any(t in k for t in ("lead", "custom", "purchase")):
              print("   ", k, v)
  ```
  Matching the CRM's recorded leads is the gold standard. A campaign named for one
  channel ("WhatsApp") can be a website-form campaign; check the action, not the name.
- **Objective framing.** Engagement, awareness and traffic campaigns are never
  headlined "0 leads"; report reach, clicks and spend. Lead and sales campaigns keep
  leads and cost per lead.

### Google Ads
- Customer IDs are digits only in env and config. The API rejects the dashed UI form
  with `INVALID_CUSTOMER_ID`.
- Campaign totals:
  ```sql
  SELECT campaign.name, metrics.cost_micros, metrics.clicks,
         metrics.impressions, metrics.conversions
  FROM campaign
  WHERE segments.date BETWEEN '<since>' AND '<until>'
  ```
  Currency: `SELECT customer.currency_code FROM customer`.
- Conversions come back fractional (data-driven attribution). Round to integers
  before adding them to a lead total.
- When the account's own conversion tracking is unreliable (no purchase tracking,
  only calls, actions never set up), compute per-campaign results from GA4 instead.
  See [Per-campaign attribution](#per-campaign-attribution).

### LinkedIn
- With no ads API wired up, a stable route observed in 2026 is replaying a saved
  Campaign Manager browser session and calling the same internal endpoints the UI
  uses (undocumented, may change): `campaignManagerReportingCampaigns` to list
  campaigns with id, name and status, `campaignManagerAdAnalytics` for metrics
  (POST with `x-http-method-override: GET`, `csrf-token` = the `JSESSIONID` cookie
  without quotes), and the account's billing endpoint for invoices. This is far more
  stable than scraping the metrics table, which interleaves compare-to-previous
  percentages into the cells.
- Filter analytics with `campaignIds=List(...)`. Filtering with `accounts=List(urn...)`
  returned zero elements.
- **Shared ad accounts** (one account carrying two brands' campaigns): attribute by
  an explicit campaign-ID list in config, and have the puller emit every campaign
  with spend that no client claims. Otherwise a newly launched campaign silently
  drops out of the report, or lands on the wrong brand's invoice.
- Unused sessions expire even when the cookie is long-lived, and a monthly pull is
  thin exercise. A weekly keep-alive job that touches the session and re-saves the
  refreshed cookies, alerting only when a human must log in again (2FA), keeps the
  monthly pull working.

## Website and CRM leads

### Source of truth: the form store
GA4 form events fail in both directions and the failure is invisible in the report:
a tag over-fired about 18x on a site-search box for months; on another site GA4
showed fewer form leads than real submissions, and its consent banner later switched
to basic consent mode, which blocks GA4 until a visitor accepts; an anti-spam plugin
put every genuine B2B enquiry of one month in the spam folder.

Count from the site's own submissions:
- Gravity Forms: `<prefix>gf_entry` + `<prefix>gf_entry_meta`.
- Elementor Pro: `<prefix>e_submissions` (field values in a companion values table),
  or the plugin's REST route `/wp-json/elementor/v1/form-submissions?status=all&after=<since>&before=<until>&per_page=100`.

Query as JSON, never TSV. Form messages contain newlines, and line-based parsing
split one entry across several rows (a handful of real entries became four times as
many rows):
```sql
SELECT JSON_ARRAYAGG(JSON_OBJECT(
  'id', e.id, 'form_id', e.form_id, 'created', e.date_created, 'status', e.status,
  'fields', (SELECT JSON_OBJECTAGG(m.meta_key, m.meta_value)
             FROM <prefix>gf_entry_meta m WHERE m.entry_id = e.id)))
FROM <prefix>gf_entry e
WHERE e.date_created >= '<since>' AND e.date_created < '<day_after_until>';
```
Run it as `wp db query "<sql>" --skip-column-names --batch --raw` over SSH. Without
`--raw`, mysql doubles every backslash, so a value containing a quote turns into
`\\"` and `json.loads` fails.

### Labelling and dedupe
- **Entry status is recorded but never used to exclude a lead.** Spam-flagged is not
  the same as spam.
- Write every entry with a `category` and a `reason`: `qualified`, `duplicate`,
  `junk`, `internal` (the agency's own tests, matched by the agency's email domain in
  any field, case-insensitive), `suspected_scam` (for example the "kindly review the
  attached document" RFQ template: fluent and corporate-looking, but it never names
  a product or a quantity).
- Dedupe on **sender + 24h window**, not sender + message text: people edit a field
  between re-submits.
- Quick-capture forms (popup phone capture) often store the phone in the `name`
  field. Dedupe across forms on normalized phone digits.
- Back-test the categorizer against months a human counted by hand before trusting it.
- The generator **refuses to render** without the form snapshot rather than falling
  back to GA4 silently.
- Phone clicks stay from GA4 (the tel: click event). A click has no server-side record.

### Paid vs organic
- Split from a per-lead signal: a Google click ID present, `utm_medium = cpc`, or a
  real source/medium. Never from a manual sheet column that is blank or "unknown"
  for most rows.
- "Not paid = organic", so the two columns always sum to the total.
- Smell test: an ad account spent real money this month and "paid" leads are about
  zero. The split is wrong.

### GA4 key-event cross-check (internal only)
Clients read GA4 themselves, and GA4's Key events column sums every key event into
one number: forms **and** phone clicks. The labelling rule applies everywhere: name
the KPI by channel ("website form leads"), never "total leads". The computed
cross-check is worth building when a client actually compares your number with
their GA4. A reviewer once flagged the ambiguity and it was recorded as an accepted
exemption; that exemption is what let it reach the client.

- Pull `eventName` x (`eventCount`, `keyEvents`). Session-scoped `keyEvents` alone
  cannot tell you which events made up the total.
- Map every key event in config, and make the generator exit on an unmapped one so a
  new conversion tag cannot reopen the gap:
  ```yaml
  ga4_key_events:
    form:  [<form_event_name>]
    phone: [<phone_click_event_name>]
    other: []
  ```
- The computed GA4 total must equal what the client sees in the GA4 UI, to the unit.
  If it does not, something is stale or unmapped.
- Form store vs GA4 form events: drift under about 10% is normal (script blockers,
  consent tooling); a healthy month sits well under that. Above 10%, a webhook or the
  GA4 tag is broken. Investigate; never pick the nicer number.
- Phone: call tracking counts more than GA4 (it also counts direct dials, while GA4
  sees only clicks by on-site visitors). More than double was observed.
- The cross-check goes into the context JSON and the email review block. It never
  renders in the client document.

### Contamination from your own and vendors' traffic
Counts are only as clean as the table they come from:
- **Own smoke tests.** A daily scripted test of a chat funnel wrote real
  conversations into the store the report read. It made up most of the
  "sessions", piled activity into the overnight hours, understated the
  session-to-lead rate several times over and turned a real month-over-month dip into
  reported growth. It shipped to the client for a month before anyone noticed.
  Because the tests never reached the leads table, leads stayed correct while the
  denominator inflated. Signatures: a naming pattern in the key, one
  hour-of-day bucket with an absurd share, a flat daily count including weekends.
  Durable fix: give test traffic its own tenant key so it is out of scope by
  construction, instead of filtering a name pattern in every query.
- **Numerator and denominator from different tables.** Contamination hits one side
  and stays internally consistent. Reconcile by joining them, not by eyeballing.
- **A rate that suddenly looks great.** Check what the denominator's population is.
  On a messaging channel a lead row exists for nearly every conversation (the phone
  number comes free), so "lead" means "we got a number". Headline the qualified rate.
- **Vendor placeholder rows.** A call-tracking platform logged blocked or withheld
  callers as full `answered` rows with `anonymous` or blank caller ID, thousands of
  them against a few hundred real calls. Drop those rows at the puller; status
  alone cannot separate them.

## GA4

### Source/medium, not channel group
Use `sessionSourceMedium` as the primary lens. The default channel grouping dumps
custom UTMs (a campaign tagged `utm_medium=Facebook`) into its catch-all group,
which once got misread as "organic traffic mislabelled" while the real organic drop
was a separate, smaller change. Use `sessionDefaultChannelGroup` only as a sanity check.
Organic share = sum of rows ending in `/ organic`. If the label says "from Google",
use `google / organic` only.

### Consolidation map
Merge before sorting or rendering. One channel split across rows makes a smaller
source look like the leader (two mediums of one AI referrer, summed, were larger
than `google / organic`, which the narrative had called the top source).
```python
AI_REFERRERS = ("chatgpt.com", "perplexity.ai")
FB_HOSTS = ("facebook.com", "m.facebook.com", "l.facebook.com", "lm.facebook.com")

def normalize_source_medium(sm):
    src, _, med = (sm or "").strip().partition(" / ")
    src = src.rstrip("/").strip().lower()   # mediumless rows arrive as "source / "
    med = med.strip().lower()
    if not src or src == "(not set)":
        return None                          # drop from client tables
    if src.startswith("search.google.com"):
        return "google / organic"
    if src.startswith(AI_REFERRERS):
        return f"{src} / referral"           # ignore medium: GA4 has emitted several for one source
    if src in FB_HOSTS and med in ("referral", "", "(not set)"):
        return "facebook.com / referral"
    return f"{src} / {med}" if med else src  # lowercasing merges UTM case variants; no dangling slash
```
Also merge UTM scheme splits of one paid channel (`meta / paid_social` with
`facebook / cpc`) when config says they are the same campaigns. Keep the
consolidation list in sync with the reviewer's attribution check.

### Landing pages and titles
- `landingPagePlusQueryString` keeps query strings, so every `fbclid`, `gclid` or
  `utm_*` click becomes its own row. Aggregate by clean path and weight rates:
  ```python
  from collections import defaultdict
  agg = defaultdict(lambda: {"sessions": 0, "keyEvents": 0.0, "_eng": 0.0})
  for r in rows:
      path = r["landingPagePlusQueryString"].split("?", 1)[0] or "/"
      s = int(r["sessions"])
      a = agg[path]
      a["sessions"] += s
      a["keyEvents"] += float(r["keyEvents"])
      a["_eng"] += float(r["engagementRate"]) * s
  for a in agg.values():
      eng = a.pop("_eng")
      a["engagementRate"] = eng / a["sessions"] if a["sessions"] else 0.0
  ```
  Any numeric field you merge must be re-weighted. A merge that summed sessions but
  kept one row's engagement rate produced a row that did not reconcile.
- GA4 stores each (path, pageTitle) pair separately. A visitor with a foreign browser
  locale on an auto-translating site produces a title in an unexpected language.
  Skip `(not set)` titles and prefer the one in the report's language.
- Mojibake paths (UTF-8 bytes decoded as Latin-1) are real traffic. Repair, do not drop:
  ```python
  def fix_mojibake(s):
      try:
          return s.encode("cp1252").decode("utf-8")
      except (UnicodeEncodeError, UnicodeDecodeError):
          return s
  ```
- Strings copied from the client's own site (titles, queries, campaign names) become
  your copy in the PDF and obey your rules: replace `"\u2014"` and `"\u2013"` with a
  hyphen at the context layer.
- "Which page converts?" uses landing-page attribution. Conversions per `pagePath`
  always point at the thank-you page, where the event fires.

### Shared properties: path filter
When the client owns only a section of a larger organization's GA4 property, every
query filters to that section, configured once (`analytics.ga4.path_filter` in
config, mirrored in the channel env):
```python
from google.analytics.data_v1beta.types import Filter, FilterExpression

def begins_with(field, value):
    return FilterExpression(filter=Filter(field_name=field,
        string_filter=Filter.StringFilter(
            match_type=Filter.StringFilter.MatchType.BEGINS_WITH, value=value)))

PATH_FILTER = begins_with("pagePath", "<section_path>")
LANDING_FILTER = begins_with("landingPagePlusQueryString", "<section_path>")
```
- Reports whose only page dimension is a landing-page dimension must use the landing
  variant.
- The filter applies to events. A session that touched the section and other paths
  is counted in both clients' reports. `page_view` counts are scoped correctly;
  session-level events may include events fired elsewhere in the session. Flag it if
  conversion counts look off.
- Make the filter the default for every query; require an explicit opt-out.

### Own-traffic exclusion
An uptime prober that executes real page loads tagged with a query parameter fires
real GA4 sessions. In one month the probe was over half of all "sessions" and most
of `(direct) / (none)`. Exclude at the API, on every report call:
```python
from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import (
    DateRange, Dimension, Filter, FilterExpression, Metric, RunReportRequest)

NO_PROBE = FilterExpression(not_expression=FilterExpression(filter=Filter(
    field_name="landingPagePlusQueryString",
    string_filter=Filter.StringFilter(
        match_type=Filter.StringFilter.MatchType.CONTAINS, value="<probe_param>"))))

req = RunReportRequest(
    property="properties/<property_id>",
    date_ranges=[DateRange(start_date="<since>", end_date="<until>")],
    dimensions=[Dimension(name="sessionSourceMedium")],
    metrics=[Metric(name="sessions"), Metric(name="keyEvents")],
    dimension_filter=NO_PROBE,
)
```
Never treat the probe parameter as a tracking parameter to strip and merge. Topline
KPI queries with no dimension filter are where this leaks in.

**Measurement Protocol ghost spam** is the other case: a referrer that never loaded a
page. Signature: users = sessions = new users exactly, zero conversions, a referrer
nobody can explain (one was a company dissolved years earlier). Verify the domain
before filtering; co-op display vendors look similar but are real traffic.

### Event families
A GTM split or migration that adds new categorized events usually fires them **in
parallel** with the original. Pick one family per metric, and write the choice into
config comments and the reviewer's per-client rules. Two families summed is double
counting.

### Per-campaign attribution
GA4 `sessionGoogleAdsCampaignName` matches the Google Ads `campaign.name` exactly
(non-Latin names included) when auto-tagging is on, so GA4 can supply per-campaign
results when the account's own conversion column cannot be trusted:
```python
# report() and event_filter() stand for your own GA4 query helpers
rows = report(dimensions=["sessionGoogleAdsCampaignName"],
              metrics=["eventCount", "eventValue", "sessions"],
              filter=event_filter("<event_name>"))
by_campaign = {r["sessionGoogleAdsCampaignName"]: (int(r["eventCount"]), float(r["eventValue"]))
               for r in rows}
```
A share of sessions always lands in `(not set)` (the click ID's session ended and the
user returned another way), so the per-campaign sum undercounts. The `google / cpc`
source/medium row is the Google total. `sessionGoogleAdsAdGroupName` and
`sessionGoogleAdsKeyword` join the same way at finer grain.

### GA4 auth
- Prefer a service account added as Viewer on the property. Fall back to personal
  OAuth user credentials only when the client's admin cannot or will not add it.
  One OAuth flow with `analytics.readonly` + `webmasters.readonly` covers GA4 and
  Search Console in one credentials file.
- **gRPC 403 with user credentials.** `BetaAnalyticsDataClient.run_report` returns
  `403 PERMISSION_DENIED` while a REST `runReport` with the same token returns 200.
  Not a permissions problem: gRPC needs a quota project on user credentials.
- **401 days later.** `with_quota_project()` returns a copy without the cached token
  expiry, so `creds.valid` reads True and the stale access token is reused. Works on
  the day you mint the token, fails later. Refresh unconditionally:
  ```python
  from google.auth.transport.requests import Request
  from google.oauth2.credentials import Credentials

  creds = (Credentials.from_authorized_user_file("<user_creds.json>")
           .with_quota_project("<quota_project_id>"))   # the GCP project owning the OAuth client
  creds.refresh(Request())   # always; an `if not creds.valid` guard does not help
  client = BetaAnalyticsDataClient(credentials=creds)
  ```
- A token that looks expired may not be: the stored `expiry` is the access token's,
  not the refresh token's. Try a refresh before calling it blocked. A refresh token
  unused for about 6 months can be revoked; re-minting needs a human in a browser.
- Personal-account tokens have stricter per-user quotas than service accounts. Fine
  for monthly batches.
- Do not use `gcloud auth application-default login` for data scopes; observed in
  2026, the stable path was your own OAuth desktop client.
- Smoke-test through the real puller, not a separate test script that may not read
  the same credential settings.

### Annotations
- GA4 has annotations (UI and Admin API v1alpha:
  `POST https://analyticsadmin.googleapis.com/v1alpha/properties/<property_id>/reportingDataAnnotations`
  with `title`, `description`, `annotationDate {year, month, day}`, `color`). It needs
  the `analytics.edit` scope and Editor role; a Viewer service account gets 403.
  UI fallback: click a date in any time-series report and add the annotation.
- Search Console added chart annotations to its Performance report UI in late 2025,
  but there is no API for them, so a pipeline cannot write them.
- Always also write a local changelog per client: date, what changed, expected impact
  window, which windows to compare, links to before/after artifacts. It survives
  permission changes and retention limits.

## Search Console
- Query page-level totals for any traffic comparison:
  `{"startDate": "<since>", "endDate": "<until>", "dimensions": ["page"], "dataState": "all", "rowLimit": 1000}`.
  Query rows anonymize the long tail; summing them underestimates clicks 2 to 5x.
- A domain property (`sc-domain:`) aggregates every host and scheme. Old http and www
  URLs keep impressions for months even with correct redirects and canonicals.
  Consolidate by path:
  ```python
  from urllib.parse import urlparse
  pages = {}
  for r in rows:
      path = urlparse(r["keys"][0]).path or "/"
      p = pages.setdefault(path, {"clicks": 0, "impressions": 0, "_pos": 0.0, "url": r["keys"][0], "_best": 0})
      p["clicks"] += r["clicks"]
      p["impressions"] += r["impressions"]
      p["_pos"] += r["position"] * r["impressions"]
      if r["impressions"] > p["_best"]:
          p["url"], p["_best"] = r["keys"][0], r["impressions"]
  for p in pages.values():
      pos = p.pop("_pos")
      p["position"] = pos / p["impressions"] if p["impressions"] else None
      p.pop("_best")
  ```
  The underlying SEO fix (update external links still pointing at the old variant)
  is a recommendation to the client, not a report change.
- Joining Search Console with GA4: GA4 paths arrive URL-decoded, Search Console and
  ranking URLs percent-encoded. Strip the host, `unquote()`, drop the trailing slash,
  then join.

## Rankings (third-party tracked keywords)
- Use `rank_absolute` (the literal position on the page) for top-3/10/20 counts.
  `rank_group` is the position among organic results only and ignores AI Overviews,
  featured snippets, People Also Ask and local packs above it. On a site that ranks
  well, every keyword had `rank_group <= 3` and the report claimed all of them in the
  top 3; about half truly were. `top_3 == total_ranked` is the bug signature. Fix it
  everywhere rankings are computed (a shared database view and any per-client
  recomputation).
- A round "ranked" count (100, 1000) usually means the API row cap. Compare to the
  snapshot's total count; if capped, show percentages of tracked keywords.
- Exclude the client's brand terms (from config) from anything labelled non-brand.
- For priority keywords, Search Console average position is the truth; third-party
  data (volume, AI Overview presence, change) enriches. Sort by clicks, then
  impressions, then position. Never lead the table with the biggest decline.
- No positive rank-change badges below position 20 (still noise).
- Files under `/wp-content/uploads/` (PDF forms, brochures) rank for unrelated
  queries. Filter them from ranking tables.

## AI Overview visibility
- **Live SERP is the only ground truth.** A third-party AI Overview citation index
  lagged 3 to 6 weeks and returned a small fraction of the citations a live sweep
  found (observed 2026).
- Keyword universe per client: Search Console queries for the period + tracked ranked
  keywords + a curated list of industry head terms, minus brand terms, capped at
  about 40 to 80 keywords to keep SERP cost reasonable.
- Persist a compact record per keyword (`aio_present`, `aio_cited`,
  `aio_cited_urls`, `organic_pos`, `organic_url`), not the full SERP response.
- **Citations are partly probabilistic.** A keyword cited in one sweep can be missing
  hours later. Single-sweep wins are candidates; require at least 2 consecutive
  confirmations before calling a citation stable. Expect the count to move 20 to 30%
  month over month with no content change. Never promise a citation will persist.
- For stable wins, embed the actual evidence: the AI Overview text block that cites
  the client and the reference row (source, URL, title, snippet). A live SERP request
  with asynchronous AI Overview loading enabled (for example DataForSEO
  `POST /v3/serp/google/organic/live/advanced` with `load_async_ai_overview: true`)
  returns the overview text and a `references` array; filter it for the client's
  domain. Seeing Google's own answer quote their page persuades a client more than a
  count.
- Section layout: four tiles (cited / opportunity / no AI Overview / ranked), a wins
  list, up to 6 opportunities (position <= 10, AI Overview present, not cited) sorted
  by position, and the snapshot date. Skip the full keyword table, a framing
  paragraph about coverage, and the no-AI-Overview list. Frame it as a dated
  snapshot, separate from the report month.
- Skip the section for ads-only clients and for clients ranking only on brand or
  transactional queries.

## Data hygiene filter package
Default for every new client generator. Stateless; copy verbatim.
```python
JUNK_TOKENS = ("inurl:", "site:", '"', "%p")
JUNK_SHORT = {"yes", "no", "ok"}          # add one-word interjections in the client's language

def looks_like_junk_query(q):
    q = (q or "").strip()
    if not q or len(q) > 60 or len(q) < 3:
        return True
    if q.lower() in JUNK_SHORT or any(t in q.lower() for t in JUNK_TOKENS):
        return True
    return q.replace(".", "").replace(",", "").replace(" ", "").isdigit()

JUNK_URL_PATTERNS = ("/?s=", "?s=", "/search/", "/wp-admin/", "/thank-you", "/thanks")

def is_junk_url(u):
    return any(p in (u or "") for p in JUNK_URL_PATTERNS)

def is_file_upload_url(u):
    return "/wp-content/uploads/" in (u or "")
```
- Apply the URL filter to Search Console pages (absolute URLs) and GA4 landing pages
  (relative paths). WordPress internal-search pages (`/?s=`) get indexed and pose as
  top landing pages.
- Also drop from client tables: `(not set)`, `(data not available)`, 404 pages,
  `tel:`-prefixed paths, thank-you and post-conversion pages.
- Template: `{% if r.url %}...{% else %}·{% endif %}`. Jinja renders a missing value as
  the literal string `None`.

## Page-level cross-check (internal analysis)
Joining tracked rankings, Search Console and GA4 at page level each month finds
tracking gaps and conversion opportunities. It is an internal artifact, not a
report section.
- Compare third-party traffic estimates with Search Console at page level. Per
  keyword the ratio looks dramatic (5 to 10x) and suggests the estimates are wildly
  off; per page it was about 1.5x, normal overestimation.
- Four buckets: tracking gap (ranks and gets Search Console clicks but 0 GA4
  sessions: tag missing), conversion opportunity (50+ sessions, 0 conversions),
  non-search traffic (GA4 sessions over 3x Search Console clicks), top converters.
