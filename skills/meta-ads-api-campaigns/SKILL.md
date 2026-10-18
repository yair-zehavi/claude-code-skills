---
name: meta-ads-api-campaigns
description: >
  Build and edit Meta (Facebook and Instagram) campaigns, ad sets, creatives and ads
  through the Marketing API or Meta's official ads CLI, safely and correctly:
  everything created PAUSED, UTMs on every creative, manual placements,
  multi-advertiser ads off, currency and timezone read from the account, and every
  write read back. Covers creation order and required fields, creative swaps,
  regional advertiser verification, targeting limits, lead-action verification,
  placement size adaptation, landing-page choice, ad copy, transient vs real errors,
  the Andromeda-era structure playbook, and anomaly-based delivery monitoring.
  Use when creating or changing anything in a Meta ad account from code, boosting an
  existing post, diagnosing a Graph API error on an ad write, or building a delivery
  monitor. Not for installing a pixel or Conversions API on a website, organic
  posting, or report design.
---

# Meta ads through the API

## Why this exists

An agent holding an ads token can spend real money in one call, and the Marketing
API fails in ways that look like success: a creative swap that silently reactivates
a paused ad, a write-only field you cannot read back, a `validate_only` check that
passes a setting Ads Manager later refuses to publish, a creative whose UTMs can
never be added after creation. This skill is the build order, the house rules and
the read-back checks that make an API-built campaign match what a careful media
buyer would click together, and keep it paused until a human has looked at it.

Files in this folder:

- `api-recipes.md` - payloads for every step, from preflight reads to monitoring queries
- `gotchas.md` - symptom -> cause -> fix, with the error subcodes seen in 2026
- `playbook.md` - Andromeda-era structure, audiences, landing pages, ad copy, policy, results definition
- `adapt_placement.py` - builds a missing aspect ratio (for example 1:1 -> 9:16) from one artwork

## Non-negotiable rules

1. **Everything is created PAUSED.** Campaign, ad set and ad all carry
   `status: PAUSED` in the create call. Going live happens only when the human says
   so in the current turn; by default the human flips Campaign -> Ad set -> Ads in
   Ads Manager after reviewing previews.
2. **Confirm before every mutation**, even if the user said "go" earlier. Budget,
   bid, status, creative swap and delete calls are never allowlisted for silent
   execution; only reads are. A typo in a budget (`30000` for `3000`) is a 10x
   overspend.
3. **UTMs on every creative, at creation.** `url_tags` cannot be changed on an
   existing creative, so an ad built without them needs a new creative. Mirror the
   account's existing convention exactly (sample a top performer's `url_tags`),
   including casing. For a new account prefer an ID-based scheme with Meta's dynamic
   parameters, for example
   `utm_source=facebook&utm_medium=paid_social&utm_campaign=<campaign_key>&utm_term={{adset.id}}&utm_content={{ad.id}}`.
   Hand-typed labels go stale when ads are duplicated; IDs do not, and
   `utm_term={{adset.id}}` lets GA4 value be joined back to ad sets. Never invent a
   medium like `utm_medium=Facebook`: GA4's default channel grouping cannot read it
   as paid, so the traffic lands in Unassigned (or in Organic Social when the source
   is a social site).
4. **Manual placements, exactly six positions.** Never leave an ad set on Advantage+
   (automatic) placements. Set them in the create call, not a later patch:

   | Ads Manager label | API value |
   |---|---|
   | Facebook Feed | `facebook_positions: feed` |
   | Facebook Stories | `facebook_positions: story` |
   | Facebook Reels | `facebook_positions: facebook_reels` |
   | Instagram feed | `instagram_positions: stream` (not `feed`) |
   | Instagram Stories | `instagram_positions: story` |
   | Instagram Reels | `instagram_positions: reels` |

   Everything else stays off: Audience Network, Messenger, Threads, WhatsApp Status,
   in-stream, search results, Marketplace, right column, Explore, profile feeds,
   notifications.
5. **Multi-advertiser ads off.** Meta opts every ad in by default, which can show it
   in one unit next to other advertisers and resize or crop the creative. Send
   `multi_advertiser_ads={"ads_enabled":"OPT_OUT"}` on every ad create; the ad set
   field `is_multi_advertiser_ads_enabled=false` was also accepted in 2026. Both are
   write-only (a GET returns "nonexisting field"), so store the `success: true`
   response or the created id in the build state as the proof.
6. **Money and time come from the account, never from the client's market.** Read
   `currency` and `timezone_name` from `act_<ad_account_id>` and label every figure
   with that currency. Budgets are in the currency's minor unit (ILS 120 -> `12000`).
   Pull month-end figures only after the account's own month has closed, plus a few
   hours for Meta to settle.
7. **Read back every write.** A 200 response is not proof. See Verification.
8. **Respect client exclusions.** Some clients may not advertise certain offers (for
   example a regulated profession barred from promoting its core service). Read the
   client's notes before building, never enable or clone a legacy paused campaign
   built on an excluded offer, and route anything ambiguous to a human.
9. **Never print the access token** or any secret. Load it from the client's env
   file, reference it by name only.

## Procedure

### 0. Preflight (read-only)

1. Read the client's notes for exclusions, standing decisions and open blockers.
2. Account facts: `GET act_<ad_account_id>?fields=currency,timezone_name,account_status`.
3. Access: the token's user needs Advertiser (Manage) on the ad account, since
   Analyze reads but cannot write. The app must be Live, not in Development mode,
   which allows campaign and ad set creates but blocks creatives. For lead ads the
   business needs a page role with the Advertise task. `GET /me/accounts` shows which
   pages the token can act on.
4. Instagram identity: `GET /<page_id>?fields=instagram_business_account{id,username}`
   -> pass the id as `instagram_user_id` on creatives.
5. Copy the account's working shape. Fetch a top performer:
   `GET /<creative_id>?fields=asset_feed_spec,object_story_spec,url_tags`. Mirror its
   structure, UTM convention, CTA and identity instead of inventing a new shape.
6. Validate every ID you will reuse:
   `GET /<id>?fields=name,status,campaign{name,status,objective}`.
7. Australian targeting needs the verified business id up front (see `gotchas.md`).

### 1. Plan the build

- Objective, budget mode (ABO on the ad set or CBO on the campaign), optimization
  goal and the `promoted_object` event. The event must match what the account
  actually counts as a result (`playbook.md`, Results definition).
- Budget sanity: daily budget / number of ads / historical CPC should give at least
  about 3 clicks per ad per day. For conversion-optimized ad sets the observed floor
  is ILS 30-50 per ad per day for about 7 days (`playbook.md`). If the budget cannot
  fund the ad count, say so before building.
- Read the ad copy and the targeting together. Copy naming one region while the
  target list covers another is a common brief error; fix it with the client first.
- Choose a landing page per ad from data and the ad's promise (`playbook.md`).
- Draft copy in the house style (`playbook.md`).
- Check targeting against the limits in `gotchas.md` (removed location options,
  retired targeting classes, radius minimums).
- Decide the attribution window now and set it at create. Once an ad set has an
  `attribution_spec` it cannot be changed; changing it means a new ad set and a
  learning reset.

### 2. Assets

- The six placements fix the ratio set: square or 4:5 for feeds, 9:16 for stories and
  reels. If only one ratio was supplied, build the missing one now with
  `adapt_placement.py` (blurred cover background, sharp original centred, feathered
  seam, no black bars) and check that headline, logo and CTA stay clear of the
  placement's UI overlays. Say in the hand-off that a native export from the designer
  is still better.
- Upload images once and keep the hashes. Upload each video once, poll until
  `status.video_status == "ready"`, and cache `video_id` by file path so reruns never
  re-upload.
- Give every video creative a thumbnail (`image_hash` of an extracted frame, or the
  video's preferred thumbnail `image_url`).

### 3. Build: one script, a state file, everything paused

Order: **campaign -> ad set -> media -> creative -> ad.** Write one script that
creates the whole sequence, persists each new id to a JSON state file immediately
after its create (never batched), and skips any step whose id already exists. A
mid-sequence failure then resumes instead of duplicating. Required fields that
trip first-time builds (full payloads in `api-recipes.md`):

- **Campaign:** `objective` (`OUTCOME_*`), `status: PAUSED`, `special_ad_categories`
  (an empty list when none applies; deciding a category is a human call). ABO:
  `is_adset_budget_sharing_enabled` set explicitly. CBO: `daily_budget` and
  `bid_strategy` on the campaign.
- **Ad set:** `status: PAUSED`, `billing_event`, `optimization_goal`,
  `promoted_object`, an explicit `bid_strategy` on ABO (plus `bid_amount` on
  bid-cap accounts), the six placements inside `targeting`,
  `targeting_automation.advantage_audience` set to 0 or 1 (with 1, `age_max` must be
  65), no `location_types` (Meta fills in the one valid value), the attribution
  window, and for Australian targeting the regional advertiser fields.
- **Creative:** `url_tags`, `object_story_spec` with `page_id` and
  `instagram_user_id`. Single image: `link_data` with `image_hash`. Video:
  `video_data` with the link inside `call_to_action.value.link`. Several ratios in
  one ad: `asset_feed_spec` with `asset_customization_rules`. Omit
  `degrees_of_freedom_spec.creative_features_spec.standard_enhancements`.
- **Ad:** `adset_id`, `creative={"creative_id": ...}`, `status: PAUSED`,
  `multi_advertiser_ads` opt-out.

If a run fails partway, delete the orphan creatives and ads it left before
reporting. Then read everything back (Verification) and hand off: the Ads Manager
link, what the human should check in the previews per placement, anything the API
or CLI could not set, and the activation order.

### Editing live objects

- **Copy, link or UTM change = new creative + swap.** Only `name`, `status` and
  `adlabels` are mutable on a creative. Create a new creative (reusing the same
  `video_id` or image hash) and `POST /<ad_id>` with `creative={"creative_id": ...}`.
  The ad id stays and nothing re-uploads.
- **A creative swap resets the ad to ACTIVE**, even when the ad was paused and the
  call did not mention status. Pass the ad's intended `status` in the same call,
  then GET it back; if it reads ACTIVE when it should not, POST `status=PAUSED` as
  its own call and read again.
- **Before "fixing" a status that looks wrong, check `updated_time`.** A human may
  have launched those ads minutes earlier on purpose.
- **Edits restart learning:** a budget change over about 20%, any bid change, a
  creative swap, a targeting change. Batch changes instead of making them one at a
  time, and do not touch budget during a test window.
- **Launching new ads does not mean pausing old ones.** Keep proven spenders running
  and pause only non-spenders (`playbook.md`).
- **Boosting an existing post:** resolve the client's share link to
  `<page_id>_<post_id>` (`api-recipes.md`) and build the creative on
  `object_story_id`, which keeps the post's social proof. Do not rebuild a live
  boosted creative just to change one placement: that drops the social proof and
  forces a new review. Hand the human the resized file for the placement panel
  instead.

## Verification

Prove each object with reads, never with "the call returned 200":

| Check | Read | Pass condition |
|---|---|---|
| Status | `GET /<id>?fields=status,effective_status` on campaign, ad set, every ad | `status` is `PAUSED` everywhere; no ad in `WITH_ISSUES` |
| Placements | `GET /<adset_id>?fields=targeting` | exactly the six positions, `device_platforms` set, `location_types` is `["home","recent"]` |
| Budget and bid | `GET /<adset_id>?fields=daily_budget,lifetime_budget,bid_strategy,optimization_goal,promoted_object,attribution_spec` | budget in minor units matches the plan; event matches the account's result action |
| UTMs | `GET /<creative_id>?fields=url_tags,object_story_spec,asset_feed_spec` | `url_tags` present, convention matches the account, landing URL is the canonical one (no redirect hop) |
| Thumbnail | `GET /<creative_id>?fields=thumbnail_url`, then fetch the URL | HTTP 200 |
| Issues | `GET /<ad_id>?fields=effective_status,issues_info,updated_time` | `issues_info` empty |
| Multi-advertiser | build state file | a recorded `success: true` or created id per ad |
| No orphans | list the account's creatives and ads by name | no creative from this run without an ad |

After the human activates: `effective_status` reads `ACTIVE` with empty
`issues_info`, impressions are above zero the next day for every ad, the new UTM
values appear in GA4 under `sessionSourceMedium`, and lead or purchase counts agree
with the CRM or shop.

## Monitoring: flag anomalies, not states

- **Spend is the ground truth of delivery.** Account status stays ACTIVE, and
  campaigns can read ACTIVE, while nothing delivers.
- **Compare two adjacent 7-day windows of daily spend** (`date_preset=last_14d`,
  `time_increment=1`). Flag "went dark" only when the prior week spent and this week
  did not. Both weeks zero is baseline (an account stopped long ago), and alerting on
  it is noise. Meta omits zero-spend days from daily rows, so sum what comes back
  instead of counting trailing zeros. A stop is flagged once a full week has passed
  without spend, stays flagged for about a week, then ages out without a state file
  or an ignore list.
- **Flag `WITH_ISSUES` campaigns separately** as a delivery issue, independent of
  spend.
- **Isolate probe failures.** A timed-out side query records its own error field
  and never marks a healthy account as broken.
- **Every account failing at once is a network blip**, not a credential problem. Run
  the collector directly before debugging anything.
- One metric being high on its own is not an alert. Diagnose a CTR drop with the
  fatigue-versus-algorithm signatures in `playbook.md` before blaming a creative.
- Any script that moves money is deterministic, dry-run by default, has a kill switch
  inside the script, and announces every money change in a channel a human reads,
  never only in a log file.

## Top gotchas

Full list with subcodes in `gotchas.md`.

| Symptom | Cause | Fix |
|---|---|---|
| A paused ad is spending after a creative change | the swap reset it to ACTIVE | send `status` with the swap, read back, re-pause separately if needed |
| Every write to an ad set fails "Advertiser is missing" (subcode 3858634) | ad set targets Australia without a verified advertiser | set `regional_regulated_categories` and `regional_regulation_identities` to the verified business id |
| Ads Manager refuses to publish (#1870194) although the API accepted it | `location_types` set to one removed value | remove `location_types`; Meta restores `["home","recent"]` |
| `url_tags` PATCH fails (subcode 1815573) | creative fields are immutable | new creative, swap it onto the ad |
| Fresh video ads show "Image Not Found" (2446386) | usually a transient review glitch | re-POST the ad with its own `creative_id` to force re-review |
| Pausing a lead ad fails with "Missing Lead Form" (3390001) | no page access, so every ad write re-validates the form | client grants the business the Advertise task on the page |
| A script logs only "HTTP Error 400: Bad Request" | the reason is in the response body | log `error_user_title`, `error_user_msg`, `code`, `error_subcode` |
| Spend reported with the wrong currency symbol | currency inferred from market | read `currency` from the account |

## When to stop and ask a human

- Before any call that activates, changes budget or bid, swaps a creative on a live
  ad, or deletes anything.
- Before pausing a proven spender ("winner"), even if a new batch is launching.
- When the client asks to exclude people by religion, ethnicity or another
  protected trait. Meta has no such targeting; do not build a proxy (geo, interest)
  on your own, because for job, housing or credit offers that can be unlawful.
- When the offer might belong in a special ad category, or touches a client
  exclusion.
- When ad copy and targeting disagree, or two pages of the site state different
  prices for what the ad promises.
- When the fix needs an admin in a UI: accepting Custom Audience terms, business
  verification, page or Instagram access, switching the app to Live.
- When an ad reads ACTIVE unexpectedly and `updated_time` is recent.
- When the budget cannot fund the planned number of ads at the per-ad floor.
