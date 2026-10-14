# Hunt commands

Everything here is read-only. Run server-side commands on the server (Linux), one app at
a time, under `timeout`. Placeholders:

```bash
HOST=<site-host>            # www.example.com
ORIGIN_IP=<origin-ip>       # the server's public IP; on the server itself, the loopback address
WP_PATH=<docroot>           # the folder that holds wp-config.php
UA_WIN='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/<version> Safari/537.36'
```

## 1. Render sweep (origin, Windows user agent)

The definitive server-side test. It greps what WordPress actually renders, so it
catches injection that is assembled at `wp_head` time or obfuscated in files.

```bash
LOADER="GET_SIGNATURE|data\.signature|eth_call|atob\(|createElement\([\"']script[\"']\)[^;]*appendChild"
# once you know the relay path (the same-origin endpoint the loader POSTs to), add it:
# LOADER="<relay-path>|$LOADER"

sweep() {   # usage: sweep /some/path/
  curl -sk --max-time 30 --resolve "$HOST:443:$ORIGIN_IP" -A "$UA_WIN" "https://$HOST$1" \
    | grep -oE "$LOADER" | sort | uniq -c
}

# paths.txt: one path per page type (home, a post, a page, a product, a landing page)
while read -r p; do echo "== $p"; sweep "$p"; done < paths.txt
```

0 hits on every path means the origin renders clean. Any hit is a lead to inspect:
`atob(` alone also appears in legitimate bundles, the combination does not.

Fleet version, for an external-loader variant that every user agent sees (no origin
pinning needed):

```bash
# hosts.txt: one host per line; IOC: the loader domain or script name from the case
while read -r h; do
  printf '%s\t%s\n' "$h" "$(curl -sL --max-time 30 "https://$h/" | grep -cE '<loader-domain>|<script-name>')"
done < hosts.txt
```

## 2. Real-browser render (through the CDN)

Bots get the CDN challenge, so the post-challenge page is only visible to a browser.
With the Playwright MCP: navigate, wait for the page to settle, then evaluate:

```js
() => [...document.scripts].map(s =>
  s.src ? 'src ' + s.src : 'inline ' + s.text.slice(0, 200).replace(/\s+/g, ' '))
```

and list network requests. Flag inline scripts that build and append other scripts,
same-origin POSTs to odd paths, and requests to hosts you cannot name. Some zones
challenge the automated browser too; then the origin sweep is your source of truth.

## 3. Is the relay handler still live?

`<relay-path>` is the same-origin endpoint, planted by the attacker, that the
fake-verification loader POSTs to. Read it off the loader's request in the
real-browser network log.

```bash
for p in "<relay-path>" "/no-such-path-$RANDOM"; do
  curl -sk --max-time 20 --resolve "$HOST:443:$ORIGIN_IP" -X POST -o /dev/null \
    -w "%{http_code} %{size_download} $p\n" "https://$HOST$p"
done
```

Same status and size for both: the handler is gone, and anything visitors still see
comes from cache (purge it). A different answer for the handler path: a live handler,
go find it.

## 4. Backdoor grep, literal plus behavioral

```bash
BEHAV='(eval|assert|create_function|call_user_func|preg_replace[^,]*/e)[^;]{0,80}(\$_(POST|GET|REQUEST|COOKIE|SERVER)|base64_decode|gzinflate|str_rot13|hex2bin)|(require|include)(_once)?[ (]*(hex2bin|base64_decode)|bsc-dataseed|eth_call|llamarpc|publicnode|jsonrpc|REQUEST_URI[^;]{0,40}(rpc|proxy)'

timeout 600 grep -rlE "$BEHAV" "$WP_PATH" 2>/dev/null
```

Some legitimate plugins hit the broader patterns. Triage by location and age: a hit in
`mu-plugins`, uploads, a theme's `functions.php` tail, or a file with a near-core name
outranks a hit deep inside a well-known plugin. Confirm with the checksum and pristine
diff in section 7.

## 5. Places to check first

```bash
cd "$WP_PATH"

# mu-plugins: auto-loaded, never shown in the plugin list. Explain every file.
ls -la wp-content/mu-plugins/

# plugin folders vs the plugin list (dot-prefixed folders are skipped by WordPress;
# the folder's own index.php shows up as "index", ignore it)
ls -1A wp-content/plugins | sed 's/\.php$//' | sort > "$HOME/ir-folders.txt"
wp plugin list --field=name | sort > "$HOME/ir-listed.txt"
comm -23 "$HOME/ir-folders.txt" "$HOME/ir-listed.txt"

# tail of every theme's functions.php, active and inactive
for f in wp-content/themes/*/functions.php; do echo "== $f"; tail -n 25 "$f"; done

# loose PHP in the docroot and in wp-content (expect core files, index.php and known drop-ins only)
ls -la ./*.php wp-content/*.php

# unauthenticated AJAX hooks: list them, explain the unknown ones
grep -rn "wp_ajax_nopriv_" wp-content --include='*.php' | cut -c1-200

# uploads: PHP files, zero-byte probes, form-upload temp folders
find wp-content/uploads -type f \( -name '*.php*' -o -name '*.phtml' \) -printf '%TY-%Tm-%Td %u %s %p\n'
find wp-content/uploads -type f -size 0 -printf '%TY-%Tm-%Td %u %p\n'

# docroot: unknown files, folders, Search Console verification files
ls -la "$WP_PATH"
ls -la "$WP_PATH"/google*.html 2>/dev/null
```

Legitimate PHP does live in uploads on some sites (PDF library fonts, security-plugin
data, optimization-plugin test files). Know which ones before you delete.

## 6. Recently modified files

```bash
find "$WP_PATH" -type f -name '*.php' -newermt '<YYYY-MM-DD>' \
  -printf '%TY-%Tm-%Td %TH:%TM  %u  %s  %p\n' | sort
```

Explain every cluster. A core update touches many core files within the same minute;
security plugins write logs in their own folders. A single file with a fresh mtime
next to old neighbours is the interesting one. The owner column also tells you who
wrote it: the app user (the web app, so possibly the attacker through PHP) or the
server-level user (you, or a deploy).

## 7. Integrity

```bash
wp core verify-checksums
wp plugin verify-checksums --all     # wp.org plugins only; premium ones cannot be verified this way

# premium plugins and child themes: diff against a pristine copy of the same version
diff -rq <pristine>/<plugin> "$WP_PATH/wp-content/plugins/<plugin>"
```

## 8. Database

```bash
P=$(wp db prefix)

# administrators, newest first
wp user list --role=administrator --fields=ID,user_login,user_email,user_registered \
  --orderby=registered --order=DESC

# autoloaded options carrying script or decoders
wp db query "SELECT option_name, LENGTH(option_value) AS len FROM ${P}options
  WHERE autoload NOT IN ('no','off','auto-off')
  AND (option_value LIKE '%<script%' OR option_value LIKE '%atob(%'
       OR option_value LIKE '%eval(%' OR option_value LIKE '%0x6d4ce63c%')"

# management child plugin state (pubkey, server, connected admin, site id).
# <child-option-prefix>: the option-name prefix used by <management-child-plugin>, for
# example the child plugin of your site-management dashboard
wp db query "SELECT option_name FROM ${P}options WHERE option_name LIKE '<child-option-prefix>%'"
```

When WP-CLI cannot boot the app as your user, get the DB credentials by parsing the
`define()` calls in `wp-config.php` with PHP's tokenizer (`token_get_all`), never by
including the file, and query with the MySQL client. Pass the password through a
client config file or the environment, not on the command line.

## 9. Soft-404 check before believing a webshell scan

```bash
# probes.txt: the scanner's list of suspicious paths
while read -r p; do
  curl -sk --max-time 20 -o /dev/null -w '%{http_code} %{size_download}\n' "https://$HOST/$p"
done < probes.txt | sort | uniq -c | sort -rn | head
```

If most probes return 200 with one identical size, the site serves its error page with
HTTP 200. Only a probe whose size differs from that is worth opening.

## 10. Entry vector by cross-similarity

```bash
# on every hacked site (save as hacked-<site>.plugins) and on one known-clean site
# with the same stack (save as clean.plugins)
wp plugin list --status=active --field=name | sort

# on your machine: plugins the clean site does not run, with how many hacked sites run each
cat hacked-*.plugins | sort | uniq -c | sort -rn \
  | awk 'NR==FNR {clean[$1]=1; next} !($2 in clean)' clean.plugins -
```

Then pull versions for the survivors (`wp plugin list --fields=name,version`) and look
for old builds of upload-capable or RCE-prone plugins. Different sites can have
different entry plugins, so do not require a candidate to be on every hacked site.
Plugins on the clean site too are not the vector.

## 11. Access-log signals

```bash
# total POSTs to a core bootstrap file and bytes served (combined log format)
awk '$6=="\"POST" && $7 ~ /^\/wp-load\.php/ {n++; b+=$10}
     END {printf "%d requests, %.1f MB\n", n, b/1048576}' <access-log>
```

Large responses to POSTs on a core file that checks clean against the core checksums
point to a hook in bootstrap serving something. Hunt for it, and block the path at the
edge meanwhile.

## 12. Capturing an on-chain payload as evidence

If the loader references a contract, pull what it currently returns, as text, before
the attacker clears it. Use the RPC endpoint the relay handler itself calls.

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"eth_call","params":[{"to":"<contract-address>","data":"0x6d4ce63c"},"latest"]}' \
  <rpc-endpoint>
```

`0x6d4ce63c` is the selector for `get()`. The result is an ABI-encoded string holding
the base64 payload. Save it to the evidence folder and never run it. A result of `0x`
means the storage has already been cleared.

## 13. CDN account review

Do this in the dashboard or through the API with a read token:
- Members: anyone you cannot name.
- API tokens: every token, its scope and last use; CLI OAuth grants count.
- Workers, Snippets, Zaraz tools, Transform and Page rules: anything that can rewrite
  HTML at the edge.
- Audit log for the incident window: logins, token creation, rule and Worker changes.
