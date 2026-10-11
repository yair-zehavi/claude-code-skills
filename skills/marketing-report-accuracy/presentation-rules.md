# Presentation rules

What may appear in the client document, how it is laid out, and how it is delivered.
The data can be right and the report still cost trust: a methodology footnote reads
as the agency hedging its own numbers, a tool name reads as exposed plumbing, a
number pushed to the wrong edge of an RTL tile reads as broken.

## What the client document is
Finished numbers and plain-language conclusions the client should see. Nothing else.
The full list of what is banned (methodology, FX math, provisional wording,
cross-system comparisons, spam-filtering theatre, tool references, notes to the
agency) is Rule 0 in [reviewer-checklist.md](reviewer-checklist.md#0-rule-0-no-internal-notes-in-the-client-document-block-run-first).

These leak because someone writes the note straight into the template or the
context builder. When one is found, delete it there and re-render. Where a caveat
that matters goes instead:
- the review block at the bottom of the delivery email (seen by the human sender
  only, deleted before sending), or
- the client's task log, for anything that needs follow-up.

Explaining in the report why your number differs from their GA4 reads as defensive and
turns a results document into a methodology debate. A rendered "how our numbers
reconcile" box was rejected for exactly that reason. Fix the label instead (see Labels
below), and answer a direct question from the client in the reply, not the PDF.

## Client-facing language
| Instead of | Write |
|---|---|
| DataForSEO, DFS, "DFS top 100" | tracked rankings, top 100 tracked rankings |
| ETV (estimated traffic value) | pages already pulling organic traffic, best-performing pages |
| SERP (as a casual noun) | search results, Google results |
| GSC, Search Console (in a heading or label) | organic search on Google |
| GA4, Google Analytics (in a heading or label) | website traffic, the channel being measured |
| "zero-click" | Google answers the question directly in the results |
| "volume warrants" | if search volume justifies it |
| raw field or event names | the plain metric name |

- Ad channels the client buys keep their names (Google Ads, Meta, LinkedIn).
- Internal artifacts (task logs, audit JSON, internal messages) keep the technical
  terms. The rule covers copy that reaches the client.
- When adding auto-generated recommendation sentences, write them for the client,
  not the operator, and keep the approved wording identical across clients.

## Numbers over narrative
- **Data-driven sections only.** Hand-written report sections produced awkward
  translated phrasing and factual errors, and were banned. A real non-data
  deliverable goes in the email or the task log.
- Section heading -> KPI tiles -> table. A framing paragraph that "explains" the
  section tends to overstate; drop it.
- Narrative sentences are generated from current-period values with the dynamic
  period label. A sentence that interpolates a nullable field is guarded, or it
  prints `None` into client copy.
- Big tables are not the deliverable. Render the top rows that support a decision;
  the snapshot JSON remains the full record.
- **Ad-hoc performance updates** (someone asks "give me the numbers for X", outside
  the monthly report) are numbers only: four windows (today, yesterday, last 7 days,
  month to date), the top-spending and the top-converting ad per platform with its
  ad set and the figure, cross-platform when several channels run. No summary
  paragraph, no highlighted "standout", no recommendations, no historical comparison
  unless asked, no trailing offers.

## Labels
- A label says what was counted: "website form leads", "inbound calls", "Meta leads".
  Never "total leads" for a single-channel count. In a Hebrew report that means
  `לידים מטפסי האתר` (leads from website forms), not `סה״כ לידים` (total leads).
- No source citations in sub-labels ("based on GA4", a vendor name in parentheses).
- Executive summary: 3 or 4 KPI tiles. One accent tile carries the headline number
  that matters most for this client. Ads-led client: total spend, leads, then a
  channel-specific tile. SEO-only client: organic clicks, organic impressions,
  ranked keywords.

## Currency display
- Each amount carries its own currency symbol from one formatting helper.
- No notes about billing currency, card conversion, or "not included in the total".
  Show the amount in its currency and stop.
- An account in a second currency gets its own KPI row; it never shares a symbol or a
  total with the main currency.

## Layout and section order
- Order follows what the client's month is about. Ads-led clients: cover, summary,
  site health, Meta, Google Ads, organic rankings, website traffic. SEO-led clients:
  cover, summary, site health, rankings, website traffic, ads at the bottom. Do not
  impose one client's order on another.
- Sections are gated by data-presence flags computed in the generator:
  ```python
  has_meta = (meta_summary.get("total_spend") or 0) > 0 or len(meta_campaigns) > 0
  has_gads = (gads_summary.get("total_spend") or 0) > 0 or len(gads_campaigns) > 0
  ctx["flags"] = {"has_meta": has_meta, "has_gads": has_gads,
                  "has_ads": has_meta or has_gads,
                  "has_site_health": (uptime_summary.get("monitor_count") or 0) > 0}
  ```
  Never gate on market or client name.
- Empty channel sections go to the bottom. Two empty ad sections can sit side by side
  to save a page. An all-zero section is suppressed.
- Optional sections (a list of articles published this month, AI Overview
  visibility) are opt-in per client and render nothing when their data is empty.
- Client logo is auto-detected from the client's assets folder (any file with "logo"
  in the name). A broken image or a text fallback while a logo file exists is a defect.

## Print CSS
```css
.section { margin: 0 0 20pt 0; }                 /* no page-break-inside: avoid here */
h2, h3, .section-heading { break-after: avoid; page-break-after: avoid; }
.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
           page-break-inside: avoid; }
table, .notes { break-inside: avoid; }
```
- `page-break-inside: avoid` on whole sections left huge white gaps whenever a section
  did not fit the remaining page. Protect rows, tables and headings instead.
- Two wide tables side by side overflow A4 and clip at the edge. Stack them.
- Render HTML to PDF with a headless Chromium (Playwright or Chrome). RTL flex layouts
  and modern CSS behaved correctly there; a CSS-only PDF engine needed workarounds.
- The visual page check in the reviewer pass is the only way to see page breaks.

## RTL reports (Hebrew and other RTL languages)
- `<html dir="rtl" lang="he">`. Use CSS logical properties (`margin-inline-*` and its
  padding counterpart) rather than left/right so one stylesheet works in both directions.
- **KPI numbers align to the start (right) edge.** Remove `unicode-bidi: plaintext`
  from `.kpi .value`; with it, the number renders LTR-isolated and sits at the left
  edge of a right-aligned tile, which looks broken. Keep `plaintext` only for a value
  that mixes LTR punctuation mid-line (`12,345 / 67.8%`).
- Numeric table cells are a separate, deliberate choice: `td.num` keeps
  `text-align: left` plus `unicode-bidi: plaintext` so digits line up on the column's
  edge. Don't "fix" it along with the KPI tiles, and check the rendered PDF.
- Empty-cell placeholder is a middle dot (`·`), never a dash.
- Strings copied from the client's site keep the report's dash rule; sanitize them in
  the context builder.
- Generated Hebrew narrative goes through a humanizer pass for translationese and AI
  filler, and uses natural verbs (leads `הגיעו`, "came in", not `גויסו`,
  "were recruited").
- Start a new RTL client from an existing RTL template, not by flipping an LTR one;
  copying an older template also copies its old bugs (check the KPI rule above).

## Files
- Deliverables are named `{ClientName-kebab}_{YYYY-MM}.{ext}`: `client_name` from
  config with spaces replaced by hyphens, case kept, and the period month. Example:
  `Acme-Corp_2026-04.pdf`, `.html`, `.context.json`, `.email-body.txt`.
- Consistent names sort by client and stay readable when many PDFs land in one inbox.

## Delivery email
- **Draft only**, in the sender's mailbox, addressed to the report recipients listed in
  config, PDF attached. Never sent by the pipeline.
- Subject with hyphens, no long dashes: `Monthly Report - {Client} - {Month} {year}`
  (Hebrew: `דוח חודשי - {client} - {month} {year}`).
- Body is short and in the sender's own voice: the greeting line verbatim from config
  (never derived from a contact-name field; if it is missing, ask), one line saying the
  month's report is attached, the sign-off. No headline numbers, no offers of a call.
- Clients that receive content work get that month's published articles in the body as
  real links: an HTML `<ul>` of `<a href>` anchors (bare URLs get rewritten into
  redirect junk by the mail client), title then URL in the plain-text body. Check each
  URL returns 200 first.
- **Review block** at the very bottom:
  ```
  ----- review (delete before sending) -----
  HTML: file:///<path>/<Client>_<YYYY-MM>.html
  PDF:  file:///<path>/<Client>_<YYYY-MM>.pdf
  Reviewer warnings: ...
  Internal cross-check: form leads 115 vs GA4 Key events 175 (118 forms + 57 phone)
  ```
  Bare paths on their own lines so they are select-copyable (mail clients do not
  linkify `file://`). The sender opens the rendered report, deletes the block, sends.
- RTL email: send an HTML body (plain-text Hebrew left-aligns), wrap it in
  `<div dir="rtl" style="text-align:right;font-family:arial,sans-serif">`, and put
  the review block in its own `<div dir="ltr">`. No font-size, line-height or color
  overrides, so the draft looks like something the sender typed. Wrap Latin tokens in
  Hebrew titles in `<span dir="ltr">`.
- Re-runs update the existing draft by its ID. Creating a new one each time leaves
  duplicates in the drafts folder.
- If the month included an outage in an automation, the client-facing wording is that
  it was paused and is running normally again, with the real times of any delayed
  leads. The root cause goes in the task log.
