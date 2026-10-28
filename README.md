# claude-code-skills

Claude Code skills that turn repeatable marketing-operations work into a written method the agent follows the same way every time. Each one comes from running these jobs for real client accounts: the rules and gotchas are the ones that cost time or money the first time round.

## Skills

**Content and design**

| Skill | What it does | When to use it |
|---|---|---|
| `humanizer-he` | Finds and rewrites AI-sounding patterns in Hebrew text: translated filler, empty marketing phrases, overused verbs, formal register where modern Hebrew fits. | Editing Hebrew copy, articles or emails that read as machine-written. |
| `design-qa` | Runs a review loop on a live web page. A fresh reviewer subagent measures spacing, alignment, overlap and contrast in a browser, reports ranked findings, and the main agent fixes and re-runs. | Taking a built page from "works" to client-ready. |
| `art-directed-graphic` | A method for client graphics that do not look AI-generated: name a physical medium, generate the art without text, then add typography and logo in HTML. Includes a library of 20 art directions. | Cards, social posts, flyers and posters that need a designed look. |
| `ai-image-production` | Routes each image job to the model family that won it in testing, keeps real products faithful with reference editing instead of a LoRA, and runs a manifest-driven pipeline for one subject across many scenes and ad ratios. Includes provider fallback and a scroll-driven video hero built from real photos. | Product shots, people, article covers, multi-ratio ad creatives, image-to-video. |

**Ads and reporting**

| Skill | What it does | When to use it |
|---|---|---|
| `meta-ads-api-campaigns` | Builds Meta campaigns, ad sets, creatives and ads from code with everything created paused, UTMs on every ad, and every write read back. Documents the write traps, such as a creative swap that silently sets the ad live. | Creating or editing anything in a Meta ad account through the API. |
| `marketing-report-accuracy` | A monthly multichannel report pipeline (pull, aggregate, generate, independent reviewer, draft for human review) with the rules that make each number survive a client's own dashboard. | Building or debugging a recurring client performance report. |

**WordPress operations**

| Skill | What it does | When to use it |
|---|---|---|
| `wordpress-elementor-automation` | Writes to WordPress and Elementor through REST, WP-CLI and the editor JS API, then proves the change on the rendered page. Covers the cache layers that keep serving old HTML. | Any agent edit to a live WordPress site. |
| `wordpress-host-migration` | Moves a site from a restricted shared host to a managed cloud host behind Cloudflare: FTP-only export, verified pulls, email DNS, and a gated cutover script with automatic DNS rollback. | Changing hosts without losing leads, mail or rankings. |
| `wordpress-incident-response` | Defensive response for a compromised site or fleet: render sweep, backdoor hunt, cleanup, credential rotation and hardening, with worked cases including a ClickFix fake-verification attack. | A fake "verify you are human" page, a malware alert, an infection that comes back. |

## Install

Copy the skill folders you want into your personal skills directory or a project:

```
cp -r skills/design-qa ~/.claude/skills/

# or per project
cp -r skills/marketing-report-accuracy your-project/.claude/skills/
```

Claude Code picks up each skill from its `SKILL.md` frontmatter and loads the reference files next to it only when needed. `design-qa` expects the Playwright MCP server to be available. Scripts inside skill folders use placeholders and read credentials from the environment or stdin; review them before running against a real site or account.

## humanizer-he

Hebrew counterpart to the English humanizer skill. It scans for 34 pattern types, rewrites in modern Israeli Hebrew, then runs a second "what still sounds like AI" pass. Hebrew-specific checks include replacing `מהווה` and `משמש כ` with plain `הוא` or `היא`, removing gender-slash overuse, and keeping English tech terms in Latin script.

Before:
> בעידן הדיגיטלי של היום, אפליקציית האימונים שלנו מהווה פתרון מהפכני שלוקח את הכושר שלכם לרמה הבאה. חשוב לציין שהיא פשוטה, חכמה ויעילה.

After:
> האפליקציה מתעדת כל אימון ומציגה את ההתקדמות שלכם לאורך שבועות. ההתחלה לוקחת שתי דקות, ואין צורך בציוד.

What changed: the opening filler, the inflated "revolutionary" claim, the translated "next level" idiom and the stacked adjectives were replaced with two concrete facts.

## design-qa

Invoke with a URL, for example `/design-qa https://example.com/landing 1440,390`. The builder never reviews its own work. Each pass uses a new subagent that knows nothing about how the page was built, runs eight programmatic checks (overflow, edge alignment, vertical rhythm, decor overlap, contrast, tap targets, image scaling, widows), and returns a strict table.

Example finding:

| # | severity | viewport | section | element | measurement | suggested fix |
|---|---|---|---|---|---|---|
| 3 | MAJOR | 390 | pricing | `.plan-card h3` | gap to body expected 16px, actual 6px | restore the section spacing token |

The loop stops after two consecutive passes with no blocker or major finding, or after four rounds.

## art-directed-graphic

The core idea: AI graphics look generic because they have no physical surface. Naming a medium and its imperfections ("four-colour silkscreen, 1mm ink misregistration, exactly four inks") produces a design instead of a stock render. Text and logos are always added afterwards in HTML and headless Chrome, so copy revisions cost nothing and scripts such as Hebrew or Arabic render correctly.

Example prompt fragment:

> A handmade cut-paper collage photographed flat under raking daylight. Torn paper fibre at every edge, each layer casting a soft shadow. Exactly four papers: crimson, warm gold, deep navy and cream. The upper third is left empty for typography and the image contains no writing of any kind.

## Attribution

`humanizer-he` is derived from the `humanizer` skill by Siqi Chen (blader), MIT License: https://github.com/blader/humanizer. That skill is based on Wikipedia's "Signs of AI writing" guide. The Hebrew patterns, examples and structure here are my own adaptation. Full notice in [NOTICE](NOTICE).

## License

MIT. See [LICENSE](LICENSE). Third-party material is covered in [NOTICE](NOTICE).
