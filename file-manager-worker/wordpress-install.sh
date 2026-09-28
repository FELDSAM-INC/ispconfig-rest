#!/bin/sh
set -eu
[ "$(id -u)" = 0 ] || exit 1
worker_source=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
# Debian/Ubuntu ISPConfig webservers. No fallback to unjailed PHP execution.
if ! command -v bwrap >/dev/null || ! command -v mysql >/dev/null || ! command -v mysqldump >/dev/null; then
    command -v apt-get >/dev/null || { echo 'WordPress requires bubblewrap 0.9+, PHP CLI and MySQL client tools.' >&2; exit 1; }
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends bubblewrap default-mysql-client ca-certificates
fi
bwrap --help | grep -q -- --bind-fd || { echo 'WordPress requires bubblewrap with --bind-fd and --disable-userns.' >&2; exit 1; }
bwrap --help | grep -q -- --disable-userns || exit 1
install -d -m 0755 -o root -g root /usr/local/share/ispconfig-rest-wordpress
install -d -m 0711 -o root -g root /var/lib/ispcp-files/wordpress
install -m 0644 -o root -g root "$worker_source/wordpress-tools.py" /usr/local/share/ispconfig-rest-wordpress/
install -m 0600 -o root -g root "$worker_source/wordpress-sandbox.py" "$worker_source/wordpress.php" "$worker_source/WordPressWorker.php" /usr/local/lib/ispconfig-rest-file-manager-worker/
install -m 0600 -o root -g root "$worker_source/../app/Support/WordPressPolicy.php" "$worker_source/../app/Support/WebDomainAutoalias.php" /usr/local/lib/ispconfig-rest-file-manager-worker/
# Supply a root-owned empty WP-CLI configuration. Workdir is /tool, never the site.
printf '{}\n' > /usr/local/share/ispconfig-rest-wordpress/empty.yml
chmod 0644 /usr/local/share/ispconfig-rest-wordpress/empty.yml
python3 - <<'PY'
import hashlib,os,pathlib,tempfile,urllib.request
base=pathlib.Path('/usr/local/share/ispconfig-rest-wordpress')
expected='ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c'
target=base/'wp-cli.phar'
if target.is_file() and not target.is_symlink() and hashlib.sha256(target.read_bytes()).hexdigest()==expected:
    os.chown(target,0,0);os.chmod(target,0o644)
else:
    with urllib.request.urlopen('https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar',timeout=60) as response:
        data=response.read(32*1024*1024+1)
    if len(data)>32*1024*1024 or hashlib.sha256(data).hexdigest()!=expected:
        raise SystemExit('WP-CLI download checksum mismatch. WordPress runtime not enabled.')
    fd,name=tempfile.mkstemp(prefix='.wp-cli-',dir=base)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno());os.fchmod(out.fileno(),0o644)
        os.replace(name,target)
    finally:
        if os.path.exists(name):os.unlink(name)
PY
cat > /etc/cron.d/ispconfig-rest-wordpress <<'CRON'
* * * * * root /usr/bin/php /usr/local/lib/ispconfig-rest-file-manager-worker/wordpress.php
CRON
chmod 0644 /etc/cron.d/ispconfig-rest-wordpress
echo 'Installed WordPress runtime (WP-CLI 2.12.0). Commands fail closed if user namespaces or the matching PHP CLI are unavailable.'
