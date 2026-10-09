---
name: design-qa
description: >
  Adversarial, pixel-level UI/UX review loop for a live page. Spawns a fresh-eyes
  reviewer subagent that MEASURES (not eyeballs) alignment, rhythm, overlap,
  contrast, and RTL correctness (when applicable) via Playwright, returns severity-ranked findings,
  then the main agent fixes and re-runs until two consecutive clean passes.
  Use when a built page needs to get from "works" to "pixel-perfect client-ready".
  Args: <url> [viewports=1440,390] [focus=section-ids] [log=path/to/qa-log.md]
---

# Design QA: the annoying-client reviewer loop

## Why this exists
The agent that built a page always passes its own review. Real clients nitpick:
2px misalignments, uneven gaps, a caption that almost touches an image, a word
orphaned on its own line. This skill simulates that client - with a ruler.

## Non-negotiable rules
1. **The reviewer is always a fresh subagent** (Agent tool, `subagent_type: claude`).
   Never review in the builder's own context. The reviewer prompt must NOT include
   build history or design rationale - only the URL, viewport list, and the
   measurement protocol below. Fresh agent per pass.
2. **Measure before judging.** Every spacing/alignment claim must carry numbers
   (`expected 64px, actual 41px`). Screenshot impressions alone are NITs at best.
3. **The reviewer may not rubber-stamp.** A pass with zero findings is only valid
   if all programmatic checks ran and returned clean - state which checks ran.
4. **Loop control:** fix → NEW reviewer pass → repeat. Stop at two consecutive
   passes with no BLOCKER/MAJOR, or after 4 rounds (then report what remains).
5. **Won't-fix ledger.** The user/builder may mark a finding `accepted`. Log it in
   the QA log; future reviewers receive the ledger so they don't re-flag it.
   This ledger is the ONLY build context a reviewer gets.

## Reviewer protocol (paste into the subagent prompt)

You are a meticulous, slightly annoying design-QA reviewer. The site owner pays
you to find what's off, not to be nice. You know nothing about how this page was
built and don't care - only what a picky client sees.

For EACH viewport (default 1440×900, then 390×844):
resize → load the URL fresh → let animations settle (~4s) → then:

### A. Programmatic checks (browser_evaluate; report numbers)
1. **Horizontal overflow:** `document.documentElement.scrollWidth - innerWidth`
   must be ≤ 0. Anything else: BLOCKER, name the overflowing elements.
2. **Edge alignment (the reading-start edge is primary: left for LTR, right for RTL):** per section, collect
   `getBoundingClientRect()` of eyebrow, heading, body paragraphs, buttons.
   All primary edges within a section should agree within 2px. Report deltas.
3. **Vertical rhythm:** per section, gaps eyebrow→heading, heading→body,
   body→CTA; and section paddings. Flag gaps deviating >20% from that gap's
   modal value across sections (inconsistent rhythm), and any gap < 8px
   (cramped) or > 1.5× the section's own median (floating).
4. **Decor overlap:** for every absolutely-positioned decorative element
   (watermarks, sketches, progress lines), intersect its rect with every text
   element's rect. Any intersection where text sits ON the decor with contrast
   loss: MAJOR. Pure background layering with readable text: note as MINOR/ok.
5. **Contrast (WCAG):** computed text color vs effective background for every
   text node class; < 4.5:1 body / < 3:1 large text = MAJOR.
6. **Mobile only:** tap targets ≥ 44×44px (links/buttons/summary); font-size
   ≥ 15px body; no fixed elements covering content.
7. **Images:** displayed px vs naturalWidth (upscaled >1.15× = MINOR), faces
   cropped by object-fit/border-radius = MAJOR.
8. **Widows:** headings/leads whose last line is a single word: MINOR.

### B. Visual pass (screenshots, full page + per flagged section)
Composition only a human eye catches: optical (mis)alignment, unbalanced
whitespace, elements that *almost* touch, inconsistent corner radii or icon
sizes, anything that looks accidental rather than designed.

### C. Output - STRICT format, nothing else
| # | severity | viewport | section | element | measurement (expected → actual) | suggested fix |
BLOCKER = broken/unreadable/overflow · MAJOR = a client would call ·
MINOR = a designer would notice · NIT = perfectionist territory.
End with: `VERDICT: PASS` (no BLOCKER/MAJOR) or `VERDICT: FAIL (nB/nM/nm/nN)`.
Then a one-line "checks run" list confirming A1-A8 all executed.

## Builder loop (main agent)
1. Run reviewer pass 1 → triage: fix all BLOCKER/MAJOR, batch MINORs that share
   a root cause; propose `accepted` for intentional design choices (ask user
   only if genuinely ambiguous).
2. Deploy fixes (bump the asset version, purge caches), then spawn a NEW reviewer for pass 2 with the updated ledger.
3. Append every pass to the QA log: date, pass #, findings table, what was
   fixed, ledger. Default log: next to the page source as `design-qa-log.md`.

## Practical notes
- The Playwright MCP browser is typically a shared singleton - never run two reviewers in
  parallel; sequential passes only.
- If tools are deferred, the reviewer must load them via ToolSearch first:
  `select:mcp__playwright__browser_navigate,mcp__playwright__browser_resize,mcp__playwright__browser_evaluate,mcp__playwright__browser_take_screenshot,mcp__playwright__browser_wait_for`
- For pages with scroll animations (for example GSAP): settle by scrolling the full page once (triggers once-anims),
  return to target section, wait 1s, then measure; for static screenshots,
  force-finish reveals (set opacity/transform/clipPath inline) BEFORE capture.
- Caches may serve stale assets: re-review only after confirming the new
  asset version (for example a `?ver=` query) is actually being served.
