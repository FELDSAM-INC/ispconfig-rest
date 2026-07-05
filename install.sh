#!/usr/bin/env bash
#
# ISPConfig REST API — installer
#
# Installs the REST API onto an existing ISPConfig 3.3 server:
#   • reads the runtime DB credentials from ISPConfig's own config file;
#   • creates the API-owned api_keys table using a privileged (root) DB login,
#     the way ISPConfig's own installer does — so the runtime user never needs
#     CREATE rights;
#   • serves the app from a DEDICATED web-server vhost backed by a dedicated
#     php-fpm pool (the standard ISPConfig serving model), reusing ISPConfig's
#     panel SSL certificate and never touching ISPConfig's own vhosts;
#   • registers the `ispconfig-rest` manager in PATH for updates.
#
# Usage:
#   curl -sSL https://raw.githubusercontent.com/FELDSAM-INC/ispconfig-rest/main/install.sh | sudo bash
#   sudo ./install.sh                    # interactive
#   sudo ./install.sh --help             # all flags / ISPC_REST_* env vars

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults (overridable by flags / env / prompts)
# ---------------------------------------------------------------------------
REPO_URL="${ISPC_REST_REPO:-https://github.com/FELDSAM-INC/ispconfig-rest.git}"
BRANCH="${ISPC_REST_BRANCH:-main}"
INSTALL_DIR="${ISPC_REST_DIR:-/opt/ispconfig-rest}"
ISPCONFIG_CONFIG="${ISPC_REST_ISPCONFIG_CONFIG:-/usr/local/ispconfig/interface/lib/config.inc.php}"
ISPCONFIG_SSL_DIR="${ISPC_REST_SSL_DIR:-/usr/local/ispconfig/interface/ssl}"
PUBLIC_PORT="${ISPC_REST_PORT:-8090}"       # HTTPS port for the dedicated vhost
APP_URL="${ISPC_REST_URL:-}"
SERVE_MODE="${ISPC_REST_SERVE:-auto}"       # auto | apache | nginx | none
CREATE_ADMIN_KEY="${ISPC_REST_CREATE_KEY:-ask}"
NONINTERACTIVE="${ISPC_REST_NONINTERACTIVE:-0}"
SERVICE_NAME="ispconfig-rest"
STATE_DIR="/etc/ispconfig-rest"
MANAGER_PATH="/usr/local/bin/ispconfig-rest"
MIN_PHP="8.3"
PHP_BIN="${ISPC_REST_PHP:-php}"
FPM_SOCK="/run/${SERVICE_NAME}.sock"
RUN_USER="${ISPC_REST_USER:-}"              # defaults to the web-server user

# Runtime DB (default: read from ISPConfig config)
DB_HOST="" DB_PORT="" DB_NAME="" DB_USER="" DB_PASS="" DB_OVERRIDE=0
# Privileged DB login used ONLY to create api_keys (default: root via socket)
DB_ADMIN_USER="${ISPC_REST_DB_ADMIN_USER:-root}"
DB_ADMIN_PASS="${ISPC_REST_DB_ADMIN_PASS:-}"

# Populated by detection
DETECTED_WS="" WEB_USER="" FPM_POOL_DIR="" FPM_SERVICE=""

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
  C_RESET=$'\033[0m'; C_B=$'\033[1m'; C_G=$'\033[32m'; C_Y=$'\033[33m'; C_R=$'\033[31m'; C_C=$'\033[36m'
else C_RESET="" C_B="" C_G="" C_Y="" C_R="" C_C=""; fi
info()  { printf '%s\n' "${C_C}▸${C_RESET} $*"; }
ok()    { printf '%s\n' "${C_G}✓${C_RESET} $*"; }
warn()  { printf '%s\n' "${C_Y}!${C_RESET} $*" >&2; }
err()   { printf '%s\n' "${C_R}✗${C_RESET} $*" >&2; }
die()   { err "$*"; exit 1; }
step()  { printf '\n%s\n' "${C_B}== $* ==${C_RESET}"; }

usage() {
  cat <<EOF
ISPConfig REST API installer

Options (each also settable via an ISPC_REST_* env var):
  --dir PATH               install directory              (default: $INSTALL_DIR)
  --repo URL / --branch    git source                     (default: $BRANCH)
  --serve MODE             auto|apache|nginx|none          (default: auto)
  --port PORT              public HTTPS port for the vhost (default: $PUBLIC_PORT)
  --url URL                public APP_URL (default: https://<host>:PORT)
  --ispconfig-config PATH  ISPConfig config to read the runtime DB from
  --ssl-dir PATH           ISPConfig SSL dir (ispserver.crt/key/bundle)
  --db-host/-port/-name/-user/-pass   override the runtime DB credentials
  --db-admin-user NAME     privileged DB user for CREATE  (default: root)
  --db-admin-pass PASS     its password (default: empty -> unix-socket auth)
  --run-user NAME          php-fpm pool / owner user (default: web-server user)
  --create-key yes|no      mint an admin API key          (default: ask)
  --non-interactive        accept defaults, no prompts
  --php PATH               php CLI binary                  (default: php)
  -h, --help
EOF
}

# ---------------------------------------------------------------------------
# Prompt helpers
# ---------------------------------------------------------------------------
ask() {
  local __var="$1" __prompt="$2" __def="${3:-}" __ans
  if [ "$NONINTERACTIVE" = "1" ]; then printf -v "$__var" '%s' "$__def"; return; fi
  if [ -n "$__def" ]; then read -r -p "$__prompt [$__def]: " __ans </dev/tty || true
  else read -r -p "$__prompt: " __ans </dev/tty || true; fi
  printf -v "$__var" '%s' "${__ans:-$__def}"
}
ask_secret() {
  local __var="$1" __prompt="$2" __def="${3:-}" __ans
  if [ "$NONINTERACTIVE" = "1" ]; then printf -v "$__var" '%s' "$__def"; return; fi
  read -r -s -p "$__prompt${__def:+ [keep current]}: " __ans </dev/tty || true; echo
  printf -v "$__var" '%s' "${__ans:-$__def}"
}
confirm() {
  local __prompt="$1" __def="${2:-yes}" __ans
  if [ "$NONINTERACTIVE" = "1" ]; then [ "$__def" = "yes" ]; return; fi
  read -r -p "$__prompt [$([ "$__def" = yes ] && echo Y/n || echo y/N)]: " __ans </dev/tty || true
  __ans="${__ans:-$__def}"; case "${__ans,,}" in y|yes) return 0;; *) return 1;; esac
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) INSTALL_DIR="$2"; shift 2;;
    --repo) REPO_URL="$2"; shift 2;;
    --branch) BRANCH="$2"; shift 2;;
    --serve) SERVE_MODE="$2"; shift 2;;
    --port) PUBLIC_PORT="$2"; shift 2;;
    --url) APP_URL="$2"; shift 2;;
    --ispconfig-config) ISPCONFIG_CONFIG="$2"; shift 2;;
    --ssl-dir) ISPCONFIG_SSL_DIR="$2"; shift 2;;
    --db-host) DB_HOST="$2"; DB_OVERRIDE=1; shift 2;;
    --db-port) DB_PORT="$2"; DB_OVERRIDE=1; shift 2;;
    --db-name) DB_NAME="$2"; DB_OVERRIDE=1; shift 2;;
    --db-user) DB_USER="$2"; DB_OVERRIDE=1; shift 2;;
    --db-pass) DB_PASS="$2"; DB_OVERRIDE=1; shift 2;;
    --db-admin-user) DB_ADMIN_USER="$2"; shift 2;;
    --db-admin-pass) DB_ADMIN_PASS="$2"; shift 2;;
    --run-user) RUN_USER="$2"; shift 2;;
    --create-key) CREATE_ADMIN_KEY="$2"; shift 2;;
    --non-interactive) NONINTERACTIVE=1; shift;;
    --php) PHP_BIN="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) die "Unknown option: $1 (see --help)";;
  esac
done

# ---------------------------------------------------------------------------
# Detection helpers
# ---------------------------------------------------------------------------
detect_web_server() {
  if command -v apache2ctl >/dev/null 2>&1 || command -v apachectl >/dev/null 2>&1 || [ -d /etc/apache2 ]; then echo apache
  elif command -v nginx >/dev/null 2>&1 || [ -d /etc/nginx ]; then echo nginx
  else echo none; fi
}
detect_web_user() {
  if id www-data >/dev/null 2>&1; then echo www-data
  elif id apache >/dev/null 2>&1; then echo apache
  elif id nginx >/dev/null 2>&1; then echo nginx
  else echo www-data; fi
}
# Find a php-fpm >= MIN_PHP: sets FPM_POOL_DIR, FPM_SERVICE. Returns 1 if none.
detect_fpm() {
  local v
  for v in 8.5 8.4 8.3; do
    if [ -d "/etc/php/$v/fpm/pool.d" ]; then
      FPM_POOL_DIR="/etc/php/$v/fpm/pool.d"
      systemctl list-unit-files "php$v-fpm.service" >/dev/null 2>&1 && FPM_SERVICE="php$v-fpm" || FPM_SERVICE="php-fpm"
      return 0
    fi
  done
  if [ -d /etc/php-fpm.d ]; then FPM_POOL_DIR="/etc/php-fpm.d"; FPM_SERVICE="php-fpm"; return 0; fi
  return 1
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
step "Preflight checks"
[ "$(id -u)" = "0" ] || die "This installer must run as root (use sudo)."
command -v git >/dev/null 2>&1 || die "git is required but not installed."
command -v "$PHP_BIN" >/dev/null 2>&1 || die "php is required but not installed."

PHP_VER="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
[ "$("$PHP_BIN" -r 'echo version_compare(PHP_VERSION,"'"$MIN_PHP"'",">=")?1:0;')" = "1" ] \
  || die "PHP >= $MIN_PHP required, found $PHP_VER."
ok "PHP $PHP_VER"

MISSING_EXT=""
for ext in pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo; do
  "$PHP_BIN" -m | grep -qix "$ext" || MISSING_EXT="$MISSING_EXT $ext"
done
[ -z "$MISSING_EXT" ] || die "Missing PHP extensions:$MISSING_EXT"
ok "Required PHP extensions present"

if command -v composer >/dev/null 2>&1; then COMPOSER="composer"
else
  info "Composer not found — fetching a local copy"
  ( cd /tmp && "$PHP_BIN" -r "copy('https://getcomposer.org/installer','composer-setup.php');" \
    && "$PHP_BIN" composer-setup.php --quiet && rm -f composer-setup.php )
  install -m 0755 /tmp/composer.phar /usr/local/bin/composer; COMPOSER="composer"
fi
ok "Composer available"

DETECTED_WS="$(detect_web_server)"
[ "$SERVE_MODE" = "auto" ] && { case "$DETECTED_WS" in apache|nginx) SERVE_MODE="$DETECTED_WS";; *) SERVE_MODE="none";; esac; }
WEB_USER="$(detect_web_user)"
[ -z "$RUN_USER" ] && RUN_USER="$WEB_USER"

FPM_AVAILABLE=0
if detect_fpm; then FPM_AVAILABLE=1; ok "php-fpm: $FPM_SERVICE (pools in $FPM_POOL_DIR)"
else warn "php-fpm not detected — Apache will fall back to mod_php; nginx requires php-fpm."; fi
ok "Web server: $DETECTED_WS (user $WEB_USER)  →  serve mode: $SERVE_MODE"

[ "$SERVE_MODE" = "nginx" ] && [ "$FPM_AVAILABLE" = "0" ] && die "nginx mode needs php-fpm — install php${PHP_VER%.*}-fpm and re-run."

# ---------------------------------------------------------------------------
# Runtime DB credentials (from ISPConfig)
# ---------------------------------------------------------------------------
step "Runtime database credentials"
read_conf() { "$PHP_BIN" -r '$c=$argv[1]; if(!is_file($c)){exit(1);} $conf=[]; include $c; echo $conf["db_'"$1"'"] ?? "";' "$ISPCONFIG_CONFIG" 2>/dev/null || true; }
if [ "$DB_OVERRIDE" = "0" ] && [ -f "$ISPCONFIG_CONFIG" ]; then
  DB_HOST="$(read_conf host)"; DB_PORT="$(read_conf port)"; DB_NAME="$(read_conf database)"
  DB_USER="$(read_conf user)"; DB_PASS="$(read_conf password)"
  [ -n "$DB_NAME" ] && ok "Read from ISPConfig: $DB_USER@$DB_HOST/$DB_NAME" || warn "Could not parse $ISPCONFIG_CONFIG"
fi
ask        DB_HOST "Database host" "${DB_HOST:-localhost}"
ask        DB_PORT "Database port" "${DB_PORT:-3306}"
ask        DB_NAME "Database name" "${DB_NAME:-dbispconfig}"
ask        DB_USER "Runtime database user (CRUD)" "${DB_USER:-root}"
ask_secret DB_PASS "Runtime database password" "$DB_PASS"

# ---------------------------------------------------------------------------
# Deployment options
# ---------------------------------------------------------------------------
step "Deployment options"
ask INSTALL_DIR "Install directory" "$INSTALL_DIR"
ask PUBLIC_PORT "Public HTTPS port" "$PUBLIC_PORT"
HOSTNAME_FQDN="$(hostname -f 2>/dev/null || hostname)"
[ -n "$APP_URL" ] || APP_URL="https://${HOSTNAME_FQDN}:${PUBLIC_PORT}"
ask APP_URL "Public application URL" "$APP_URL"

# ---------------------------------------------------------------------------
# System user
# ---------------------------------------------------------------------------
step "System user"
if ! id "$RUN_USER" >/dev/null 2>&1; then
  useradd --system --home-dir "$INSTALL_DIR" --shell /usr/sbin/nologin "$RUN_USER" 2>/dev/null \
    || useradd --system --home-dir "$INSTALL_DIR" --shell /bin/false "$RUN_USER"
  ok "Created system user $RUN_USER"
else ok "Using existing user $RUN_USER"; fi

# ---------------------------------------------------------------------------
# Fetch code + dependencies
# ---------------------------------------------------------------------------
step "Fetching application code"
if [ -d "$INSTALL_DIR/.git" ]; then
  git -C "$INSTALL_DIR" fetch --depth 1 origin "$BRANCH"
  git -C "$INSTALL_DIR" checkout -f "$BRANCH"; git -C "$INSTALL_DIR" reset --hard "origin/$BRANCH"
else
  mkdir -p "$(dirname "$INSTALL_DIR")"
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi
chown -R "$RUN_USER":"$RUN_USER" "$INSTALL_DIR"
ok "Code at $INSTALL_DIR ($(git -C "$INSTALL_DIR" rev-parse --short HEAD))"

run_as() { sudo -u "$RUN_USER" env -C "$INSTALL_DIR" "$@"; }
step "Installing dependencies"
run_as env COMPOSER_HOME="$INSTALL_DIR/.composer" $COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress
ok "Dependencies installed"

# ---------------------------------------------------------------------------
# Environment file
# ---------------------------------------------------------------------------
step "Configuring environment"
ENV_FILE="$INSTALL_DIR/.env"
APP_KEY_LINE="APP_KEY="
if [ -f "$ENV_FILE" ] && grep -q '^APP_KEY=base64:' "$ENV_FILE"; then
  APP_KEY_LINE="$(grep '^APP_KEY=' "$ENV_FILE")"; info "Preserving existing APP_KEY"
fi
env_escape() { ISPC_REST_VAL="$1" "$PHP_BIN" -r '$v=(string)getenv("ISPC_REST_VAL"); $v=str_replace(["\\","\"","\$"],["\\\\","\\\"","\\\$"],$v); echo "\"".$v."\"";'; }
umask 077
cat > "$ENV_FILE" <<EOF
APP_NAME="ISPConfig REST API"
APP_ENV=production
$APP_KEY_LINE
APP_DEBUG=false
APP_URL=$(env_escape "$APP_URL")
APP_TIMEZONE=UTC

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=$(env_escape "$DB_HOST")
DB_PORT=$(env_escape "$DB_PORT")
DB_DATABASE=$(env_escape "$DB_NAME")
DB_USERNAME=$(env_escape "$DB_USER")
DB_PASSWORD=$(env_escape "$DB_PASS")

SESSION_DRIVER=array
CACHE_STORE=file
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=null
FILESYSTEM_DISK=local
MAIL_MAILER=log

API_VERSION=1.0
# No dev key in production — mint keys with: ispconfig-rest key:create
API_DEV_KEY=
EOF
chown "$RUN_USER":"$RUN_USER" "$ENV_FILE"; chmod 600 "$ENV_FILE"
grep -q '^APP_KEY=base64:' "$ENV_FILE" || { run_as "$PHP_BIN" artisan key:generate --force; ok "Application key generated"; }

# ---------------------------------------------------------------------------
# Create the api_keys table with a privileged DB login (root)
# ---------------------------------------------------------------------------
step "Creating the api_keys table (privileged login)"
migrate_privileged() {
  if [ -n "$DB_ADMIN_PASS" ] || [ "$DB_ADMIN_USER" != "root" ]; then
    run_as env DB_USERNAME="$DB_ADMIN_USER" DB_PASSWORD="$DB_ADMIN_PASS" "$PHP_BIN" artisan migrate --force
  else
    # unix-socket auth as MySQL root — requires the root OS user
    ( cd "$INSTALL_DIR" && DB_USERNAME=root DB_PASSWORD="" "$PHP_BIN" artisan migrate --force )
  fi
}
if migrate_privileged; then MIGRATE_OK=1; else MIGRATE_OK=0; fi
chown -R "$RUN_USER":"$RUN_USER" "$INSTALL_DIR/storage" "$INSTALL_DIR/bootstrap/cache" 2>/dev/null || true
if [ "$MIGRATE_OK" = "1" ]; then ok "api_keys table ready"
else warn "Privileged migration failed. Provide --db-admin-pass (if MySQL root needs a password) or create the table manually, then run 'ispconfig-rest update'."; fi

step "Optimizing"
run_as "$PHP_BIN" artisan config:cache >/dev/null 2>&1 || true
run_as "$PHP_BIN" artisan route:cache  >/dev/null 2>&1 || true
ok "Configuration cached"

# ---------------------------------------------------------------------------
# php-fpm pool (dedicated to this app)
# ---------------------------------------------------------------------------
SERVE_BACKEND=""   # fpm | modphp — recorded for the manager
write_fpm_pool() {
  local pool="$FPM_POOL_DIR/${SERVICE_NAME}.conf"
  cat > "$pool" <<EOF
; ISPConfig REST API — dedicated php-fpm pool (managed by install.sh)
[${SERVICE_NAME}]
user = ${RUN_USER}
group = ${RUN_USER}
listen = ${FPM_SOCK}
listen.owner = ${WEB_USER}
listen.group = ${WEB_USER}
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 10s
pm.max_requests = 500
php_admin_value[expose_php] = off
php_admin_flag[display_errors] = off
EOF
  systemctl reload "$FPM_SERVICE" 2>/dev/null || systemctl restart "$FPM_SERVICE"
  SERVE_BACKEND="fpm"
  ok "php-fpm pool '${SERVICE_NAME}' → ${FPM_SOCK}"
}

ssl_ok() { [ -f "$ISPCONFIG_SSL_DIR/ispserver.crt" ] && [ -f "$ISPCONFIG_SSL_DIR/ispserver.key" ]; }
ssl_bundle_line_apache() { [ -f "$ISPCONFIG_SSL_DIR/ispserver.bundle" ] && printf '    SSLCACertificateFile %s/ispserver.bundle\n' "$ISPCONFIG_SSL_DIR"; }

setup_apache() {
  step "Configuring Apache vhost (reusing ISPConfig SSL)"
  ssl_ok || die "ISPConfig SSL cert not found in $ISPCONFIG_SSL_DIR"
  local avail="/etc/apache2/sites-available/${SERVICE_NAME}.conf" php_handler
  if [ "$FPM_AVAILABLE" = "1" ]; then
    write_fpm_pool
    a2enmod ssl rewrite headers proxy proxy_fcgi setenvif >/dev/null 2>&1 || true
    php_handler="    <FilesMatch \\.php\$>
        SetHandler \"proxy:unix:${FPM_SOCK}|fcgi://localhost\"
    </FilesMatch>"
  else
    a2enmod ssl rewrite headers >/dev/null 2>&1 || true
    php_handler="    # served by the Apache mod_php (php-fpm not installed)"
    SERVE_BACKEND="modphp"
  fi
  cat > "$avail" <<EOF
# ISPConfig REST API — dedicated vhost (NOT managed by ISPConfig).
Listen ${PUBLIC_PORT}
<VirtualHost _default_:${PUBLIC_PORT}>
    ServerName ${HOSTNAME_FQDN}
    DocumentRoot ${INSTALL_DIR}/public

    SSLEngine On
    SSLProtocol All -SSLv3 -TLSv1 -TLSv1.1
    SSLHonorCipherOrder On
    SSLCertificateFile ${ISPCONFIG_SSL_DIR}/ispserver.crt
    SSLCertificateKeyFile ${ISPCONFIG_SSL_DIR}/ispserver.key
$(ssl_bundle_line_apache)

${php_handler}

    <Directory ${INSTALL_DIR}/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  \${APACHE_LOG_DIR}/${SERVICE_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SERVICE_NAME}-access.log combined
</VirtualHost>
EOF
  a2ensite "$SERVICE_NAME" >/dev/null 2>&1 || true
  if apache2ctl configtest 2>/dev/null; then
    systemctl reload apache2 2>/dev/null || systemctl restart apache2
    ok "Apache vhost on :$PUBLIC_PORT ($SERVE_BACKEND)"
  else warn "apache2ctl configtest failed — review $avail and reload Apache."; fi
}

setup_nginx() {
  step "Configuring nginx vhost (php-fpm, reusing ISPConfig SSL)"
  ssl_ok || die "ISPConfig SSL cert not found in $ISPCONFIG_SSL_DIR"
  write_fpm_pool
  local avail="/etc/nginx/sites-available/${SERVICE_NAME}.conf" bundle=""
  # nginx wants the full chain in one file; use bundle if present, else the cert.
  local certfile="$ISPCONFIG_SSL_DIR/ispserver.crt"
  [ -f "$ISPCONFIG_SSL_DIR/ispserver.pem" ] && certfile="$ISPCONFIG_SSL_DIR/ispserver.pem"
  cat > "$avail" <<EOF
# ISPConfig REST API — dedicated vhost (NOT managed by ISPConfig).
server {
    listen ${PUBLIC_PORT} ssl;
    server_name ${HOSTNAME_FQDN};
    root ${INSTALL_DIR}/public;
    index index.php;

    ssl_certificate     ${certfile};
    ssl_certificate_key ${ISPCONFIG_SSL_DIR}/ispserver.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \\.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
    }

    location ~ /\\.(?!well-known).* { deny all; }
}
EOF
  mkdir -p /etc/nginx/sites-enabled
  ln -sf "$avail" "/etc/nginx/sites-enabled/${SERVICE_NAME}.conf"
  if nginx -t 2>/dev/null; then systemctl reload nginx; ok "nginx vhost on :$PUBLIC_PORT (fpm)"
  else warn "nginx -t failed — review $avail and reload nginx."; fi
}

case "$SERVE_MODE" in
  apache) setup_apache;;
  nginx)  setup_nginx;;
  none)   info "Serve mode 'none' — deploy a web front end yourself (DocumentRoot $INSTALL_DIR/public).";;
  *) die "Unknown serve mode: $SERVE_MODE";;
esac

# ---------------------------------------------------------------------------
# Firewall — open the port in ISPConfig's own firewall (falls back to the OS)
# ---------------------------------------------------------------------------
open_firewall() {
  step "Firewall"
  local out rc
  # Preferred: add the port to ISPConfig's firewall record via datalog, so its
  # firewall plugin (bastille/ufw) reconfigures the running firewall natively.
  out="$(run_as "$PHP_BIN" artisan firewall:allow "$PUBLIC_PORT" 2>&1)"; rc=$?
  printf '%s\n' "$out" | sed 's/^/  /'
  if [ "$rc" = "0" ]; then ok "Port $PUBLIC_PORT allowed in the ISPConfig firewall"; return; fi
  # Fallback: the OS firewall, only if one is actually active.
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qiw active; then
    ufw allow "${PUBLIC_PORT}/tcp" >/dev/null 2>&1 && ok "Port $PUBLIC_PORT opened via ufw" || warn "ufw rule failed"
  elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state 2>/dev/null | grep -qiw running; then
    firewall-cmd --permanent --add-port="${PUBLIC_PORT}/tcp" >/dev/null 2>&1 && firewall-cmd --reload >/dev/null 2>&1 \
      && ok "Port $PUBLIC_PORT opened via firewalld" || warn "firewalld rule failed"
  else
    warn "No firewall was changed automatically — open port ${PUBLIC_PORT}/tcp yourself if the API is reached from outside."
  fi
}
[ "$SERVE_MODE" != "none" ] && [ "$MIGRATE_OK" = "1" ] && open_firewall

# ---------------------------------------------------------------------------
# Manager CLI + install state
# ---------------------------------------------------------------------------
step "Registering the ispconfig-rest manager"
mkdir -p "$STATE_DIR"
cat > "$STATE_DIR/install.conf" <<EOF
# ISPConfig REST API — install state (managed by install.sh / ispconfig-rest)
INSTALL_DIR="$INSTALL_DIR"
RUN_USER="$RUN_USER"
SERVE_MODE="$SERVE_MODE"
SERVE_BACKEND="$SERVE_BACKEND"
SERVICE_NAME="$SERVICE_NAME"
PUBLIC_PORT="$PUBLIC_PORT"
WEB_SERVER="$DETECTED_WS"
FPM_SERVICE="$FPM_SERVICE"
BRANCH="$BRANCH"
PHP_BIN="$(command -v "$PHP_BIN")"
EOF
chmod 600 "$STATE_DIR/install.conf"
install -m 0755 "$INSTALL_DIR/bin/ispconfig-rest" "$MANAGER_PATH"
ok "Installed $MANAGER_PATH"

# ---------------------------------------------------------------------------
# Optional admin API key
# ---------------------------------------------------------------------------
ADMIN_KEY=""
case "$CREATE_ADMIN_KEY" in
  yes) MK=1;; no) MK=0;;
  *) confirm "Create an initial admin API key now?" yes && MK=1 || MK=0;;
esac
[ "${MK:-0}" = "1" ] && ADMIN_KEY="$(run_as "$PHP_BIN" artisan api:key:create "installer admin" 2>/dev/null | grep -oE 'isp_[A-Za-z0-9]+' || true)"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
step "Done"
ok "ISPConfig REST API $(cat "$INSTALL_DIR/VERSION" 2>/dev/null) installed."
echo
echo "  Install dir : $INSTALL_DIR   (runs as $RUN_USER)"
echo "  Serve mode  : $SERVE_MODE${SERVE_BACKEND:+ / $SERVE_BACKEND}"
echo "  API base    : ${APP_URL%/}/api/v1"
echo "  Docs (UI)   : ${APP_URL%/}/api/documentation"
echo "  Manage      : ispconfig-rest {status|update|restart|logs|key:create|artisan ...}"
[ -n "$ADMIN_KEY" ] && { echo; echo "  ${C_B}Admin API key (shown once):${C_RESET} ${C_G}$ADMIN_KEY${C_RESET}"; }
case "$SERVE_MODE" in
  apache|nginx) echo; echo "  The vhost listens on port $PUBLIC_PORT with ISPConfig's panel certificate.";
                echo "  Open that port in your firewall if you reach the API from outside.";;
esac
echo
echo "  Mint keys:  ispconfig-rest key:create \"my integration\" [--client-id N]"
echo "  Update:     ispconfig-rest update"
