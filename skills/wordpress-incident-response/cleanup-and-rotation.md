# Cleanup and rotation

## Order

1. Evidence saved (see [hunt-commands.md](hunt-commands.md)): copies, metadata, hashes.
2. Edge block live and fan-out channel cut (SKILL.md phase 4).
3. Server-side relay handler and the injector removed.
4. Backdoors, `mu-plugins` first; then webshells, attacker folders and files in the
   docroot, uploads webshells and zero-byte probes, the attacker's Search Console
   verification file.
5. Rogue admins (after the owner confirms), unknown plugins and themes.
6. Defaced core files restored from a pristine copy of the same version.
7. Page cache and CDN cache purged; render sweep re-run.
8. Residue sweep: admins, recently added plugins and themes, checksum failures again.
9. Entry plugins patched or replaced.

## Keep a copy before you delete

```bash
EV="$HOME/ir-<site>-<YYYY-MM-DD>"
mkdir -p "$EV"
cp -p <suspect-file> "$EV"/              # -p keeps mtime and mode
sha256sum <suspect-file> >> "$EV/hashes.txt"
stat -c '%U %G %a %s %y %n' <suspect-file> >> "$EV/stat.txt"
```

Pull the folder to your evidence store and delete it from the server once the incident
is closed. Treat the copies as live malware: text only, never executed, never under a
web-served path.

## Files the server-level user cannot delete (shared hosts)

Observed on Cloudways in 2026; the same ownership model exists on other managed hosts.

**Why it happens.** Files written by the web app (an mu-plugin backdoor, a webshell
dropped through a form upload) are owned by the app's system user. Folders such as
`wp-content/mu-plugins` are often `755` and app-owned. The server-level SSH user is
only in the web group, and deleting a file needs write permission on its parent
folder, which that user lacks. `rm` fails with permission denied.

**Option A: the application-level SFTP user.** The host console exposes per-app
credentials that map to the app's own user, so `rm` works.
- Shell access is disabled for that user: SFTP only.
- The session is chrooted: `pwd` is `/` and the site is under `public_html/`.
- Do not use `sftp -b <file>` together with `sshpass`: batch mode has no TTY, the
  password is never sent, and the error looks exactly like a ban. Feed commands on
  stdin instead.
- Connect once, do all the work, disconnect. Repeated connections trip fail2ban for
  about 30 minutes.

```bash
app_sftp() {   # usage: app_sftp <app-sftp.env>   (sftp commands on stdin)
  ( set -a; . "$1"; set +a
    export SSHPASS="$SFTP_PASSWORD"
    sshpass -e sftp -o PreferredAuthentications=password -o PubkeyAuthentication=no \
      "$SFTP_USER@$SFTP_HOST" )
}

app_sftp <app-sftp.env> <<'EOF'
ls -la public_html/wp-content/mu-plugins
rm public_html/wp-content/mu-plugins/<backdoor-file>.php
ls -la public_html/wp-content/mu-plugins
bye
EOF
```

Better still: key auth for that user, no password at all.

**Option B: quarantine-rename with the server-level user.** If the folder's own parent
is group-writable (`wp-content` was `775`), rename the whole folder out of the load
path and recreate it empty:

```bash
cd "$WP_PATH/wp-content"
mv mu-plugins .mu-plugins.QUARANTINE
mkdir mu-plugins
```

Then put back any legitimate mu-plugins from a known-good copy. Note that renaming an
app-owned folder to a different parent is not allowed for a non-owner; renaming it in
place is.

**Option C: plugin folders.** `wp-content/plugins` is usually group-writable, so a
plugin folder can be renamed in place to a dot-prefixed name, which WordPress skips
when it lists plugins:

```bash
mv "$WP_PATH/wp-content/plugins/<slug>" "$WP_PATH/wp-content/plugins/.<slug>-old-<timestamp>"
```

The same move is how to swap in a fresh plugin copy when the in-place update fails on
app-owned files: stage the new version in a folder you own, move the old folder aside
with a dot-prefix, move the staged folder in.

**Agent caveat.** Claude Code's auto-mode safety filter refuses remote writes that look
like webshell deployment (upload a PHP file and request it) and bulk `mv`/`rm` of
shell-named files, even during legitimate cleanup. Read-only recon is not affected. Do
not rephrase the command to get past it: add a narrow allow rule for a reviewed
script, or give the human the exact one-liner.

## Removing a management child plugin fleet-wide

A compromised management dashboard can push plugins, themes, code and admin users to
every connected site. Remove the child plugin everywhere, then delete the dashboard.

`<management-child-plugin>` is, for example, the child plugin of your site-management
dashboard; `<child-option-prefix>` is the prefix of the option names it writes. List
those options first (hunt-commands section 8) and save them as evidence.

```bash
P=$(wp db prefix)
wp plugin deactivate <management-child-plugin>
wp db query "DELETE FROM ${P}options WHERE option_name LIKE '<child-option-prefix>%'"
wp plugin delete <management-child-plugin>
wp cache flush
```

Track the result per site: deleted, or deactivated with files left behind because they
are app-owned (finish those with Option A or C). Group the work by server, one session
per server.

## Cache purge

- Page-cache plugin: its own CLI or admin button (Breeze: `wp breeze purge --cache=all`),
  plus `wp cache flush` for the object cache.
- CDN: purge everything for the zone.
- Re-run the render sweep and the real-browser load afterwards.

## Patching the entry plugins

- Check status exactly: `wp plugin get <slug> --field=status`. Grepping `wp plugin
  status` for "active" also matches "Inactive".
- Copy the folder you are about to replace into a dated backup folder first. It is a
  one-minute rollback that keeps the day's data, unlike a full restore.
- Premium plugins: if `wp plugin update` claims "already updated" on an old build, the
  vendor updater is not serving it (expired license or a dead updater). Install the
  vendor zip with `wp plugin install <zip> --force`.
- Multi-year major jumps (observed: Gravity Forms 2.4 to 2.9) run database migrations
  that are not cleanly reversible. Stage them and get a go/no-go.

## Rotation

| What | Why | Notes |
|---|---|---|
| CDN account password, 2FA | If the account was the attacker's access, they can inject at the edge on every zone | Remove unknown members; enforce 2FA for all |
| Every CDN API token, including CLI OAuth grants | Tokens survive a password change | Re-issue scoped tokens; update every place that stores them |
| WP admin passwords, 2FA on every admin | Admin access is the easiest re-entry | Check that a 2FA plugin is installed at all |
| SSH and SFTP passwords | They may have been typed into compromised tooling or logs | Prefer moving to key auth and clearing the password |
| App-level SFTP credentials used during cleanup | Exposed to more tooling than usual during IR | Rotate after the incident |
| Secrets in sibling apps' `wp-config.php` | On a shared server with world-readable config files, a webshell that can run shell commands can read them | Needed when `open_basedir` and `disable_functions` cannot be confirmed |
| Any secret that leaked into chat, a log or tool output | Assume it is public | Some tools echo the code or command you pass them; never embed a password there |

## Secret-safe command patterns

```bash
# BAD: the secret is part of a string. zsh does not word-split an unquoted $CMD,
# runs the whole string as one command name and prints it in "command not found".
CMD="sshpass -p $PASS ssh $SSH_USER@$SSH_HOST uptime"; $CMD

# BAD: the password is in argv, readable by every user on the host via ps.
sshpass -p "$PASS" ssh "$SSH_USER@$SSH_HOST" uptime

# GOOD: a function reads the env file in a subshell; the secret travels in the environment.
remote() {   # usage: remote <server.env> '<remote command>'
  ( set -a; . "$1"; set +a
    export SSHPASS="$SSH_PASSWORD"
    sshpass -e ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no \
      "$SSH_USER@$SSH_HOST" "$2" )
}

# BEST: key auth, no password anywhere.
```

- **Inspecting a secrets file:** `sed 's/=.*/=<set>/' <file>.env` shows which keys are
  set without printing values.
- **Setting a rotated value:** the human runs a silent prompt in their own terminal.
  Example:

```bash
#!/usr/bin/env bash
# set-secret.sh <env-file> <VAR>: prompt silently, write VAR='value', mode 600
set -euo pipefail
f="$1"; var="$2"
read -rs -p "$var: " val; echo
case "$val" in *"'"*) echo "value contains a single quote; set it by hand" >&2; exit 1;; esac
umask 077
tmp=$(mktemp "$f.XXXXXX")
{ grep -v "^$var=" "$f" 2>/dev/null || true; printf "%s='%s'\n" "$var" "$val"; } > "$tmp"
mv "$tmp" "$f"
chmod 600 "$f"
```

- **Env file format:** values with spaces must be quoted or `source` fails (a label like
  `Two Words` unquoted breaks the whole file). Values containing `!`, `$` or `#` go in
  single quotes. Inline comments after a value break a naive `cut -d=`; strip them, or
  parse with grep and sed when a file is not shell-safe.
- **Hooks that block secret exposure** may also fire on documentation that contains
  the bad patterns above. Write such docs with a file-write tool, not a shell heredoc.

## Secure secret transfer

To hand secret files to another operator without a paid vault:

```bash
# sender: copy the files, blank per-person values in the copies, then bundle and encrypt
out=$(mktemp -d)
cp <files> "$out/"
tar czf creds.tgz -C "$out" . && rm -rf "$out"
gpg --symmetric --cipher-algo AES256 --output creds.tgz.gpg creds.tgz && rm creds.tgz
```

- Run `gpg` in a real terminal, not through an agent: it prompts for the passphrase,
  which then never reaches chat or logs. This is the same symmetric gpg (AES256) the
  backup dumps use.
- Send `creds.tgz.gpg` over any channel; it is ciphertext. Give the passphrase over a
  different channel (voice, SMS).
- gpg checks integrity on decrypt, so a tampered or truncated bundle fails with an
  error. `age -p` works the same way if both ends have `age`.

```bash
# receiver: decrypt into the secrets folder, then remove the bundle
gpg --output creds.tgz --decrypt creds.tgz.gpg
tar xzf creds.tgz -C <secrets-dir>/ && rm creds.tgz creds.tgz.gpg
chmod 600 <secrets-dir>/*
```

Do not reuse that passphrase. Never paste secret contents into chat or email.
