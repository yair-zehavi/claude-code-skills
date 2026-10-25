#!/usr/bin/env bash
# Pull a site from the old host's exporter into a private run dir on the NEW server.
# Runs on the new server. Every request is pinned to the old origin IP with --resolve,
# so the same command keeps working after the DNS flip.
#
# Usage: pull-site.sh <exporter-filename> [since-epoch]
#   since-epoch = delta run: only files modified after it. The DB is pulled again unless PULL_DB=0.
# Env:
#   DOMAIN   the site's domain, e.g. example.com
#   OLD_IP   the old host's origin IP
#   MIG      private dir outside the web root; holds token.hdr ("X-Mig-Token: <token>", mode 600)
set -euo pipefail
umask 077   # run dirs hold a full DB dump and the source wp-config.php
: "${DOMAIN:?set DOMAIN}" "${OLD_IP:?set OLD_IP}" "${MIG:?set MIG}"
URL="https://$DOMAIN/${1:?exporter filename}"
SINCE="${2:-}"
TAG=$(date -u +%Y%m%dT%H%M%SZ)
OUT=$MIG/run-$TAG
mkdir -p "$OUT/site"

get() { curl -sS --fail --retry 2 --retry-delay 20 --resolve "$DOMAIN:443:$OLD_IP" -H @"$MIG/token.hdr" -A "Mozilla/5.0 site-migration" "$@"; }

get "$URL?m=info" > "$OUT/info.json"
echo "info: $(jq -r '"php \(.php) mysql \(.mysql) prefix \(.prefix) tables \(.tables|length) server_time \(.time)"' "$OUT/info.json")"

get "$URL?m=list${SINCE:+&since=$SINCE}" > "$OUT/list.tsv"
echo "list: $(wc -l < "$OUT/list.tsv") files, $(awk -F'\t' '{s+=$2} END{printf "%.1f MB", s/1e6}' "$OUT/list.tsv")"

if [ -z "$SINCE" ] || [ "${PULL_DB:-1}" = "1" ]; then
  get "$URL?m=db" > "$OUT/db.sql"
  tail -n1 "$OUT/db.sql" | grep -qx -- '-- mig-dump-complete' || { echo "DB dump incomplete"; exit 1; }
  echo "db: $(du -h "$OUT/db.sql" | cut -f1), $(grep -c '^CREATE TABLE' "$OUT/db.sql") tables"
fi

t0=$(date +%s)
get "$URL?m=files${SINCE:+&since=$SINCE}" | tar -xf - -C "$OUT/site"
echo "files: extracted in $(( $(date +%s) - t0 ))s"

# Verify extracted files against the manifest by path and size (GNU find).
cd "$OUT/site"
find . -type f -printf '%P\t%s\n' | sort > "$OUT/got.tsv"
cut -f1,2 "$OUT/list.tsv" | sort > "$OUT/want.tsv"
if diff -q "$OUT/want.tsv" "$OUT/got.tsv" >/dev/null; then
  echo "verify: OK, $(wc -l < "$OUT/got.tsv") files match manifest (path+size)"
else
  echo "verify: MISMATCH"; diff "$OUT/want.tsv" "$OUT/got.tsv" | head -20
fi
ln -sfn "$OUT" "$MIG/latest"
echo "run dir: $OUT"
