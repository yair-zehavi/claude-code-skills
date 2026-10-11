# Reviewer checklist

The independent QA pass for a rendered monthly report. The agent that generated the
report always passes its own review, so the reviewer is a fresh subagent that knows
nothing about how the report was built. Each check below exists because its failure
reached, or nearly reached, a client.

## Running the reviewer

**Inputs** (give all of them, in full):
- the rendered HTML and PDF
- the context JSON the template rendered from
- the previous month's context JSON (for month-over-month checks)
- the client config
- the raw snapshots directory for the period
- this checklist and [data-rules.md](data-rules.md)
- the client's exemption file (per-client rules), if any

Give the reviewer the same data window the generator had. A checker given a trimmed
view (a fraction of the snapshot) accused the generator of inventing figures that
were simply outside what it could see. With the full snapshot, the same checker
caught real errors: an invented search query and a miscounted zero-click tally.

**Severity:**
- `block`: do not draft the email. Fix and re-run.
- `warn`: real issue, acceptable to draft; the note goes into the email review block.
- `note`: informational; does not gate.

**Verdict:** `pass` / `pass-with-notes` / `block`.

**Loop:** fix every block (and every clear warn) at its source: the template or the
context builder, never the rendered file. Regenerate. Spawn a **new** reviewer.
Repeat until clean. Wrap the reviewer in a wall-clock watchdog; a reviewer that loops
on an unfixable finding is marked `held` and the batch moves on. (One looped for half
an hour on an intentional gap that had no exemption yet.)

**Exemption ledger.** Intentional quirks (bot-filtered sessions, split months, a
chosen event definition) are recorded as per-client exemptions, or the reviewer
re-flags them forever. Two constraints:
- An exemption about data semantics (currency, lead action, event family) cites how
  it was verified (API call, Ads Manager screenshot) and when. An unverified
  exemption poisons every future review: a rule saying "convert this account's spend
  at a fixed rate" encoded a wrong currency assumption for months.
- An exemption may never hide something the client can see. An accepted exemption
  for an ambiguous "total leads" label is how that label reached a client who then
  found a different number in GA4.

## Reviewer prompt (paste into the subagent)

```
You are reviewing a client's monthly performance report before it is emailed. You did
not build it. Your job is to find what is wrong, not to approve it.

Inputs: <paths to HTML, PDF, context JSON, previous context JSON, config, snapshots
dir, checklist, data rules, client exemption file>

1. Run Rule 0 first and report it separately.
2. Run every check A to V in the checklist. For each data claim, recompute it from the
   raw snapshots; do not trust the context JSON.
3. Render every PDF page to PNG and look at each one (check Q).
4. Load the client exemption file; exemptions suppress findings, extra rules add them.

Output ONLY a JSON array, then a verdict line:
[{"check": "A", "severity": "block|warn|note", "location": "section / element",
  "issue": "...", "why_it_matters": "...", "evidence": "expected X, found Y, from <file>",
  "source_to_fix": "template or context builder, with the line if known"}]
VERDICT: pass | pass-with-notes | block
Then one line listing the checks that ran. If you could not render the PDF, say
"pagination unverified" in the verdict line.
```

## The checks

### 0. Rule 0: no internal notes in the client document (block, run first)
The report contains finished numbers and plain-language conclusions only. Block any
of these, visible anywhere (footnote, note box, parenthetical, KPI sub-label, table
caption, section heading, narrative sentence):
- **Methodology**: "counted from the `lead` event", "based on GA4 form submissions",
  "leads counted from the master sheet", "data source: forms plugin + consent mode",
  "last-click attribution", "conversions not tied to a landing page".
- **Currency and FX**: "reported in USD, converted at rate X", "billed in dollars,
  your card charges in local currency", "in account currency, not added to the total".
- **Provisional wording**: "pending verification", "will be updated after approval",
  "preliminary", "subject to change".
- **Cross-system discrepancies**: "GA4 shows X but the pixel shows Y", "the sheet has
  120, the form store 122", "two event families, do not sum", a "how our numbers
  reconcile with Google Analytics" box.
- **Spam-filtering theatre**: "X real inquiries, spam excluded", "after filtering".
- **Tool and debug references**: `DataForSEO`, `DFS`, `ETV`, raw event or API field
  names, campaign IDs, "webhook", automation-platform scenario names, pixel event names.
- **Anything addressed to the agency**: "TODO", "verify before sending", "internal",
  a caveat hedging our own numbers.

Name the exact source file and line. The fix is deletion at the source, then
re-render. Do not soften the wording. A caveat that genuinely matters moves to the
email review block or the client's task log.

### A. Numbers add up (block)
- Every cover and exec-summary KPI equals the sum of its breakdown in the detail sections.
- One KPI has one value wherever it appears.
- Every `+X%` / `-Y%` equals `(current - previous) / previous` within rounding.
- **Component columns close to the total** in every row and the totals row. If
  organic + paid does not equal total, a column is missing (add "unattributed") or
  the total is wrong.

### B. Freshness and external ground truth (block)
- Database-backed report: cross-check one KPI (for example Meta spend and leads)
  against the raw snapshot. A mismatch means the aggregate did not run.
- Pull timestamp is at least 3 days after period end for GA4 and Search Console data,
  and after each ad account's month closed in its own timezone.
- Currency and lead action were verified against the platform API this cycle.

### C. Tool and agency jargon (block when in headings, labels or prose)
- Banned in client-visible copy: `DataForSEO`, `DFS`, `ETV`, `GSC`, `GA4`,
  `SERP` used casually, raw field names (`sessionSourceMedium`,
  `landingPagePlusQueryString`), UTM-cased channel names (`FACEBOOK / CPC`).
- Spelled-out measurement tools count too: `Google Analytics`, `Search Console`,
  `Google Tag Manager`, `Looker`. A tool name in a section heading shipped for two
  months because reviews focused on note boxes.
- A vendor name in a KPI label parenthetical ("inbound calls (vendor)") is removed,
  not reworded.
- Channels the client buys stay (`Google Ads`, `Meta`). Measurement tools become the
  channel they measure ("Search Console by market" -> "organic search on Google by market").

### D. Win-section integrity (warn)
- A "top campaign / ad / keyword / page this month" is not paused and has real
  conversion outcomes. Top spend is not top performer.
- The sample supports the claim. A 2-session page with a 200% conversion rate is not a win.

### E. Negative framing in highlights (warn)
- A drop presented without context does not belong in cover tiles or spotlight boxes
  ("traffic down 25%" as a headline, "0 leads" prominently). It goes in the
  recommendations.

### F. Language quality (block for dashes, warn otherwise)
- Narrative in the config language. English data labels are fine.
- Zero em-dashes and en-dashes anywhere visible, including `<title>` and strings
  copied from the client's own site.
- No translationese or AI filler. Hebrew narrative goes through a Hebrew
  humanizer pass.

### G. Data noise (warn)
Not in client-visible tables: `(not set)`, `(data not available)`, thank-you pages,
404 pages, `tel:` paths, internal search and admin paths, URLs still carrying
`fbclid` / `gclid` / `utm_*` (must be stripped and merged with the clean path).

### H. Plausibility (warn, block where noted)
- CTR over 50% on 500+ impressions (exact-match brand at low volume: note only).
- Conversion rate over 100%.
- Spend 0 on an active campaign; clicks with 0 impressions.
- Any count moving more than about 3x month over month: a data-integrity question
  first. An inbound-call count jumped by an order of magnitude because of vendor
  placeholder rows; only the ratio to the prior month gave it away.
- `top_3 == total_ranked`: block (`rank_group` bug).
- A conversion rate that jumped to excellent: check the denominator's population.

### I. Period and stale narrative (block)
- The cover month propagates to every section (point-in-time ranking snapshots excepted).
- No hardcoded month names in prose; no prior-month figures. Cross-check one or two
  narrative numbers against this period's KPIs.

### J. Brand and competitors (warn)
- Competitor names from config do not appear in narrative (tables are fine).
- Brand terms do not appear in tables labelled non-brand.

### K. Currency (block)
- Spend renders in the account's currency, verified this cycle.
- One symbol per currency. The same symbol for two currencies is a block.
- No FX conversion at a fixed rate; a second currency has its own row and stays out
  of the main total.

### L. Keyword visibility counts (warn)
- A round ranked count (100, 1000) likely hit an API row cap. Compare with the
  snapshot total; show percentages of tracked keywords if capped.

### M. Attribution consolidation (warn)
One channel split across rows must be merged: UTM case variants, UTM scheme splits of
one paid channel, all `*.facebook.com / referral` hosts, one AI referrer under several
mediums, `search.google.com / referral` (that is `google / organic`). Mediumless rows
must not render with a dangling slash (`source /`).

### N. RTL (Hebrew and other RTL reports) (warn)
- No `unicode-bidi: plaintext` on `.kpi .value`: it pushes the number to the wrong
  edge of the tile.
- Do not flag the same rule on numeric table cells (`td.num`): there it is
  deliberate, for digit-column alignment. Confirm on the rendered page that numbers
  sit where the layout expects them.

### O. Rank badges and table order (warn)
- No positive rank-change badges for positions worse than 20.
- Priority keyword tables sort by clicks and impressions, not by rank change; they do
  not open with the biggest decline.

### P. Section order and empty sections (warn)
- Sections with data come first; empty channel sections go to the bottom.
- An all-zero section is suppressed, not rendered as a wall of zeros.
- Section visibility is driven by data-presence flags, not market.

### Q. Print pagination (block, visual)
Render every page and look at it:
```bash
pdftoppm -png -r 80 <Client>_<YYYY-MM>.pdf <scratch_dir>/page
```
At every page boundary: no heading orphaned at a page bottom, no KPI row split
(labels on one page, values on the next), no table header separated from its rows,
no notes box split mid-sentence. At each page's left and right edges: no clipped
columns (two wide tables side by side overflow A4). If the PDF cannot be rendered
(tool missing on that machine), the verdict says "pagination unverified"; it does not
silently pass.

### R. Leads (block)
- The lead KPI label says what was counted. A forms-only count is not labelled a total.
- Paid vs organic comes from a per-lead signal; paid + organic = total in every row.
- An ad account with real spend and about zero paid leads means the split is wrong.

### S. Organic share (block when mislabelled)
- Computed once, identical in the summary and the traffic section.
- Labelled "from Google" means `google / organic` only, not all search engines.

### T. Ad campaign objective framing (warn)
- Engagement, awareness or traffic campaigns are not headlined "0 leads"; show reach,
  clicks, spend. Lead and sales campaigns keep leads and cost per lead.

### U. Logos and assets (warn)
- Agency and client logos render (no broken image, no text fallback when a logo file
  exists). Referenced asset filenames exist.

### V. Rendering bugs (block)
- No literal `None`, `nan`, `undefined` or `{{` in visible text.
- No mojibake (`×©` style sequences in a Hebrew path or title): repair it upstream.

## Orchestrator checks the reviewer does not replace
The reviewer saying "Rule 0: PASS" is one signal. Two spelled-out tool names sat in a
heading and a methodology sentence for months while a reviewer told to run Rule 0
first reported a pass. Before drafting, the orchestrator also:

1. Greps the visible text:
   ```bash
   perl -0777 -pe 's/<style.*?<\/style>//gs; s/<script.*?<\/script>//gs; s/<!--.*?-->//gs' \
     <Client>_<YYYY-MM>.html \
   | grep -nE 'GA4|GSC|DFS|DataForSEO|ETV|Pixel|Google Analytics|Search Console|Google Tag Manager|Looker|utm_|fbclid|\(not set\)|None'
   perl -CSD -ne 'print "$.: $_" if /[\x{2013}\x{2014}]/' <Client>_<YYYY-MM>.html
   ```
   Stripping styles and comments first keeps the hits real. The dash check uses perl
   because macOS grep has no `-P`, and a failed grep prints nothing, which reads as clean.
2. Looks at the rendered PDF pages itself for anything the reviewer marked unverified.
3. Emits the run's `RESULT:` line only after both.
