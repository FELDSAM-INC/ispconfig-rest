#!/usr/bin/env bash
#
# ISPConfig REST API — installer
#
# Installs the REST API onto an existing ISPConfig 3.3 server. Reads the
# database credentials from ISPConfig's own config file, deploys the app,
# runs it as a systemd service, and registers the `ispconfig-rest` manager
# in PATH for updates.
#
# Usage:
#   curl -sSL https://raw.githubusercontent.com/FELDSAM-INC/ispconfig-rest/main/install.sh | sudo bash
#   sudo ./install.sh                       # interactive
#   sudo ./install.sh --non-interactive     # use defaults + flags/env
#
# Every prompt has a corresponding --flag and ISPC_REST_* env var; see --help.

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults (overridable by flags / env / prompts)
# ---------------------------------------------------------------------------
REPO_URL="${ISPC_REST_REPO:-https://github.com/FELDSAM-INC/ispconfig-rest.git}"
BRANCH="${ISPC_REST_BRANCH:-main}"
INSTALL_DIR="${ISPC_REST_DIR:-/opt/ispconfig-rest}"
RUN_USER="${ISPC_REST_USER:-ispconfig-rest}"
ISPCONFIG_CONFIG="${ISPC_REST_ISPCONFIG_CONFIG:-/usr/local/ispconfig/interface/lib/config.inc.php}"
APP_URL="${ISPC_REST_URL:-http://127.0.0.1:8090}"
BIND_HOST="${ISPC_REST_BIND_HOST:-127.0.0.1}"
BIND_PORT="${ISPC_REST_PORT:-8090}"
SERVE_MODE="${ISPC_REST_SERVE:-systemd}"          # systemd | none
CREATE_ADMIN_KEY="${ISPC_REST_CREATE_KEY:-ask}"   # yes | no | ask
NONINTERACTIVE="${ISPC_REST_NONINTERACTIVE:-0}"
SERVICE_NAME="ispconfig-rest"
STATE_DIR="/etc/ispconfig-rest"
MANAGER_PATH="/usr/local/bin/ispconfig-rest"
MIN_PHP="8.3"
PHP_BIN="${ISPC_REST_PHP:-php}"

# DB overrides (default: read from ISPConfig config)
DB_HOST="" DB_PORT="" DB_NAME="" DB_USER="" DB_PASS="" DB_OVERRIDE=0

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
  C_RESET=$'\033[0m'; C_B=$'\033[1m'; C_G=$'\033[32m'; C_Y=$'\033[33m'; C_R=$'\033[31m'; C_C=$'\033[36m'
else
  C_RESET="" C_B="" C_G="" C_Y="" C_R="" C_C=""
fi
info()  { printf '%s\n' "${C_C}▸${C_RESET} $*"; }
ok()    { printf '%s\n' "${C_G}✓${C_RESET} $*"; }
warn()  { printf '%s\n' "${C_Y}!${C_RESET} $*" >&2; }
err()   { printf '%s\n' "${C_R}✗${C_RESET} $*" >&2; }
die()   { err "$*"; exit 1; }
step()  { printf '\n%s\n' "${C_B}== $* ==${C_RESET}"; }

usage() {
  cat <<EOF
ISPConfig REST API installer

Options (each also settable via ISPC_REST_* env var):
  --dir PATH               install directory            (default: $INSTALL_DIR)
  --repo URL               git repository               (default: $REPO_URL)
  --branch NAME            branch or tag                (default: $BRANCH)
  --run-user NAME          system user to run as        (default: $RUN_USER)
  --ispconfig-config PATH  ISPConfig config to read DB  (default: $ISPCONFIG_CONFIG)
  --url URL                public APP_URL               (default: $APP_URL)
  --host HOST              bind host                    (default: $BIND_HOST)
  --port PORT              bind port                    (default: $BIND_PORT)
  --db-host / --db-port / --db-name / --db-user / --db-pass
                           override DB credentials instead of reading them
  --serve MODE             systemd | none               (default: $SERVE_MODE)
  --create-key yes|no      mint an admin API key        (default: ask)
  --non-interactive        accept defaults, no prompts
  --php PATH               php binary                    (default: $PHP_BIN)
  -h, --help               this help
EOF
}

# ---------------------------------------------------------------------------
# Prompt helpers (no-op in non-interactive mode)
# ---------------------------------------------------------------------------
ask() { # ask VAR "Prompt" "default"
  local __var="$1" __prompt="$2" __def="${3:-}" __ans
  if [ "$NONINTERACTIVE" = "1" ]; then printf -v "$__var" '%s' "$__def"; return; fi
  if [ -n "$__def" ]; then read -r -p "$__prompt [$__def]: " __ans </dev/tty || true
  else read -r -p "$__prompt: " __ans </dev/tty || true; fi
  printf -v "$__var" '%s' "${__ans:-$__def}"
}
ask_secret() { # ask_secret VAR "Prompt" "default"
  local __var="$1" __prompt="$2" __def="${3:-}" __ans
  if [ "$NONINTERACTIVE" = "1" ]; then printf -v "$__var" '%s' "$__def"; return; fi
  read -r -s -p "$__prompt${__def:+ [keep current]}: " __ans </dev/tty || true; echo
  printf -v "$__var" '%s' "${__ans:-$__def}"
}
confirm() { # confirm "Prompt" default(yes|no)
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
    --run-user) RUN_USER="$2"; shift 2;;
    --ispconfig-config) ISPCONFIG_CONFIG="$2"; shift 2;;
    --url) APP_URL="$2"; shift 2;;
    --host) BIND_HOST="$2"; shift 2;;
    --port) BIND_PORT="$2"; shift 2;;
    --db-host) DB_HOST="$2"; DB_OVERRIDE=1; shift 2;;
    --db-port) DB_PORT="$2"; DB_OVERRIDE=1; shift 2;;
    --db-name) DB_NAME="$2"; DB_OVERRIDE=1; shift 2;;
    --db-user) DB_USER="$2"; DB_OVERRIDE=1; shift 2;;
    --db-pass) DB_PASS="$2"; DB_OVERRIDE=1; shift 2;;
    --serve) SERVE_MODE="$2"; shift 2;;
    --create-key) CREATE_ADMIN_KEY="$2"; shift 2;;
    --non-interactive) NONINTERACTIVE=1; shift;;
    --php) PHP_BIN="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) die "Unknown option: $1 (see --help)";;
  esac
done

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
step "Preflight checks"
[ "$(id -u)" = "0" ] || die "This installer must run as root (use sudo)."

command -v git >/dev/null 2>&1 || die "git is required but not installed."
command -v "$PHP_BIN" >/dev/null 2>&1 || die "php is required but not installed."

PHP_VER="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
if [ "$("$PHP_BIN" -r 'echo version_compare(PHP_VERSION, "'"$MIN_PHP"'", ">=") ? 1 : 0;')" != "1" ]; then
  die "PHP >= $MIN_PHP required, found $PHP_VER."
fi
ok "PHP $PHP_VER"

MISSING_EXT=""
for ext in pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo; do
  "$PHP_BIN" -m | grep -qix "$ext" || MISSING_EXT="$MISSING_EXT $ext"
done
[ -z "$MISSING_EXT" ] || die "Missing PHP extensions:$MISSING_EXT"
ok "Required PHP extensions present"

if command -v composer >/dev/null 2>&1; then
  COMPOSER="composer"
elif [ -f "$INSTALL_DIR/composer.phar" ]; then
  COMPOSER="$PHP_BIN $INSTALL_DIR/composer.phar"
else
  info "Composer not found — fetching a local copy"
  ( cd /tmp && "$PHP_BIN" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && "$PHP_BIN" composer-setup.php --quiet && rm -f composer-setup.php )
  install -m 0755 /tmp/composer.phar /usr/local/bin/composer
  COMPOSER="composer"
fi
ok "Composer available"

[ -f "$ISPCONFIG_CONFIG" ] || warn "ISPConfig config not found at $ISPCONFIG_CONFIG (you can enter DB credentials manually)."

# ---------------------------------------------------------------------------
# Read ISPConfig DB credentials (PHP parses its own config — robust)
# ---------------------------------------------------------------------------
step "Database credentials"
read_ispconfig_conf() { # read_ispconfig_conf KEY
  "$PHP_BIN" -r '
    $c=$argv[1]; if(!is_file($c)){exit(1);}
    $conf=[]; include $c;
    echo $conf["db_'"$1"'"] ?? "";
  ' "$ISPCONFIG_CONFIG" 2>/dev/null || true
}

if [ "$DB_OVERRIDE" = "0" ] && [ -f "$ISPCONFIG_CONFIG" ]; then
  DB_HOST="$(read_ispconfig_conf host)"
  DB_PORT="$(read_ispconfig_conf port)"
  DB_NAME="$(read_ispconfig_conf database)"
  DB_USER="$(read_ispconfig_conf user)"
  DB_PASS="$(read_ispconfig_conf password)"
  [ -n "$DB_NAME" ] && ok "Read ISPConfig database credentials ($DB_USER@$DB_HOST/$DB_NAME)" \
                    || warn "Could not parse credentials from $ISPCONFIG_CONFIG"
fi

# Fall back / confirm
ask        DB_HOST "Database host" "${DB_HOST:-localhost}"
ask        DB_PORT "Database port" "${DB_PORT:-3306}"
ask        DB_NAME "Database name" "${DB_NAME:-dbispconfig}"
ask        DB_USER "Database user" "${DB_USER:-root}"
ask_secret DB_PASS "Database password" "$DB_PASS"

# ---------------------------------------------------------------------------
# Deployment options
# ---------------------------------------------------------------------------
step "Deployment options"
ask INSTALL_DIR "Install directory" "$INSTALL_DIR"
ask BIND_HOST   "Bind host (reverse-proxied)" "$BIND_HOST"
ask BIND_PORT   "Bind port" "$BIND_PORT"
ask APP_URL     "Public application URL" "${APP_URL/:[0-9]*/:$BIND_PORT}"
ask SERVE_MODE  "Service mode (systemd|none)" "$SERVE_MODE"

# ---------------------------------------------------------------------------
# Run-as user
# ---------------------------------------------------------------------------
step "System user"
if ! id "$RUN_USER" >/dev/null 2>&1; then
  useradd --system --home-dir "$INSTALL_DIR" --shell /usr/sbin/nologin "$RUN_USER" 2>/dev/null \
    || useradd --system --home-dir "$INSTALL_DIR" --shell /bin/false "$RUN_USER"
  ok "Created system user $RUN_USER"
else
  ok "Using existing user $RUN_USER"
fi

# ---------------------------------------------------------------------------
# Fetch / update the code
# ---------------------------------------------------------------------------
step "Fetching application code"
if [ -d "$INSTALL_DIR/.git" ]; then
  info "Existing install found — updating"
  git -C "$INSTALL_DIR" fetch --depth 1 origin "$BRANCH"
  git -C "$INSTALL_DIR" checkout -f "$BRANCH"
  git -C "$INSTALL_DIR" reset --hard "origin/$BRANCH"
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
  APP_KEY_LINE="$(grep '^APP_KEY=' "$ENV_FILE")"   # preserve key on re-install
  info "Preserving existing APP_KEY"
fi

# Escape a value for a dotenv double-quoted string. The value is passed to PHP
# via an env var (no shell-quoting of the payload), and PHP escapes backslash,
# double-quote and dollar in order — verified to round-trip through phpdotenv
# for arbitrary passwords ($ " \ # spaces, etc.).
env_escape() {
  ISPC_REST_VAL="$1" "$PHP_BIN" -r '
    $v = (string) getenv("ISPC_REST_VAL");
    $v = str_replace(["\\", "\"", "\$"], ["\\\\", "\\\"", "\\\$"], $v);
    echo "\"" . $v . "\"";
  '
}
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
# No dev key in production — mint real keys with: ispconfig-rest key:create
API_DEV_KEY=
EOF
chown "$RUN_USER":"$RUN_USER" "$ENV_FILE"
chmod 600 "$ENV_FILE"

if ! grep -q '^APP_KEY=base64:' "$ENV_FILE"; then
  run_as "$PHP_BIN" artisan key:generate --force
  ok "Application key generated"
fi

# ---------------------------------------------------------------------------
# Database connectivity + migrations
# ---------------------------------------------------------------------------
step "Database setup"
if run_as "$PHP_BIN" artisan db:show >/dev/null 2>&1 || run_as "$PHP_BIN" -r '
  require "vendor/autoload.php"; $a=require "bootstrap/app.php";
  $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  Illuminate\Support\Facades\DB::select("select 1"); echo "ok";
' >/dev/null 2>&1; then
  ok "Connected to $DB_NAME@$DB_HOST"
else
  warn "Could not connect to the database with the supplied credentials — check them and re-run 'ispconfig-rest update'."
fi

info "Running migrations (creates only the API-owned api_keys table)"
run_as "$PHP_BIN" artisan migrate --force || warn "Migration failed — the DB user may lack CREATE. See notes at the end."

step "Optimizing"
run_as "$PHP_BIN" artisan config:cache >/dev/null 2>&1 || true
run_as "$PHP_BIN" artisan route:cache  >/dev/null 2>&1 || true
ok "Configuration cached"

# ---------------------------------------------------------------------------
# systemd service
# ---------------------------------------------------------------------------
if [ "$SERVE_MODE" = "systemd" ]; then
  step "Installing systemd service"
  UNIT="/etc/systemd/system/${SERVICE_NAME}.service"
  ABS_PHP="$(command -v "$PHP_BIN")"
  cat > "$UNIT" <<EOF
[Unit]
Description=ISPConfig REST API
After=network.target mysql.service mariadb.service

[Service]
Type=simple
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$INSTALL_DIR
ExecStart=$ABS_PHP artisan serve --host=$BIND_HOST --port=$BIND_PORT
Restart=on-failure
RestartSec=3
# Hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=$INSTALL_DIR/storage $INSTALL_DIR/bootstrap/cache

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable "$SERVICE_NAME" >/dev/null 2>&1 || true
  systemctl restart "$SERVICE_NAME"
  sleep 1
  if systemctl is-active --quiet "$SERVICE_NAME"; then
    ok "Service $SERVICE_NAME running on $BIND_HOST:$BIND_PORT"
  else
    warn "Service failed to start — check: journalctl -u $SERVICE_NAME -n 30"
  fi
else
  info "Service mode 'none' — start the app yourself (e.g. behind php-fpm or 'php artisan serve')."
fi

# ---------------------------------------------------------------------------
# Manager CLI + install state
# ---------------------------------------------------------------------------
step "Registering the ispconfig-rest manager"
mkdir -p "$STATE_DIR"
cat > "$STATE_DIR/install.conf" <<EOF
# ISPConfig REST API — install state (managed by install.sh / ispconfig-rest)
INSTALL_DIR="$INSTALL_DIR"
RUN_USER="$RUN_USER"
SERVICE_NAME="$SERVICE_NAME"
SERVE_MODE="$SERVE_MODE"
BIND_HOST="$BIND_HOST"
BIND_PORT="$BIND_PORT"
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
  yes) MAKE_KEY=1;;
  no)  MAKE_KEY=0;;
  *)   if confirm "Create an initial admin API key now?" yes; then MAKE_KEY=1; else MAKE_KEY=0; fi;;
esac
if [ "${MAKE_KEY:-0}" = "1" ]; then
  ADMIN_KEY="$(run_as "$PHP_BIN" artisan api:key:create "installer admin" 2>/dev/null | grep -oE 'isp_[A-Za-z0-9]+' || true)"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
step "Done"
ok "ISPConfig REST API $(cat "$INSTALL_DIR/VERSION" 2>/dev/null) installed."
echo
echo "  Install dir : $INSTALL_DIR"
echo "  Runs as     : $RUN_USER"
echo "  Listening   : $BIND_HOST:$BIND_PORT   (reverse-proxy this behind your web server)"
echo "  Docs (UI)   : ${APP_URL%/}/api/documentation"
echo "  Manage      : ispconfig-rest {status|update|restart|logs|key:create|artisan ...}"
[ -n "$ADMIN_KEY" ] && { echo; echo "  ${C_B}Admin API key (shown once):${C_RESET} ${C_G}$ADMIN_KEY${C_RESET}"; }
cat <<EOF

Next steps:
  • Front the service with your existing web server. Example (nginx):
        location /api/ { proxy_pass http://$BIND_HOST:$BIND_PORT; proxy_set_header Host \$host; }
    (Apache: ProxyPass "/api/" "http://$BIND_HOST:$BIND_PORT/api/")
  • Mint keys:  ispconfig-rest key:create "my integration" [--client-id N]
  • Update later:  ispconfig-rest update

If migrations reported a CREATE error, the DB user lacks table-create rights.
Grant it once (as MySQL root), then run 'ispconfig-rest update':
    GRANT CREATE ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
EOF
