# Install or update server tools from the REST host

The root `ispconfig-rest` CLI discovers servers in ISPConfig's master database,
checks passwordless SSH, copies the release and runs the existing installers:

| Component | ISPConfig role | Installer |
| --- | --- | --- |
| `database` | Database server | Database import/export/copy worker |
| `web-logs` | Web server | Website logs, document-root checks and PHP configuration snapshots |
| `file-manager` | Web server | Jailed SFTP accounts for WHMCS |
| `waf` | Web server | Apache/nginx ModSecurity, OWASP CRS and audit worker |
| `php-limits` | Web server | PHP-FPM cgroup limits per account and website (opt-in) |

Inactive servers, mirrors and servers without a selected role are listed as
skipped. The current workers identify websites by their owning server ID; mirrored
servers are not advertised as independent worker targets. The local server is
identified using ISPConfig's native server ID and does not need SSH to itself.
The API runtime does not receive SSH keys, SQL administrator credentials or root
privileges. There are no new HTTP endpoints or migrations for this CLI feature.

## Quick start

Update REST and apply its existing migrations first. Run as root on the REST host:

```sh
# Database worker, web logs/runtime worker and WAF across the applicable servers:
sudo ispconfig-rest server-tools install --components database,web-logs,waf --dry-run
sudo ispconfig-rest server-tools install --components database,web-logs,waf

# Update only components already installed on each server:
sudo ispconfig-rest server-tools update

# Inspect installed components, prerequisites and SQL permissions:
sudo ispconfig-rest server-tools status
```

`install` ensures selected components are installed, and also updates existing
ones. `update` never adds a component absent from that server. The default component
selection is all components except `php-limits`, which restarts PHP-FPM once when
installed and must be selected explicitly (`sudo ispconfig-rest php-limits:install`);
`update` includes it where it is installed. `--dry-run` performs read-only discovery and target checks;
it does not fetch/copy a release, run installers or grant permissions. A normal run
shows the plan and asks for confirmation; use `--yes` for unattended operation.
Every selected target must pass preflight before any installer runs. A later
installer failure is reported per server, returns a nonzero exit status, and leaves
successful installations in place. Correct the error and repeat the command.

Convenience aliases are `workers:install`, `workers:update`, `waf:install` and
`waf:update`. Workers includes the file-manager worker. See all options with
`ispconfig-rest server-tools install --help`.

## SSH setup

The CLI uses root's OpenSSH configuration. A server's `server_name` is its default
SSH address. Override it with `--host 2=web02-admin`, select individual IDs using
repeatable `--server 2`, or configure host aliases/ports/ProxyJump in root's
`~/.ssh/config`. `--ssh-port`, `--identity` and `--known-hosts` are also supported.
For example:

```sh
sudo ispconfig-rest server-tools install --server 2 --host 2=web02-admin \
  --components web-logs,waf --identity /root/.ssh/ispconfig-tools
```

SSH is always batch/key-only, with strict host-key verification and no agent
forwarding. Unknown or changed host keys are not automatically trusted. If login
fails, the command stops before installation and prints a `ssh-copy-id` example.
Configure the public key using your normal administrator access, verify the host
fingerprint through a trusted channel, populate root's known_hosts, and rerun.
The manager never prompts for, stores or transfers an SSH password/private key.

The default SSH user is root. `--ssh-user deploy` supports an administrator account
with passwordless `sudo -n`; this needs permission to run the root installation
commands. It is administrative server access, not a customer SFTP/hosting account.

## Release provenance and requirements

The REST host needs Python 3.8+, git, OpenSSH client, PHP CLI and sudo. Targets need
the prerequisites documented by their individual installers, including PHP CLI
8.3+, the required extensions and native utilities. WAF supports Debian/Ubuntu
distribution packages. The command reports missing prerequisites; it does not
replace a server's PHP installation. The WAF installer installs its ModSecurity
packages normally and downloads the OWASP CRS release pinned by this API release,
verifying its checksum and signature.

By default, sources come directly from the official HTTPS GitHub repository at
the **exact commit installed by the API**. That commit must belong to the branch
selected in root-owned `install.conf`; arbitrary fork/PR objects are rejected.
The web-owned checkout supplies only
the revision and read-only inventory, executed as the unprivileged API user. No
worker code or `.env` from that checkout is executed as root. Only explicitly
allowlisted regular source files are archived; private configuration and vendor
dependencies are excluded. The target receives a private root-owned staging
directory and verifies SHA-256 checksums before executing any installer. Temporary
stages are removed on normal completion and failure.

For an offline or privately maintained release, pass `--source /root/rest-release`.
Its directories, ancestors and selected files must be root-owned, without symlinks
or group/other write permissions. It must be compatible with the installed API.
The administrator is responsible for reviewing that source. Worker installation
does not switch branches or update the REST API itself.

New installations install the root CLI helpers automatically. When upgrading an
old CLI, the new manager bootstraps missing helpers from the installed published
commit on first use. Regular REST updates refresh the helpers as well.

## Database permissions and configuration

Each target uses its existing ISPConfig master SQL account. Preflight checks the
required table permissions using read-only queries and `EXPLAIN`, without writing
rows. Missing tables/columns require API migrations first. The plan lists missing
grants by account and table. On a REST host that also owns the local ISPConfig
master database, the command applies only these explicit worker table privileges
using MySQL root's local socket login, falling back to local ISPConfig
`mysql_clientdb.conf` credentials. It never creates SQL users, changes passwords,
grants global privileges, or grants writes to native ISPConfig tables.

If the database master is elsewhere or local administrator access is unavailable,
apply the displayed table grants there and rerun. `--no-grants` requires permissions
to be configured beforehand. Worker credentials remain on their existing hosts;
only the SQL account identity and missing table rights cross the SSH connection.

Database jobs and file-manager jail reconciliation run through their normal cron
after installation. The CLI does not wait for large database jobs, and an unrelated
website with an unsafe jail cannot prevent installation of other components. Jail
safety checks stay enforced; per-site failures remain visible in the worker's
syslog. See `journalctl -t ispconfig-rest-file-manager-worker`. Installed cron entries
are checked after copying; heartbeat/capability availability follows the next
minute's worker run. `status` checks installation and permissions, not heartbeat
freshness or customer traffic.

## File manager and WAF

First-time file-manager installation additionally needs the **WHMCS public key**
and its outgoing IP address:

```sh
sudo ispconfig-rest server-tools install \
  --file-manager-key /root/whmcs-files.pub --whmcs-ip 192.0.2.10
```

Never supply a private key here. Subsequent installs/updates reuse each server's
existing public key and egress IP unless replacements are explicitly supplied.
WHMCS's private file-manager mapping still needs each server's trusted host key,
address and local private-key path; see the [file manager instructions](../file-manager-worker/README.md).

The WAF installer preserves existing per-site configuration and Atomicorp license
files. New websites stay opted out. Optional server-specific license entry stays
on that server: `sudo ispconfig-waf atomic-key`. The installer also updates the
native ISPConfig directive allowlist on webservers that host the panel, and on the
local REST/panel host even if it has no web role. If the ISPConfig interface is on
a separate host outside these targets, run the security-only installer there as
described in the [WAF instructions](../waf-server/README.md#ispconfig-directive-validation).

## Tests

```sh
python3 -m unittest discover -s tests/Integration/server-tools -p 'test_*.py'
php vendor/bin/phpunit --filter ServerTools
docker build -t ispcp-waf-test tests/Integration/waf
docker build -t ispcp-server-tools-test -f tests/Integration/server-tools/Dockerfile .
docker run -d --name ispcp-server-tools-db -e MARIADB_ROOT_PASSWORD=server-tools-fixture-only mariadb:10.11
# Wait until the disposable MariaDB server is ready, then:
docker run --rm --network container:ispcp-server-tools-db -v "$PWD:/app:ro" ispcp-server-tools-test php /app/tests/Integration/server-tools/grants.php
docker run --rm --network container:ispcp-server-tools-db -v "$PWD:/app:ro" ispcp-server-tools-test python3 /app/tests/Integration/server-tools/ssh.py
docker rm -f ispcp-server-tools-db
```

The native checks use only the disposable container's loopback fixture database.
The SSH fixture runs the real installers twice and verifies all five components,
configuration preservation, source ownership, cleanup, wrong-server rejection and
both host-key and login-key failure. It stubs systemctl only inside that container.
`php-limits` needs a Docker host with the cgroup v2 unified hierarchy, and `waf`
downloads the pinned OWASP CRS release.
