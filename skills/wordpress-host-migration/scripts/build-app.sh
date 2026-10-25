#!/usr/bin/env bash
# Build (or refresh) the new app from a pull-site.sh run. Runs on the new server as the master/shell user.
# Usage: build-app.sh <run-dir>          full: mirror files (--delete), merge config + .htaccess, import DB
#        build-app.sh <run-dir> delta    delta: copy changed files only, import DB if the run has one
# Env:
#   DOCROOT     the new app's docroot as seen from this shell, e.g. /home/master/applications/<app>/public_html
#   MIG         private dir outside the web root (same as pull-site.sh); holds merge-wp-config.php
#   OLD_ROOT    the old host's absolute docroot (WP Rocket hardcodes it in advanced-cache.php)
#   FPM_ROOT    the docroot path PHP-FPM sees on the new host (on Cloudways: /home/<server>.cloudwaysapps.com/<app>/public_html)
#   OBJECT_CACHE_PLUGIN  optional: slug of the new host's object-cache plugin to keep and re-activate
#   MARKER      text written to .well-known/origin-marker.txt so checks can prove which host answered
set -euo pipefail
: "${DOCROOT:?}" "${MIG:?}" "${OLD_ROOT:?}" "${FPM_ROOT:?}" "${MARKER:?}"
RUN=$(readlink -f "${1:?run dir}")
MODE=${2:-full}
cd "$DOCROOT"

# First run only: keep the new host's own config files, drop the fresh-install tables.
if [ ! -d "$MIG/host-orig" ]; then
  mkdir -p "$MIG/host-orig"
  cp -a wp-config.php .htaccess "$MIG/host-orig/"
  [ -f wp-salt.php ] && cp -a wp-salt.php "$MIG/host-orig/"
  wp db clean --yes --quiet
  echo "fresh-install tables dropped, host originals saved"
fi

DEL=""
[ "$MODE" = full ] && DEL="--delete"
EXCL=()
[ -n "${OBJECT_CACHE_PLUGIN:-}" ] && EXCL=(--exclude="/wp-content/plugins/$OBJECT_CACHE_PLUGIN/")
# --omit-dir-times: as the master user, setting times on app-owned dirs fails with exit 23.
rsync -a --omit-dir-times $DEL --chmod=D775,F664 \
  --exclude=/wp-config.php --exclude=/wp-salt.php --exclude=/.htaccess \
  --exclude=/wp-content/object-cache.php ${EXCL[@]+"${EXCL[@]}"} \
  --exclude=/wp-content/cache/ --exclude=/wp-content/uploads/.htaccess \
  "$RUN/site/" "$DOCROOT/"
if [ -f wp-content/advanced-cache.php ]; then
  sed -i "s#$OLD_ROOT#$FPM_ROOT#g" wp-content/advanced-cache.php
fi
echo "files synced ($MODE)"

if [ "$MODE" = full ]; then
  php "$MIG/merge-wp-config.php" "$MIG/host-orig/wp-config.php" "$RUN/site/wp-config.php" "$DOCROOT/wp-config.php" "$DOCROOT/wp-salt.php"
  # Source rules first, then the host's WordPress block. An nginx-only source has no WordPress block,
  # and without one every permalink 404s on Apache. Read the source rules before this: they were inert on nginx.
  { [ -f "$RUN/site/.htaccess" ] && cat "$RUN/site/.htaccess"; printf '\n\n'; sed -n '/^# BEGIN WordPress/,/^# END WordPress/p' "$MIG/host-orig/.htaccess"; } > "$DOCROOT/.htaccess"
  if [ ! -f wp-content/uploads/.htaccess ]; then
    cat > wp-content/uploads/.htaccess <<'EOF'
# Security hardening: block PHP execution in uploads
<Files "*.php">
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
</Files>
EOF
  fi
  echo ".htaccess merged, uploads PHP block in place"
fi

if [ -f "$RUN/db.sql" ]; then
  wp db import "$RUN/db.sql" --quiet
  # Row count per table: from the dump (one row per line starting with "(") vs the live DB.
  awk '/^INSERT INTO `/{t=$3; gsub(/`/,"",t)} /^\(/{n[t]++} END{for(k in n) print k"\t"n[k]}' "$RUN/db.sql" | sort > "$RUN/rows-dump.tsv"
  : > "$RUN/rows-db.tsv"
  for t in $(cut -f1 "$RUN/rows-dump.tsv"); do
    printf '%s\t%s\n' "$t" "$(wp db query "SELECT COUNT(*) FROM \`$t\`" --skip-column-names)" >> "$RUN/rows-db.tsv"
  done
  if diff -q "$RUN/rows-dump.tsv" "$RUN/rows-db.tsv" >/dev/null; then
    echo "db imported: row counts match the dump for $(wc -l < "$RUN/rows-dump.tsv") non-empty tables"
  else
    echo "db row count MISMATCH"; diff "$RUN/rows-dump.tsv" "$RUN/rows-db.tsv" | head || true
  fi
  [ -n "${OBJECT_CACHE_PLUGIN:-}" ] && wp plugin activate "$OBJECT_CACHE_PLUGIN" --quiet
  wp cache flush --quiet
  wp elementor flush-css 2>/dev/null || true
  rm -rf wp-content/cache/wp-rocket wp-content/cache/min wp-content/cache/busting
  echo "object cache, Elementor CSS and WP Rocket cache cleared"
fi
mkdir -p .well-known
echo "$MARKER" > .well-known/origin-marker.txt
echo "siteurl $(wp option get siteurl) | home $(wp option get home) | blog_public $(wp option get blog_public) | core $(wp core version) | active plugins $(wp plugin list --status=active --field=name | wc -l)"
