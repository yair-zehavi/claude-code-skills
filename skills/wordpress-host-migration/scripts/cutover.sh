#!/usr/bin/env bash
# Gated DNS cutover: old host -> new host behind a Cloudflare zone, with automatic rollback.
# Runs on an always-on machine (not on either host). Never writes to the old host: every call to the
# old origin goes from the new server, pinned to the old IP, using the token file that lives only there.
#
#   cutover.sh <config> preflight          checks only, changes nothing
#   cutover.sh <config> run                preflight, final delta sync, DNS flip, post-flip checks, rollback on failure
#   cutover.sh <config> pages <ip> [-k]    page checks through one IP, changes nothing (-k for the bare origin)
#
# Exit: 0 live on the new host | 1 preflight failed, nothing changed
#       2 sync, build or origin check failed, DNS untouched
#       3 post-flip checks failed, DNS rolled back to the old host | 4 rollback failed, needs a human now
#
# Config file (sourced, holds no secrets):
#   DOMAIN=<domain>                 canonical host is the apex; www must 301 to it (swap the www check if not)
#   ZONE_ID=<zone_id>
#   NEW_IP=<new-origin-ip>
#   OLD_IP=<old-origin-ip>
#   NEW_SSH=<ssh-user>@<new-server> key-based SSH to the new server
#   MIG=<private dir on the new server, outside the web root>
#   DOCROOT=<docroot on the new server>
#   EXPORTER=<exporter filename on the old host>
#   SINCE=<epoch of the first full pull, minus 3600>
#   MARKER=<content of .well-known/origin-marker.txt written by build-app.sh>
#   EXPECTED_MX=<substring of the MX host, e.g. google.com>
#   REGISTRY_NS=<one authoritative server of the TLD: dig +short NS <tld>.>
#   PAGES="/ /<top-landing-page>/ /<service-page>/ /<blog-post>/ /sitemap_index.xml /robots.txt"
#   TEMP_DOMAIN_RE='cloudwaysapps\.com'   the new host's temporary domain; must not appear in any page
#   HOST_AFTER_BUILD=<optional local command: reset file ownership to the app user, purge the host page cache>
# On the new server, $MIG/mig.env holds the env that pull-site.sh and build-app.sh read.
# Env here: CF_API_TOKEN (zone read, zone settings read, DNS edit on this zone).
set -u

CONF=${1:?usage: cutover.sh <config> preflight|run|pages <ip> [-k]}
MODE=${2:?usage: cutover.sh <config> preflight|run|pages <ip> [-k]}
# shellcheck disable=SC1090
. "$CONF"
HERE=$(cd "$(dirname "$0")" && pwd)
UA="Mozilla/5.0 (Macintosh) cutover-check"
TMP=$(mktemp -d)

say() { echo "$(date -u +%H:%M:%S) $*"; }
cfj() { python3 "$HERE/cf-api.py" "$@"; }
remote() { ssh -o BatchMode=yes "$NEW_SSH" "$@"; }
remote_env() { remote "set -a; . $MIG/mig.env; set +a; $*"; }
FAILS=0
check() {
  if "${@:2}"; then say "ok   $1"; else say "FAIL $1"; FAILS=$((FAILS + 1)); fi
}

CF_NS=$(cfj GET "/zones/$ZONE_ID" | jq -r '.result.name_servers[0] // empty')

zone_active() { [ "$(cfj GET "/zones/$ZONE_ID" | jq -r .result.status)" = active ]; }
# A token without SSL read scope gets error 9109 on /ssl/*; the zone's ssl setting carries the edge certificate status.
edge_cert_active() { cfj GET "/zones/$ZONE_ID/settings/ssl" | jq -e '.result.certificate_status == "active"' >/dev/null; }
registry_on_cf() { dig +norec +time=5 @"$REGISTRY_NS" "$DOMAIN" NS | grep -q 'ns.cloudflare.com'; }
resolvers_on_cf() {
  local r
  for r in one.one.one.one dns.google dns.quad9.net; do
    dig +short +time=5 @"$r" "$DOMAIN" NS | grep -q cloudflare || return 1
  done
}
a_records_are() {
  local n
  for n in "$DOMAIN" "www.$DOMAIN"; do
    [ "$(cfj GET "/zones/$ZONE_ID/dns_records?type=A&name=$n" | jq -r '.result[0].content')" = "$1" ] || return 1
  done
}
mx_on_cf() { [ -n "$CF_NS" ] && dig +short @"$CF_NS" "$DOMAIN" MX | grep -q "$EXPECTED_MX"; }
exporter_ok() {
  remote "curl -sS --fail --resolve $DOMAIN:443:$OLD_IP -H @$MIG/token.hdr -A site-migration 'https://$DOMAIN/$EXPORTER?m=info' | jq -e .php >/dev/null"
}
new_app_ok() { remote "cd $DOCROOT && wp core is-installed"; }

# Fetch every check page through IP $1 ("-k" as $2 skips certificate checks, for the bare origin).
pages_ok() {
  local ip=$1 k=${2:-} p code
  for p in $PAGES; do
    code=$(curl -s $k --max-time 60 --resolve "$DOMAIN:443:$ip" -A "$UA" -o "$TMP/b" -w '%{http_code}' "https://$DOMAIN$p?cutover=$RANDOM")
    [ "$code" = 200 ] || { say "     $p -> HTTP $code"; return 1; }
    if grep -qE "$TEMP_DOMAIN_RE" "$TMP/b"; then say "     $p still references the temporary domain"; return 1; fi
  done
  [ "$(curl -s $k --max-time 30 --resolve "$DOMAIN:443:$ip" "https://$DOMAIN/.well-known/origin-marker.txt")" = "$MARKER" ] \
    || { say "     origin marker missing (not served by the new host)"; return 1; }
  code=$(curl -s $k --max-time 30 --resolve "www.$DOMAIN:443:$ip" -o /dev/null -w '%{http_code} %{redirect_url}' "https://www.$DOMAIN/")
  [ "$code" = "301 https://$DOMAIN/" ] || { say "     www -> $code"; return 1; }
}

# $1 = IP, $2 = proxied true|false. Assumes A records for both apex and www (adapt if www is a CNAME).
flip() {
  local n id ttl=1
  [ "$2" = false ] && ttl=60
  for n in "$DOMAIN" "www.$DOMAIN"; do
    id=$(cfj GET "/zones/$ZONE_ID/dns_records?type=A&name=$n" | jq -r '.result[0].id')
    cfj PATCH "/zones/$ZONE_ID/dns_records/$id" "{\"content\":\"$1\",\"proxied\":$2,\"ttl\":$ttl}" | jq -e .success >/dev/null || return 1
  done
}

host_after_build() {
  if [ -n "${HOST_AFTER_BUILD:-}" ]; then
    bash -c "$HOST_AFTER_BUILD" && say "ok   host post-build hook" || say "WARN host post-build hook failed"
  else
    say "WARN no HOST_AFTER_BUILD: reset file ownership and purge the host cache by hand after cutover"
  fi
}

if [ "$MODE" = pages ]; then
  pages_ok "${3:?ip}" "${4:-}" && say "pages OK via $3" && exit 0
  say "pages FAILED via $3"; exit 1
fi

say "=== $DOMAIN cutover: $MODE ==="
check "Cloudflare zone active" zone_active
check "Cloudflare edge certificate active" edge_cert_active
check "TLD registry delegates to Cloudflare" registry_on_cf
check "public resolvers see Cloudflare NS" resolvers_on_cf
check "A @/www still on the old host (not flipped yet)" a_records_are "$OLD_IP"
check "MX served by Cloudflare" mx_on_cf
check "new app answers wp-cli" new_app_ok
check "exporter on the old host reachable from the new server" exporter_ok
if [ $FAILS -gt 0 ]; then say "PREFLIGHT FAILED ($FAILS), nothing changed"; exit 1; fi
if [ "$MODE" = preflight ]; then say "PREFLIGHT OK"; exit 0; fi
[ "$MODE" = run ] || { say "unknown mode $MODE"; exit 1; }

say "--- final sync: DB + files changed since epoch $SINCE"
remote_env "$MIG/pull-site.sh $EXPORTER $SINCE" > "$TMP/pull.log" 2>&1
rc=$?
sed 's/^/     /' "$TMP/pull.log"
if [ $rc -ne 0 ] || ! grep -q '^verify: OK' "$TMP/pull.log"; then say "FAIL final pull, DNS untouched (site still on the old host)"; exit 2; fi

remote_env "$MIG/build-app.sh $MIG/latest delta" > "$TMP/build.log" 2>&1
rc=$?
sed 's/^/     /' "$TMP/build.log"
if [ $rc -ne 0 ] || ! grep -q 'row counts match' "$TMP/build.log" \
   || ! grep -q "home https://$DOMAIN | blog_public 1 |" "$TMP/build.log"; then
  say "FAIL build/import on the new host, DNS untouched (site still on the old host)"; exit 2
fi

host_after_build

if ! pages_ok "$NEW_IP" -k; then say "FAIL new origin checks, DNS untouched (site still on the old host)"; exit 2; fi
say "ok   new origin serves all check pages"

say "--- DNS flip: @ + www -> $NEW_IP, proxied"
if ! flip "$NEW_IP" true; then
  say "FAIL flip API call, rolling back"
  flip "$OLD_IP" false && { say "rolled back to the old host"; exit 3; }
  say "ROLLBACK FAILED"; exit 4
fi
sleep 30
EDGE=$(dig +short @"$CF_NS" "$DOMAIN" A | head -1)
ok=0
for attempt in 1 2 3; do
  # Proxied answer must be a Cloudflare edge IP, never either origin.
  if [ -n "$EDGE" ] && [ "$EDGE" != "$NEW_IP" ] && [ "$EDGE" != "$OLD_IP" ] && pages_ok "$EDGE"; then ok=1; break; fi
  say "     post-flip check attempt $attempt failed (edge $EDGE)"
  sleep 30
  EDGE=$(dig +short @"$CF_NS" "$DOMAIN" A | head -1)
done
if [ $ok -ne 1 ]; then
  say "FAIL post-flip checks through Cloudflare, rolling back to the old host"
  if flip "$OLD_IP" false && a_records_are "$OLD_IP"; then say "rolled back: @/www -> $OLD_IP (DNS-only)"; exit 3; fi
  say "ROLLBACK FAILED"; exit 4
fi
say "ok   live through Cloudflare edge $EDGE -> new host"

res=$(remote "curl -sS --resolve $DOMAIN:443:$OLD_IP -H @$MIG/token.hdr -A site-migration 'https://$DOMAIN/$EXPORTER?m=selfdelete'")
code=$(remote "curl -s -o /dev/null -w '%{http_code}' --resolve $DOMAIN:443:$OLD_IP 'https://$DOMAIN/$EXPORTER'")
say "exporter on the old host: $res, now HTTP $code"
say "DONE: $DOMAIN is served from the new host"
exit 0
