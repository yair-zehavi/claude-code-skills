---
name: marketing-report-accuracy
description: >
  Produce a monthly multichannel client performance report (Google Ads, Meta,
  LinkedIn, GA4, Search Console, website and CRM leads) in which every number
  holds up when the client checks it against their own dashboards. Runs
  pull -> aggregate -> generate -> independent reviewer subagent -> draft email
  for human review, and encodes the accuracy rules (month closure per ad-account
  timezone, data settling, lead source of truth, attribution consolidation, URL
  normalization, currency) and the presentation rules (no internal notes, no tool
  jargon, numbers over narrative, RTL number alignment). Use when building,
  running, reviewing or debugging a recurring client performance report, or when
  a client disputes a reported number. Not for ad-hoc "how is it doing" updates
  (those are plain numbers tables, see presentation-rules.md), live dashboards,
  or campaign optimization decisions.
---

# Marketing report accuracy: every number defensible

## Why this exists
A late report costs a day. A wrong number in front of a client costs trust that
takes months to rebuild, and clients do check: they open GA4 and Ads Manager
themselves. A report pipeline fails safe on loud errors (a puller crashes, a token
expires) and fails dangerous on quiet lies: a wrong currency label, double-counted
leads, a stale database, a pull taken before the month closed. Quiet lies are
internally consistent. The snapshot, the context and the rendered PDF all agree, so
a review that only compares them with each other passes them. This skill is the set
of checks that catch them.

Reference files in this folder:
- [data-rules.md](data-rules.md): per-source accuracy rules, queries, filters and auth pitfalls.
- [reviewer-checklist.md](reviewer-checklist.md): the reviewer subagent's protocol and full check list.
- [presentation-rules.md](presentation-rules.md): what may appear in the client document, layout, RTL, the delivery email.

## Non-negotiable rules
1. **One source of truth per metric**, written in the client's config and verified
   against the platform (table below). Never switch sources silently when one is
   missing: the generator refuses to render instead.
2. **Never pull before the month is closed and settled.** Each ad account closes its
   month at midnight in its own reporting timezone. GA4 can take about 48h to finish
   processing a day and Search Console lags about 3 days. A pull on the 1st
   understates every Google number and nothing errors.
3. **Verify external ground truth every cycle**: ad-account currency and the lead
   action, read from the platform API. Never trust a stored label, never infer
   currency from market or language.
4. **Re-pull -> re-aggregate -> re-generate, always all three.** A database-backed
   report renders from the database, not from the fresh snapshot.
5. **A label states exactly what was counted.** A forms-only count is "website form
   leads", never "total leads": the client's GA4 Key events column adds phone clicks
   to forms, and a gap against a "total" reads as an error.
6. **Rule 0: the client document holds finished numbers and plain conclusions only.**
   No methodology, FX math, cross-system comparisons, provisional wording,
   spam-filtering talk, or tool names. A caveat that matters goes in the review
   block of the delivery email or the client's task log, never the PDF.
7. **No hand-written report sections.** Every narrative sentence is generated from
   current-period data.
8. **Review is independent and layered**: a fresh reviewer subagent with the same
   data the generator had, plus a grep for tool names, plus a visual check of every
   PDF page. "The reviewer said pass" is one signal, not clearance.
9. **Drafts only.** The pipeline never sends. A human reviews and sends.

### Sources of truth
| Metric | Source of truth | Not this |
|---|---|---|
| Ad spend | Ad platform, in the account's own currency | A config label, a fixed-rate conversion |
| Platform leads | The one action type that the Ads Manager Results column shows | A sum of overlapping action types |
| Website form leads | The site's own form store (database) | GA4 form events |
| Phone clicks | GA4 tel: click event (no server-side record exists) | n/a |
| Traffic mix | GA4 `sessionSourceMedium` | `sessionDefaultChannelGroup` |
| Organic search clicks | Search Console page-level totals | Sum of query rows (long tail is anonymized) |
| Ranking positions | `rank_absolute` (what the searcher sees) | `rank_group` (organic-only position) |
| AI Overview citations | Live SERP checks, confirmed on 2+ sweeps | A third-party citation index |

## Procedure

### Stage 0: Preflight (before any pull)
1. **Roster.** For a batch run, rebuild the client list from what exists on disk
   (every client folder with a report generator) and diff it against the hardcoded
   list. A client onboarded after the list was written silently misses a whole cycle.
2. **Read the client's task log** for known blockers (dormant ad account, analytics
   not connected, pending re-auth). A known blocker changes what is sendable.
3. **Currency.** Read it from each platform (Meta ad account `currency` field, Google
   Ads `customer.currency_code`, LinkedIn invoice currency) and compare with the
   snapshot label and any FX logic. See [data-rules.md](data-rules.md#currency).
4. **Lead action.** Confirm the configured Meta action type is the one in the Ads
   Manager Results column for each active campaign. Rules about data semantics must
   cite how and when they were verified.
5. **Config vs env drift.** Account, property and customer IDs in config must mirror
   the channel env files. Pullers read env, humans trust config. Google Ads customer
   IDs are digits only; the API rejects the dashed UI form.
6. **Month closure.** For each ad account, compute when its month closed in your
   timezone and add a few hours for the platform to settle. For GA4 and Search
   Console, wait at least 3 days after period end. A guard in the pull script exits
   non-zero under 3 days and prints the earliest safe date; an explicit override
   exists for a deliberate mid-month look.
7. **Test before declaring anything blocked.** An "expired" OAuth token may refresh
   fine; a "not wired" property may already be readable by the service account.
   One API call settles it.

### Stage 1: Pull
- One puller per channel writes a raw JSON snapshot per period, stamped with the
  pull time. Shared pullers live in one place; a per-client copy of a shared puller
  goes stale and ignores later fixes (one ignored the per-client lead-action
  override and undercounted leads).
- Apply scoping at pull time, not in the template: path filters for a client that
  owns only a section of a shared GA4 property, exclusion of your own uptime-probe
  and smoke-test traffic, explicit campaign-ID lists for a shared ad account.
- Website leads come from the form store over SSH and WP-CLI, queried as JSON
  (never TSV). Spam-flagged entries are kept and labelled, not dropped.
- A channel with zero spend and zero campaigns is honest data (render as inactive).
  An empty log plus a missing snapshot is a failure. Tell them apart before shipping.
- Queries, filters and auth details per source: [data-rules.md](data-rules.md).

### Stage 2: Aggregate and normalize
- Run the aggregator inside the pull script, so a re-pull always rebuilds the
  database. When fixing a metric, trace the whole path: puller -> snapshot ->
  aggregate -> database view -> generator -> template. Fix at the source and check
  nothing downstream re-derives the value differently.
- Normalize before sorting or rendering: strip query strings from landing pages,
  consolidate homepage host and scheme variants from Search Console, merge
  source/medium variants of one channel, drop junk queries and junk URLs, repair
  mojibake, replace dashes in client-sourced strings.
- Leads: dedupe on sender plus a 24h window, label each entry (qualified /
  duplicate / junk / internal / suspected scam) with a reason, and split paid vs
  organic from a per-lead signal (click ID or `utm_medium=cpc`), with "not paid =
  organic" so the columns always sum to the total.

### Stage 3: Generate
- Data-driven sections only, gated by data-presence flags computed from the data
  (`has_meta`, `has_gads`, `has_site_health`), never by market or client name.
- Every narrative sentence uses the dynamic period label and current-period values.
  No hardcoded month names, no prior-month figures in prose.
- Money renders in each account's own currency. An account in a second currency gets
  its own KPI row and stays out of the main-currency spend total; its leads still
  count toward the lead total.
- Output files follow one naming pattern: `{ClientName-kebab}_{YYYY-MM}.{html,pdf,context.json}`.
- Layout, labels, RTL and print CSS: [presentation-rules.md](presentation-rules.md).

### Stage 4: Reviewer pass (independent QA)
1. **Spawn a fresh subagent** (Agent tool, general-purpose type; a mid-size model
   such as Sonnet is enough) that did not build the report. Give it the rendered
   HTML, the context JSON, the previous month's context (for month-over-month), the
   client config, the raw snapshots directory, the global checklist, and the
   client's exemption file. Give it the full snapshots, not a trimmed view: a
   checker given less data than the generator falsely accuses it of inventing
   figures that were simply outside its window.
2. **Rule 0 runs first.** Any internal note in the client document is a block.
3. **Then the data checks**: totals vs breakdowns, component sums, plausibility,
   currency, attribution consolidation, freshness, stale narrative. Full list:
   [reviewer-checklist.md](reviewer-checklist.md).
4. **Then the visual pass**: render every PDF page to an image and look at it. Page
   breaks exist only in the PDF, so reviewing HTML can never catch an orphaned
   heading, a split KPI row or a column clipped at the page edge.
5. **Severity**: `block` stops the draft; `warn` drafts with the note in the review
   block; `note` is informational. Verdict: `pass` / `pass-with-notes` / `block`.
6. **Fix at the source** (template or context builder, never the rendered file),
   regenerate, and spawn a new reviewer. Repeat until clean.
7. **Exemptions are institutional memory and can be wrong.** An intentional quirk
   must be recorded as a per-client exemption or the reviewer re-flags it forever.
   But an exemption that hides a client-visible ambiguity is how a number that
   looks wrong reaches a client. Every exemption about data semantics cites how it
   was verified and when.
8. **The orchestrator runs its own grep** for tool names over the rendered HTML,
   including spelled-out product names, after stripping `<style>` blocks and comments.

### Stage 5: Deliver
- Create an email draft addressed to the client's report recipients, PDF attached,
  body short and in the account owner's voice, greeting taken verbatim from config.
- At the bottom: a `----- review (delete before sending) -----` block with links to
  the rendered HTML and PDF, the reviewer's warnings, and any internal
  cross-check (for example how the form-lead count relates to GA4 Key events).
  The human reads it, deletes it, sends.
- Re-runs update the existing draft in place instead of creating a duplicate.
- In a batch, every client run ends with one machine-readable line:
  `RESULT: <client> | clean|notes|held|failed | <reason>`. A wall-clock watchdog
  marks a runaway reviewer `held` and the batch continues. Verify a `failed` line
  against the output folder and the drafts before acting on it: the summary itself
  can be a quiet lie (a bolded marker missed by a strict parser, a timeout misread
  as a failure).

## Verification: cross-checks
Run these before drafting. Numbers below are illustrative.

| Compare | Against | Pass condition / example |
|---|---|---|
| Each exec-summary KPI | Sum of its breakdown in the detail section | Equal. Leads tile 48 = Meta 30 + Google 12 + organic forms 6 |
| Component columns (paid + organic) | Total column, every row and the totals row | Sum closes. 22 + 9 against a total of 40 means a missing "unattributed" column |
| One KPI in several places | Itself | Identical everywhere (no 58% in the summary and 55% in the GA4 section) |
| Every `+X%` badge | `(current - previous) / previous` | Within rounding |
| Database-rendered KPI | Same field in the raw snapshot | Equal; a mismatch means the aggregate did not run |
| Ad spend in the report | Platform UI for the same dates, in account currency | Equal to the unit |
| Meta leads | Ads Manager Results column | Equal; `lead`, `lead_grouped` and the pixel lead are the same conversions, never summed |
| Form-store lead count | GA4 form key events, same period | Drift under about 10% is normal (script blockers, consent banners); above that a tag or webhook is broken |
| Report lead KPI | Client's GA4 Key events total | Internal only. Key events 175 = 118 form events + 57 phone clicks; the report's 115 is forms only. The computed GA4 total must match the GA4 UI exactly |
| GA4 tel: clicks | Call-tracking platform calls | Call tracking is normally larger (it also counts direct dials); GA4 higher is worth investigating |
| Per-campaign GA4 attribution sum | GA4 `google / cpc` total | Per-campaign sum is lower (rows lost to `(not set)`); `google / cpc` is the Google total |
| Shipped month's snapshot | A fresh re-pull of the same month | Equal; a difference means the pull was too early |
| Every count | Same count last month | A move over about 3x is a data-integrity question before it is a performance story |
| Top-3 ranking count | Total ranked keywords | `top_3 == total_ranked` is a bug signature (`rank_group`), not a win |
| Narrative numbers | Current-period KPIs | Same values; prior-month figures in prose are a stale-narrative block |
| Organic share labelled "from Google" | `google / organic` only | Identical in summary and GA4 section, Google-only |

## Gotchas
Format: symptom -> cause -> fix.

- Every Google number a few percent low, no errors -> pulled within 3 days of month
  end -> pull guard that refuses under 3 days; re-pull and diff to audit past months.
- Last day of the month short on one ad account -> that account reports in another
  timezone (US Pacific, New York, or UTC as observed on LinkedIn in 2026) and its
  month had not closed -> pull after the account's month closes plus a few hours;
  re-pull and fix every artifact that used the early figure together.
- Spend off by a constant factor -> currency label wrong (an account assumed local
  currency was in USD and the generator divided by an FX rate) -> read currency from
  the API each cycle; no fixed-rate conversion anywhere.
- Meta leads differ from what Ads Manager shows -> overlapping action types summed, or
  the wrong single action configured -> one configured action type, exact match,
  verified against the Results column.
- Client says "your 115 leads, my GA4 says 175" -> GA4 Key events include phone
  clicks -> relabel the KPI by channel; keep the explanation in the email review block.
- Fix applied, report unchanged -> database not rebuilt -> re-pull, re-aggregate,
  re-generate as one step.
- Same landing page 5-10 times in the top-pages table -> `fbclid`/`gclid`/`utm_*` kept
  in `landingPagePlusQueryString` -> split on `?`, aggregate by path, weight
  engagement rate by sessions.
- Homepage twice in Search Console pages -> domain property aggregates http/https and
  www variants -> group by URL path, sum clicks and impressions, impression-weight position.
- Big unexplained jump in the GA4 catch-all channel group -> a campaign used a custom
  `utm_medium` -> read `sessionSourceMedium`, not the default channel grouping.
- `(direct) / (none)` and sessions inflated, a landing page row whose numbers do not
  reconcile -> your own uptime prober's tagged page loads fire real GA4 sessions,
  and a cleaner stripped the probe parameter and merged them into the real row ->
  exclude probe traffic with a GA4 dimension filter at pull time; never strip-and-merge.
- Conversion rate suddenly excellent -> the denominator changed meaning (a chat channel
  creates a lead row for nearly every conversation) -> report the qualified rate.
- An order-of-magnitude jump in inbound calls -> a vendor's placeholder rows (blocked
  or withheld caller ID logged as answered calls) -> drop rows with anonymous or
  blank caller ID at the puller.
- Unknown referral source with users = sessions = new users and zero conversions ->
  Measurement Protocol ghost spam -> verify the domain is dead, then add it to a
  fleet-wide exclusion list. A low-quality but real ad-tech referrer looks similar
  and must stay.
- Every tracked keyword "in the top 3" -> `rank_group` ignores AI Overviews, snippets and
  local packs above the result -> use `rank_absolute` in every place rankings are
  computed (shared view and per-client generator).
- GA4 gRPC call returns 403 with user OAuth credentials while REST returns 200 ->
  missing quota project; days later 401 -> cached token expiry dropped -> see
  [data-rules.md](data-rules.md#ga4-auth).
- A literal `None` in client prose -> a nullable field interpolated into narrative ->
  guard with `{% if x is not none %}`; reviewer greps for it.
- A tool name shipped in a section heading for months -> Rule 0 review focused on note
  boxes and footnotes; headings, KPI sub-labels and table captions were the blind
  spot, and the grep only had abbreviations -> grep both abbreviations and spelled-out
  names over headings too.
- Hebrew KPI numbers pushed to the left edge of their tiles -> `unicode-bidi:
  plaintext` on the KPI value -> remove it from `.kpi .value`.

## When to stop and ask a human
- The platform's currency or lead action disagrees with config and the right
  definition is not obvious (for example the account's real "lead" is a messaging
  conversation or a custom pixel event).
- A cross-check gap is above threshold (form store vs GA4 over about 10%, any
  count moving over 3x month-over-month) and the cause is not found in the data.
- A GA4 or LinkedIn session needs browser consent or 2FA to re-authorize. Hold that
  client's draft: a report showing 0 sessions is not sendable.
- A client disputes a number. Answer them through the account owner; do not add an
  explanatory box to the report.
- A new GA4 key event appears that is not mapped to form, phone or other in config.
- A shipped report turns out to be wrong. The human decides whether and how to send
  a correction.
- An exemption would hide something the client can see.
- Anything that would need a hand-written section, an FX conversion, or a claim that
  an AI Overview citation is stable after a single sweep.
