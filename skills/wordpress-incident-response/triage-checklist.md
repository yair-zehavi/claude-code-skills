# Triage checklist

## Detection signals

| Signal | What it has meant | First check |
|---|---|---|
| Fake CDN-style "Verify you are human" page asking for Win+X or Win+R, paste, Enter | ClickFix overlay, usually loaded by an injected script (see [worked-cases.md](worked-cases.md)) | Real-browser load plus origin render sweep with a Windows user agent |
| "Only on Windows", "not on Mac", "comes back after cleaning", "on all the sites" | A loader that targets Windows, plus a fan-out channel still alive | Management child-plugin options on every site, CDN account audit log |
| Server-side curl and grep find nothing, visitors still affected | Stale CDN or page cache, or the CDN challenge hiding the page from bots | Purge, then load in a real browser |
| Search Console "Deceptive pages" with no sample URLs | Injected external script on every page | Plain `curl` of the homepage, grep for script tags pointing at unknown hosts |
| Defacement page ("Hacked") | Overwritten `index.php`, webshells in the docroot, and usually a quieter backdoor elsewhere | Full hunt, `mu-plugins` first |
| Unknown PHP files or folders in the docroot | Webshells, attacker working folders | Owner and mtime of each, then the full hunt |
| `google<token>.html` in the docroot that does not match your own Search Console verification | Attacker claiming the property in Search Console | Keep a copy as evidence, then remove |
| Zero-byte files in uploads, PHP under a form-upload temp folder | Upload probes and a webshell dropped through a form file-upload field | Uploads sweep, form plugin version |
| Administrator nobody recognises (empty profile, made-up email domain, created recently) | Backdoor account, or a security vendor's temporary support account | Ask the owner; list files modified since the account was created |
| Many large POST responses to a core bootstrap file with a random query string, from dozens of cloud-provider IPs | Likely something hooked into WordPress bootstrap serving a payload (observed once in 2026, with the core file unmodified) | Access-log totals, backdoor hunt, WAF rule on that path |
| Host malware or vulnerability alert | The host's own scanner found something | Treat as a lead, not a verdict; hunt yourself |
| Plugin advisory email | Possible exposure across the fleet | Verify independently, not from the email, then [scripts/fleet-advisory-audit.sh](scripts/fleet-advisory-audit.sh) |

## First hour

- [ ] Tell the reporter and the client team not to follow any on-screen verification
      steps. Ask whether anyone already did, and on which machine.
- [ ] Record the report: who, what they saw, OS and browser, URL, time, screenshot.
- [ ] List the sites that share a server, a CDN account, a management dashboard or a
      plugin set with the affected site. That is the provisional blast radius.
- [ ] Confirm restore points: host backup schedule and retention, off-host dumps, VM
      snapshots. Note the oldest available date.
- [ ] Gather access without asking anyone to paste a secret: server SSH (is your
      current IP allowlisted?), app-level SFTP, WP-CLI path, CDN API token. Look in
      the secrets store before saying something is missing.
- [ ] Capture evidence before any write: rendered HTML from the origin and from a real
      browser, network requests, suspect files (copy plus metadata), admin list.
- [ ] Open the evidence log (template below).
- [ ] Decide the containment step that can go live immediately, usually the edge WAF
      rule.

## Know your own footprint

Before you read access logs or the admin activity log, list the IP ranges your own
team uses. Residential IPs rotate between sessions, so an admin login from a consumer
ISP in your own country is often your own staff. Do not attribute admin activity to a
third party (or to the client's developer) until you have ruled that out.

## Evidence log template

Keep one file per incident, next to the site's other operational notes. Times in ISO
8601 with time zone. Secret values never go in it, only the fact that a secret was
rotated.

```markdown
# Incident <site or fleet> <YYYY-MM-DD>

## Report
- Reported by / when / channel:
- What they saw (OS, browser, URL):
- Screenshot / saved HTML:

## Timeline
- <timestamp> <event> (<source of the fact: log line, mtime, report>)

## Blast radius
- Sites checked: <n> / affected: <list>
- Shared factors: <server, CDN account, management dashboard, plugins>

## IOCs
- Paths / endpoints:
- Search tokens:
- External hosts and contract addresses:
- File hashes (sha256):

## Files
| path | owner | mtime | size | sha256 | action (quarantined / removed / restored) | when |

## Accounts
| user | email | created | role | decision | decided by |

## Entry vector
- Candidate(s) and the evidence (cross-similarity table, upload paths, logs):

## Containment
- <timestamp> <action> (edge rule, child plugin removed, quarantine)

## Rotation
| secret | rotated when | by whom | where it is stored now |

## Verification
- Render sweep output, checksum output, uploads 403 check, admin review:

## Open follow-ups
- [ ] <task> (owner, why it matters)
```
