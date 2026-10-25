# Transactional email for a moved site

After the move, everything WordPress sends (form notifications, auto-replies, password
resets) leaves from the new server. On a managed host that relays every app through one mail
provider, the site's domain has to authorize that provider in DNS and be verified by it.
Skip either and mail still goes out, unsigned, and fails DMARC at the receiver.

The specifics below were observed in 2026 on Cloudways with its Elastic Email add-on. The
pattern holds for any shared relay: find out what the relay signs with, publish exactly that,
verify inside the relay, and prove it with a real message.

## How mail leaves the new host

- On a Cloudways server with the Elastic Email add-on, postfix relays every app through
  `[smtp.elasticemail.com]:2525`. Every domain moved onto such a server must be added to
  the add-on.
- Until the domain is added and verified, Elastic still relays the mail but does not
  DKIM-sign it at all (not even with its own `d=elasticemail.com` signature). Delivery
  "works", authentication does not.

## Records

Create them in the Cloudflare zone before the nameserver switch, DNS-only.

| purpose | type | name | value |
|---|---|---|---|
| SPF | TXT | `<domain>` | the existing SPF record with `include:_spf.elasticemail.com` added. One SPF record per name: merge, never add a second. Keep the mailbox provider's include. |
| DKIM | TXT | `api._domainkey.<domain>` | the current **2048-bit** key published in the host's documentation |
| tracking | CNAME | `tracking.<domain>` | `api.elasticemail.com` |
| DMARC | TXT | `_dmarc.<domain>` | `v=DMARC1; p=none;` |

Keep the MX records exactly as they were: the mailboxes are not moving.

## The 2048-bit DKIM key

- As of October 2026, Elastic Email verified a **new** sender domain only against a 2048-bit
  DKIM key. The older shared 1024-bit `api._domainkey` value still works for domains that
  were verified earlier; their status appears to be cached and not rechecked. A new domain
  that published the 1024-bit value stayed DKIM red until the 2048-bit key replaced it.
- So don't copy the DKIM record from another domain that "works". Its green status may be
  cached; some domains verified long ago even show DKIM verified with no record published at all.
- The key comes from the Cloudways knowledge-base article on activating the Elastic Email
  add-on. That page sits behind a Cloudflare challenge (403 to curl and simple fetchers), so
  read it in a real browser.
- The 2048-bit record is about 410 characters. A single TXT string holds at most 255, so
  enter it as two quoted strings in one record.
- Elastic says the key can change over time. If verification starts failing, re-read the
  source before debugging anything else.

## Verify the domain in the relay

1. After the zone is live, add and verify the domain in the host's Elastic Email add-on
   (panel or API; on Cloudways the verify call also adds the domain).
2. All five checks must be green. The API returns booleans only, never the values it
   expected, so compare your records with the documentation yourself when one stays red.
3. Signing starts a few minutes after verification turns green.

## Default sender

Cloudways apps have an application setting "Default email sender". Its default is
`<app>@<server>.cloudwaysapps.com`, used as the envelope sender, which breaks DMARC alignment
for mail that claims to come from the site's domain. Set it to a real mailbox on the domain,
for example `<mailbox>@<domain>`.

Forms should use the same mailbox as From and Reply-To. If a form sends an auto-reply to the
visitor (in Elementor, the second email action addressed to `[field id="email"]`), give it
the same From and Reply-To.

## Relay test

From a shell on the new server, to an inbox you control:

```bash
printf 'From: <mailbox>@<domain>\nTo: <test-inbox>\nSubject: relay test\n\nrelay test\n' \
  | /usr/sbin/sendmail -f <mailbox>@<domain> <test-inbox>
```

Open the received message's full headers (in Gmail: "Show original"). Pass:

```
Authentication-Results: ... dkim=pass header.i=@<domain> ...
```

No DKIM result at all means the domain is not verified in the relay yet, or verification is
younger than a few minutes.

## Where the logs are (and aren't)

- `/var/log/mail.log` is `root:adm` on Cloudways servers, so the master user cannot read it.
- `mailq` works and shows anything stuck in the local queue.
- The Cloudways API exposes no mail log. The panel can download the Elastic logs.

## Done means

- [ ] All five relay checks green.
- [ ] Default sender is a mailbox on the domain.
- [ ] Relay test shows `dkim=pass header.i=@<domain>`.
- [ ] One expected, labelled form entry reached the client's inbox, confirmed by the client.
