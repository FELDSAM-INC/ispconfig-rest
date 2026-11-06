#!/usr/bin/env bash
# The manager's privileged migrations end to end (1.4.4): a runtime database user without DDL rights, as since 1.4.0,
# and `ispconfig-rest extension:update hosting` with a pending extension migration. Real MariaDB, composer, artisan
# and Git; the official GitHub repositories are served from this working tree by git daemon. Disposable container.
set -euo pipefail
root=$(cd "$(dirname "$0")/../../.." && pwd)
hosting=${HOSTING_DIR:-$root/extensions/hosting}
[[ -f "$hosting/extension.json" ]] || { echo "Set HOSTING_DIR to an ispconfig-rest-hosting checkout." >&2; exit 1; }
image=ispcp-privileged-migration-test
docker build -q -t "$image" - <<'DOCKERFILE' >/dev/null
FROM ubuntu:24.04
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends git git-daemon-run php-cli php-mysql php-mbstring \
    php-xml php-curl php-zip php-sqlite3 php-bcmath php-intl unzip composer mariadb-server sudo ca-certificates && rm -rf /var/lib/apt/lists/*
DOCKERFILE
docker run --rm -i -v "$root:/repo:ro" -v "$hosting:/hosting:ro" "$image" bash -s <<'SCRIPT'
set -euo pipefail
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; exit 1; fi; }
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
mariadbd --user=mysql --skip-networking=0 --bind-address=127.0.0.1 > /tmp/mariadb.log 2>&1 &
for attempt in $(seq 1 60); do mysqladmin ping --silent && break; sleep 1; done
mysql -e "CREATE DATABASE dbispconfig; CREATE USER 'ispconfig'@'localhost' IDENTIFIED BY 'runtime-pass';
          GRANT SELECT, INSERT, UPDATE, DELETE ON dbispconfig.* TO 'ispconfig'@'localhost';"

# Published "releases": the tracked files of the working trees, committed into bare origins served by git daemon.
publish() { # SOURCE NAME
  mkdir -p /tmp/publish/$2 && (cd "$1" && git ls-files -z --cached | xargs -0 -I{} cp --parents -P {} /tmp/publish/$2/ 2>/dev/null || true)
  git -C /tmp/publish/$2 init -q -b main && git -C /tmp/publish/$2 add -A && git -C /tmp/publish/$2 -c user.name=t -c user.email=t@t commit -qm release
  git clone -q --bare /tmp/publish/$2 /srv/git/$2.git
}
git config --global --add safe.directory '*'
publish /repo core
publish /hosting hosting
git daemon --base-path=/srv/git --export-all --reuseaddr --detach --listen=127.0.0.1
for attempt in $(seq 1 20); do git ls-remote git://127.0.0.1/core.git >/dev/null 2>&1 && break; sleep .5; done
for user in root apiuser; do home=$([ $user = root ] && echo /root || echo /home/apiuser)
  [ $user = root ] || useradd -m -s /bin/bash apiuser
  printf '[url "git://127.0.0.1/core.git"]\n\tinsteadOf = https://github.com/FELDSAM-INC/ispconfig-rest.git\n[url "git://127.0.0.1/hosting.git"]\n\tinsteadOf = https://github.com/FELDSAM-INC/ispconfig-rest-hosting.git\n' > $home/.gitconfig
  chown $user: $home/.gitconfig
done

# The runtime installation as its unprivileged owner, with the limited database login.
install -d -o apiuser -g apiuser /srv/api
sudo -u apiuser git clone -q https://github.com/FELDSAM-INC/ispconfig-rest.git /srv/api
sudo -u apiuser git clone -q https://github.com/FELDSAM-INC/ispconfig-rest-hosting.git /srv/api/extensions/hosting
sudo -u apiuser env -C /srv/api COMPOSER_HOME=/home/apiuser/.composer composer install -q --no-dev --no-interaction --no-progress
printf '%s\n' APP_ENV=production "APP_KEY=base64:$(head -c32 /dev/urandom | base64)" DB_CONNECTION=mysql DB_HOST=localhost DB_PORT=3306 \
  DB_DATABASE=dbispconfig DB_USERNAME=ispconfig DB_PASSWORD=runtime-pass CACHE_STORE=array SESSION_DRIVER=array | sudo -u apiuser tee /srv/api/.env >/dev/null
check 'the runtime login cannot create tables' "! sudo -u apiuser env -C /srv/api php artisan migrate --force >/dev/null 2>&1"

mkdir -p /etc/ispconfig-rest
cat > /etc/ispconfig-rest/install.conf <<CONF
INSTALL_DIR="/srv/api"
RUN_USER="apiuser"
SERVE_MODE="none"
BRANCH="main"
RELEASE_REF="refs/heads/main"
PHP_BIN="/usr/bin/php"
DB_HOST="localhost"
DB_PORT="3306"
DB_NAME="dbispconfig"
EXTENSIONS="hosting"
EXTENSION_HOSTING_REPOSITORY="https://github.com/FELDSAM-INC/ispconfig-rest-hosting.git"
EXTENSION_HOSTING_REF="refs/heads/main"
CONF
chmod 600 /etc/ispconfig-rest/install.conf
install -m 0755 /repo/bin/ispconfig-rest /usr/local/bin/ispconfig-rest

# Pending core and hosting migrations: run with the maintenance login of /etc/mysql/debian.cnf (root over the socket).
ispconfig-rest extension:update hosting > /tmp/update.log 2>&1 || { cat /tmp/update.log; exit 1; }
check 'the update reports the privileged migrations' "grep -q 'Database migrations applied' /tmp/update.log"
check 'core and hosting tables exist' "[ \$(mysql -N dbispconfig -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='dbispconfig' AND table_name IN ('api_keys','api_web_waf_clients','api_web_waf_bans')\") = 3 ]"
check 'nothing is pending for the runtime user' "sudo -u apiuser env -C /srv/api php artisan migrate:status --pending=1 >/dev/null"
check 'the runtime login still cannot create tables' "! mysql -uispconfig -pruntime-pass dbispconfig -e 'CREATE TABLE probe (id int)' 2>/dev/null"
check 'the private stage is gone' "[ -z \"\$(ls -d /root/ispconfig-rest-migrate.* 2>/dev/null)\" ]"

# Nothing pending: no privileged step on the next update.
ispconfig-rest extension:update hosting > /tmp/update2.log 2>&1 || { cat /tmp/update2.log; exit 1; }
check 'a second update migrates nothing' "! grep -q 'privileged login' /tmp/update2.log"

# A local commit in the runtime extension checkout never runs as root.
mysql -e "DELETE FROM dbispconfig.migrations WHERE migration LIKE '%create_web_waf_client_tables'; DROP TABLE dbispconfig.api_web_waf_clients, dbispconfig.api_web_waf_bans;
          ALTER TABLE dbispconfig.api_web_waf_events DROP INDEX api_waf_events_site_ip;"
sudo -u apiuser sh -c 'cd /srv/api/extensions/hosting && echo "<?php exit(9);" > database/migrations/2026_11_07_000002_untrusted.php && git add . && git -c user.name=x -c user.email=x@x commit -qm untrusted'
check 'unverified extension code is refused' "! ispconfig-rest extension:update hosting --branch main >/tmp/update3.log 2>&1 || grep -q 'Migrations failed' /tmp/update3.log"
echo 'PASS privileged migrations of the manager'
SCRIPT
