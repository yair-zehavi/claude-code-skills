#!/usr/bin/env bash
# Read-only fleet audit for a plugin advisory: the installed version of one plugin and
# the administrators on every site, through the WordPress REST API.
#
# Usage: fleet-advisory-audit.sh <plugin-folder> <affected-versions> <site.env>...
#   plugin-folder      folder name under wp-content/plugins, e.g. elementor
#   affected-versions  comma-separated exact versions, e.g. 4.3.0,4.3.1
#   site.env           one per site, shell-sourceable, defining:
#                        WP_BASE_URL       https://example.com/wp-json (with /wp-json)
#                        WP_USERNAME       an administrator
#                        WP_APP_PASSWORD   an application password, quoted
#                        CDN_BYPASS_HEADER_NAME / CDN_BYPASS_HEADER_VALUE (optional)
#
# Credentials reach curl as a config on stdin (-K -), never in argv or a command string.
# Output: site, version, plugin status, admin count, newest admin registration, verdict.
# "?" in the admin columns usually means the CDN blocks /wp/v2/users: check over SSH.
set -uo pipefail

if [ $# -lt 3 ]; then sed -n '2,16p' "$0"; exit 2; fi
plugin="$1"; affected=",$2,"; shift 2

esc() { printf '%s' "$1" | sed 's/[\\"]/\\&/g'; }

curl_cfg() {
  printf 'user = "%s"\n' "$(esc "$WP_USERNAME:$WP_APP_PASSWORD")"
  if [ -n "${CDN_BYPASS_HEADER_NAME:-}" ]; then
    printf 'header = "%s: %s"\n' "$CDN_BYPASS_HEADER_NAME" "$(esc "${CDN_BYPASS_HEADER_VALUE:-}")"
  fi
}

wp_get() { curl_cfg | curl -sS --max-time 45 -K - "${WP_BASE_URL%/}$1" 2>/dev/null; }

printf '%-40s %-10s %-9s %-6s %-11s %s\n' SITE VERSION STATUS ADMINS NEWEST VERDICT

for envf in "$@"; do
  (
    WP_BASE_URL=""
    set -a; . "$envf" 2>/dev/null; set +a
    if [ -z "$WP_BASE_URL" ]; then
      printf '%-40s %s\n' "$envf" "no WP_BASE_URL in env file"; exit 0
    fi
    case "$WP_BASE_URL" in
      */wp-json|*/wp-json/) ;;
      *) printf '%-40s %s\n' "$WP_BASE_URL" "WP_BASE_URL must end in /wp-json"; exit 0 ;;
    esac

    vs=$(wp_get "/wp/v2/plugins?_fields=plugin,status,version" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("ERR ERR"); sys.exit()
if not isinstance(d, list):
    print("ERR ERR"); sys.exit()
prefix = sys.argv[1] + "/"
for p in d:
    if p.get("plugin", "").startswith(prefix):
        print(p.get("version", "?"), p.get("status", "?")); sys.exit()
print("none -")
' "$plugin")
    ver="${vs%% *}"; status="${vs##* }"

    admins=$(wp_get "/wp/v2/users?roles=administrator&context=edit&per_page=100&_fields=id,registered_date" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("? ?"); sys.exit()
if not isinstance(d, list):
    print("? ?"); sys.exit()
dates = sorted(u.get("registered_date", "")[:10] for u in d)
print(len(d), dates[-1] if dates else "-")
')
    count="${admins%% *}"; newest="${admins##* }"

    case "$ver" in
      ERR)  verdict="REST unreachable or auth failed (CDN challenge? wrong credential?)" ;;
      none) verdict="plugin not installed" ;;
      *)    if [ "${affected#*,$ver,}" != "$affected" ]; then verdict="AFFECTED: update now"
            else verdict="not in affected list"; fi ;;
    esac

    printf '%-40s %-10s %-9s %-6s %-11s %s\n' "${WP_BASE_URL%/wp-json*}" "$ver" "$status" "$count" "$newest" "$verdict"
  )
done
