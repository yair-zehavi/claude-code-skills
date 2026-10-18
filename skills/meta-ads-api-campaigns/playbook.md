# Playbook: structure, audiences, pages, copy, policy, results

The judgment half of the skill. `SKILL.md` covers how to build safely; this covers
what to build. Money figures are observed rules of thumb from ILS-denominated
accounts in 2026, not Meta guarantees.

## 1. Andromeda-era structure

Andromeda is Meta's ad retrieval engine: for each impression it narrows the whole
ads inventory to a short list of candidates before ranking. Engineering write-up:
https://engineering.fb.com/2024/12/02/production-engineering/meta-andromeda-advantage-automation-next-gen-personalized-ads-retrieval-engine/

- **More variants beat fewer.** A larger candidate pool gives better per-impression
  matches. The industry pattern is 15-30 variants per source asset, not 3-4.
  Advantage+ creative enhancements (image animation, music, text variations,
  brightness and contrast) each add candidates.
- **Do not pause existing winners when launching new ads.** It shrinks the pool for
  every ad in the set, pausing ads for more than 7 days resets the ad set's learning,
  and it removes your comparison baseline. Pause non-spenders instead: ads spending
  under roughly ILS 50-100 a week, which the system has already decided not to serve.
- **Fund every ad.** Conversion-optimized ad sets need about ILS 30-50 per ad per day
  for around 7 days to give each variant a fair chance. Twelve ads at ILS 35 is about
  ILS 420 a day. Below the floor, ads either never gather a click-through signal or
  get one bad early impression and stay down-ranked: starved, not bad.
- **Set the budget before activating and hold it for the full test window.** Even an
  increase sends affected ads back into exploration. "Add budget because it looks
  good" is the fastest way to break a learning ad set.
- **Cadence:** new creative batches every 2-3 weeks (2026 industry write-ups put
  fatigue windows at 2-3 weeks, down from 6+), and 2-4 genuinely new concepts a
  week once spend passes about ILS 200 a day. Daily or every-other-day monitoring
  is normal.
- **One primary inclusion signal per ad set.** Stacking a custom audience, a
  lookalike and detailed targeting in one ad set intersects three constraints and
  starves the optimizer. Use customer lists mostly for exclusion on cold prospecting,
  and let Advantage+ Audience or targeting expansion do the broadening.

### Creative fatigue or algorithm shift?

- **Creative fatigue:** CTR drops evenly across all continuing ads, CPMs flat or
  down, ROAS holds up roughly in proportion, and new ads launch weak because the
  audience pool is exhausted.
- **Algorithmic shift:** a sharp single-day inflection, CPMs spike (auction
  protection), specific placements get deprioritized, lookalike audiences are hit
  differently from the rest.

Flat or falling CPMs with falling CTR is fatigue and audience exhaustion, not Meta.
Require three consecutive days of decay before acting on fatigue; single-day
reactions flip-flop.

## 2. Audiences

### Value-based customer list from a CRM export

Normalize the export to Meta's lowercase headers: `email, phone, fn, ln, ct,
country, value`. Upload raw values; Meta hashes on upload.

- Email: lowercase, trimmed.
- Phone: E.164. Strip non-digits, drop the national leading zero, prepend the country
  code. Fall back to a second phone column when the mobile is empty.
- Names: split on the first whitespace (`fn` = first token, `ln` = the rest).
- Country: ISO code; map local spellings of the country name to it.
- Drop rows with neither email nor phone; Meta cannot match them.
- `value` by funnel stage, on a logarithmic spread so closed deals dominate the
  signal. Example: open or qualified lead 1, proposal sent 5, closed won 20. Milder
  1/2/5, more aggressive 1/3/100; pick what reflects the real lifetime value gap.

Upload: Audiences -> Create Audience -> Customer List -> enable "Include customer
lifetime value" -> upload. Then create a Lookalike with "Value-based" enabled. Keep
the original CRM export untouched.

**Small lists:** value-based lookalikes need roughly 1,000+ customers with real value
spread; a list of a few hundred, mostly in the lowest tier, falls back to a regular
lookalike. Filter instead of weighting: build a second audience with only the top
tier (closed deals) and make a regular lookalike from it. The result is three
audiences: the full list for exclusion on prospecting, the top-tier subset as the
lookalike source, and the lookalike as the prospecting audience.

### Audience-building spend is not waste

Before proposing to cut an ad set because it has no direct conversions, check why it
exists. An ad set sending traffic to the website can be there to build a site-visitor
audience for remarketing and lookalikes. Likewise a long lead form with qualifying
questions may be deliberate (a low completion rate from form open can be the point).
Check the client's standing decisions before recommending either change.

## 3. Landing-page selection

Choose from data plus the ad's promise, and give one recommendation per ad.

1. **Pull GA4 for 90 days:** `landingPage` x `sessions`, `keyEvents:<lead event>`,
   `sessionKeyEventRate:<lead event>`, and the `pagePath` where the lead event fires
   (often a thank-you page, which shows how much of the count is support traffic,
   not sales).
2. **Remove noise:** support pages and their thank-you pages, thank-you rows at 100%
   (reloads), tiny samples.
3. **Read rates with intent in mind.** About and contact pages convert well because
   visitors already know the brand; they are poor cold-traffic destinations. A page
   built for paid traffic is the best benchmark for how cold visitors convert. The
   homepage often wins on volume at a low rate.
4. **Message match beats raw rate.** Each ad goes to the page that shows exactly what
   it promises. An ad promising a free plan never lands on a page headlined with a
   paid price.
5. **Thin data:** split ads across two pages and let `utm_content` decide after one to
   two weeks.
6. **Flag contradictions** between pages (two prices for the same plan) to a human and
   keep the disputed claim out of the ads.
7. Use the canonical URL, so the click does not pay for a redirect.

## 4. Ad copy

The style that won against wordier, more technical drafts:

- **Headline:** short, second person, a benefit or metaphor, no brand name.
- **Open on the concrete action the viewer sees** in the video or image.
- **A negation triad** naming the friction removed.
- **Introduce the product plainly and last**, as an object the person uses.
- Second person throughout ("your"), not narration of the on-screen actor.

Illustrative shape (invented product):

> **Your Door Answers Itself**
> See who is at the door and talk to them from your phone, wherever you are.
> No running downstairs, no missed parcels, no guessing.
> That's <Product>, the doorbell that puts your front door in your pocket.

Hard rule: **never name the product as if it were the person.** "This is <Product>.
Watch him answer the door" fails because the person on screen is using the product;
he is not called <Product>. Skip mechanism explanations; say what it
does, not how the technology works. No em-dashes or en-dashes in ad copy, in any
language. Keep primary text near 125 characters (where mobile truncates it) and
headlines near 40, and put the point first.

## 5. Policy, sensitive products and client exclusions

- **Sensitive products are often allowed; the risk is the copy.** Meta's personal
  attributes policy flags copy that implies the viewer has a condition. Third-person
  product, offer and delivery copy passes; second-person copy that assumes the
  viewer's condition ("Running out of your ...?") gets flagged. Before restricting a
  category, search the Ad Library: if competitors run active ads with product
  creatives, offers and Shop Now, the category is fine, and retargeting, catalog ads
  and purchase optimization can run too.
- **No religion or ethnicity targeting exists**, and Meta has also retired employer,
  job title and school targeting. A request to exclude people by a protected trait is
  a human decision: do not build a geographic or interest proxy on your own, since for
  job, housing or credit offers that can be unlawful discrimination.
- **Special ad categories:** campaigns send `special_ad_categories`, an empty list
  when none applies. Whether an offer belongs in a category is a human call.
- **Client exclusions:** some clients may not advertise certain services (for example
  rules restricting how a regulated profession promotes its core work). Advertise
  only the permitted adjacent offers, keep legacy campaigns built on excluded
  services paused, and never clone them.
- **Copy and targeting must agree.** A geo named in the copy that is not in the
  targeting is a brief error to fix with the client before building.

## 6. Results definition

Decide per account what counts as a result before reporting or optimizing.

- **The result action differs by account:** the generic `lead`, `purchase`,
  `offsite_conversion.fb_pixel_custom`, or a specific
  `offsite_conversion.custom.<conversion_id>`. Store it in the client config. A
  non-default action should be matched exactly, with no fallback.
- **Audit it:** list the `actions` dict of a recent insights pull for every spending
  campaign and compare with the configured action. The gold standard is the lead or
  order count in the CRM or shop.
- **The pixel event to send is whatever the active ad sets optimize for:** read
  `promoted_object.custom_event_type` from the ad sets (one account used `Contact`,
  not `Lead`).
- **Do not sum duplicate actions.** `purchase`, `omni_purchase` and
  `offsite_conversion.fb_pixel_purchase` were the same event on one account; summing
  them triple-counted purchases and faked ROAS. For a cross-account monitor, take
  the larger of `lead`, `offsite_conversion.fb_pixel_lead` and `onsite_web_lead`
  rather than their sum.
- **One canonical reporting script.** Per-client copies drift and silently ignore
  per-account overrides.

## 7. Budget automation design

- **Deterministic daily mover, separate weekly judgment.** The script that changes
  budgets is plain code with no model in the loop; structural recommendations come
  from a separate read-only review.
- **Dry-run by default**, `--apply` to write. Skip ad sets already at target, keep the
  sum exact after rounding, and flag targets below a sensible daily minimum.
- **Kill switch inside the script** (a pause file or an environment flag the script
  itself checks), not only in the scheduler.
- **Announce every money change** in a channel a human reads; a log file alone is
  not enough.
- **Guardrails:** cap the per-run change (for example 15% of an ad set's budget),
  never auto-raise total spend beyond the agreed band, never auto-pause a winner,
  respect the per-ad budget floor before calling an ad "low spend", and block
  flip-flopping by skipping recently touched ad sets.
- **Separately funded campaigns:** one ad account can host campaigns paid from a
  different budget. Exclude them from caps and from month-to-date pacing.

## 8. Naming convention

- Campaign: `<Type> | <Brand> | <Geo> | <Month YYYY>`
- Ad set: `<Geo or Audience> | <Goal>`
- Creative: `<Brand> | <Ad name>`
- Ad: `Ad <n> | <Hook>`

Consistent names make insights exports and UTM joins readable, and an ID-based UTM
scheme keeps working even when names drift.
