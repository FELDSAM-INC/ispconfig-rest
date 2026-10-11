# Install ISPConfig servers with Ansible

`site.yml` installs an ISPConfig 3.3 multiserver setup on fresh Debian/Ubuntu servers. It uses the official
[ISPConfig autoinstaller](https://www.ispconfig.org/documentation/installing-ispconfig-with-the-autoinstaller/) and
adds ispconfig-rest with its server tools on the panel. The groups you put a server in decide what it becomes:

| Group | Role | The server gets |
| --- | --- | --- |
| `ispconfig_panel` | `ispconfig_panel` | ISPConfig master: the interface (port 8080) and the master database, plus ispconfig-rest (port 8090), which installs the server tools on the other servers. It hosts nothing for customers unless it is also in another group. |
| `ispconfig_web` | `ispconfig_web` | nginx (or Apache), PHP-FPM with the chosen PHP versions, including PHP 5.3 built from source, Pure-FTPd with a passive port range, jailkit for SSH users, quota |
| `ispconfig_db` | `ispconfig_db` | MariaDB for customer databases, reachable by the web servers |
| `ispconfig_dns` | `ispconfig_dns` | BIND as primary DNS, with the [catalog zone](../dns-catalog/README.md) for secondaries |

Exactly one server is the panel. A server may be in several groups, for example web and database; the autoinstaller
runs once with all its services. Mail is not covered.

## Requirements

- Ansible core 2.15 or newer on your machine; only the built-in modules are used.
- Fresh servers with Debian 12, Debian 13, Ubuntu 22.04 or Ubuntu 24.04, reachable as root (or a user with sudo).
  The autoinstaller reconfigures the whole server: do not use servers that already run something.
- Each server's FQDN (at least two dots) as its inventory name. That becomes the ISPConfig server name. For Let's
  Encrypt certificates on the interface, its DNS record must point to the server; otherwise it gets a self-signed one.
- The servers reach the panel's MariaDB (TCP 3306), and the panel reaches the servers over SSH (TCP 22).

## Quick start

```sh
cd ansible
cp hosts.example hosts                          # your servers and groups
cp group_vars/all.example.yml group_vars/all.yml
ansible-vault encrypt_string 'a-long-password' --name ispconfig_admin_password            # paste into all.yml
ansible-vault encrypt_string 'another-long-one' --name ispconfig_master_db_admin_password
ansible-playbook -i hosts site.yml --ask-vault-pass
```

An inventory in the INI format of `hosts.example`:

```ini
[ispconfig_panel]
panel.example.com ispconfig_address=10.0.0.10

[ispconfig_web]
web1.example.com ispconfig_address=10.0.0.11

[ispconfig_db]
db1.example.com ispconfig_address=10.0.0.21

[ispconfig_dns]
ns1.example.com ispconfig_address=10.0.0.31
```

`ispconfig_address` is the address the ISPConfig servers use for each other, for example on a private network; it
defaults to the server's default IPv4 address. The playbook runs in this order:

1. **Prepare**: checks the inventory and variables, sets the host name and lists all ISPConfig servers in
   `/etc/hosts` on each of them. ISPConfig grants the servers' database accounts on the panel by name and address,
   so the names must resolve to these addresses everywhere.
2. **Panel**: autoinstaller as a standalone master, then ispconfig-rest.
3. **Servers**: all database, web and DNS servers in parallel (`ispconfig_serial: 1` installs them one by one).
   Each server joins the panel and is then visible in the ISPConfig interface.
4. **Server tools**: the panel installs the [server tools](../server-tools/README.md) on the servers.
5. **DNS secondaries**: optional, see [DNS](#dns).

A run takes 20–60 minutes per server, mostly the autoinstaller. Running the playbook again changes nothing on
installed servers and adds new ones; tags `panel`, `web`, `db`, `dns`, `tools` and `secondaries` limit a run.

## How the servers join the panel

When unattended, the autoinstaller only installs standalone servers. Joining a multiserver setup is its interactive
"expert mode". The role patches one line of the downloaded autoinstaller. The `autoinstall.ini` it generates then
goes through [`autoinstall.php`](roles/ispconfig_server/files/autoinstall.php), which sets the values a joining server
needs: `join_multiserver_setup=y`, the panel's database address and the join account, and no interface. On the panel
it sets your admin password. The role checks that the patched line exists exactly once and stops when a new
autoinstaller release has changed it. The values file holds secrets and is deleted after the run.

- The join account (`ispconfig_master_db_admin_user`, default `ispconfig_join`) is a MariaDB account on the panel with
  all privileges, created for each server's address only. ISPConfig asks for it again when you update a server
  ("MySQL master server root"), so it stays.
- The autoinstaller does not stop on errors and always exits with success. The role checks for ISPConfig's
  configuration and the final "Installation ready" message instead. On failure it shows the end of
  `/root/ispconfig-install-log/ansible.log`, without lines that mention passwords. The autoinstaller's own logs in that
  directory contain the MariaDB root password.
- ISPConfig marks every new server as a database server and the panel as a web server. The role sets the ISPConfig
  services (web and FTP, database, DNS) from the inventory groups, on every run, in the master database and in the
  server's own copy.
- MariaDB listens only where it has to; see [Private network and databases](#private-network-and-databases).
- The autoinstaller is not versioned. Set `ispconfig_autoinstaller_sha256` to install only a copy you have reviewed.

## PHP versions on web servers

`ispconfig_php_versions` lists the PHP versions for websites. 5.6 and 7.0–8.5 come from the sury repository through
the autoinstaller; versions added to the list later are installed on the next run. Each version is added to
ISPConfig (System > Additional PHP Versions). Removing a version from the list does not uninstall it.

**PHP 5.3** has no package for current systems, so the role builds PHP 5.3.29 from source into `/opt/php-5.3`
([`build-php53.sh`](roles/ispconfig_web/files/build-php53.sh), 5–15 minutes):

- PHP 5.3 needs OpenSSL 1.0. The build links its own OpenSSL 1.0.2u and libcurl 7.88.1 statically, so PHP 5.3 never
  loads the system's OpenSSL 3. All three sources are pinned by SHA-256: PHP and OpenSSL as published, and curl
  checked against its maintainer's signature.
- Extensions: mysql, mysqli, PDO MySQL (mysqlnd), gd with JPEG/PNG/FreeType, curl, openssl, mbstring, mcrypt, soap,
  zip, xsl, xmlrpc, bcmath, calendar, exif, ftp, gettext, iconv, sockets, SQLite and others. Not included: intl, imap
  and tidy (their current libraries do not fit PHP 5.3) and opcache (PHP 5.3 has none).
- PHP-FPM only: PHP 5.3 builds no CGI binary next to FPM. Choose PHP-FPM for its websites, which nginx uses anyway.
- It runs as the `php5.3-fpm` service, configured like the packaged versions: `/etc/php/5.3/{cli,fpm}/php.ini`,
  `conf.d` and `fpm/pool.d`, where ISPConfig writes the website pools. `/usr/bin/php5.3` is its CLI, also in SSH jails
  (jailkit section `php5_3`). Extra php.ini settings: `ispconfig_php53_ini`.
- PHP 5.3 has had no security fixes since 2014, and neither has OpenSSL 1.0.2 since 2019. Offer it only to websites
  that cannot move on, and keep them isolated (PHP-FPM chroot, open_basedir).
- It is rebuilt when its sources, the build script or the paths change. A failed build leaves the working one in place.

`ispconfig_tools_php_version` (8.3) is installed on every server: ispconfig-rest and the server tools need PHP 8.3+.
It becomes the default `php` where the system's PHP is older (Debian 12).

## The panel and ispconfig-rest

ispconfig-rest is installed with its own [installer](../README.md#install-on-an-ispconfig-server-recommended) from
`ispconfig_rest_version` (`main` follows the releases; a tag pins one, 1.0.7 or newer: the installer of earlier
releases stops on a new panel, which has no ISPConfig firewall record yet). An installed API is not touched again;
update it with `ispconfig-rest update`. API keys are not created; mint one with `sudo ispconfig-rest key:create "WHMCS"`.

The panel's root gets its own SSH key (`/root/.ssh/ispconfig-tools`). Its public key is authorized for root on each
server, from the panel's address only. The servers' host keys are read through Ansible's connection, so
`ispconfig-rest server-tools` works without accepting unknown host keys. The server tools are `database`, `web-logs`
and `waf` by default (`ispconfig_rest_server_tools`), plus:

- `file-manager` when `ispconfig_rest_file_manager_key` (the WHMCS public key) and `ispconfig_rest_whmcs_ip` are set,
- `dns-catalog` on the DNS servers (`ispconfig_rest_dns_catalog`),
- `php-limits` when `ispconfig_rest_php_limits` is true.

They are installed again only when the servers or components change. Later updates: `ispconfig-rest server-tools
update`.

## DNS

Each DNS server gets the `dns-catalog` server tool: a catalog zone of all its zones, which BIND secondaries follow by
themselves. To set up secondaries in the same run, list them and make the DNS servers their primaries:

```ini
[ispconfig_dns_primaries:children]
ispconfig_dns

[ispconfig_dns_secondaries]
ns2.example.net
```

`site.yml` then runs [`dns-catalog/ansible/secondary.yml`](../dns-catalog/ansible/secondary.yml) at the end. Its
variables are described in the [DNS catalog guide](../dns-catalog/README.md#set-up-a-secondary-with-ansible).

## Private network and databases

Put the servers on a private network if your provider offers one, and set `ispconfig_address` to each server's private
address. Ansible itself can keep connecting over the public address (`ansible_host`). Traffic between ISPConfig
servers is unencrypted MariaDB: the servers read the panel's master database every minute, and websites query their
databases on the database servers.

MariaDB listens where it has to (`ispconfig_mariadb_bind_address`):

| Server | MariaDB listens on |
| --- | --- |
| panel | 127.0.0.1 and `ispconfig_address`: the master database is reachable from the private network only (MariaDB before 10.11, i.e. Ubuntu 22.04, takes one address: `ispconfig_address`, local clients use the socket) |
| database servers | all addresses: the web servers over the private network, customers with remote access from outside |
| web and DNS servers | 127.0.0.1 |

When the panel's `ispconfig_address` is not a private address, the role warns: the firewall must then keep 3306 to the
ISPConfig servers.

Databases of websites on another server work without manual steps:

- Every server resolves the others' FQDNs to their `ispconfig_address` (`/etc/hosts`), and ISPConfig records that
  address as each server's IP address (System > Server Config).
- When a database is created for a website on another server, in ISPConfig, through ispconfig-rest or from WHMCS,
  ISPConfig turns on remote access for the web server's address. Changes keep it.
- Websites connect to the database server by its FQDN, which resolves to its private address on the web servers. The
  WHMCS client area shows that name as the database host.
- Customers who want their own remote access add their addresses to the database in WHMCS or ISPConfig; they connect
  to the database server's public address.
- Private addresses are not offered to customers: ispconfig-rest leaves them out of the addresses it reports for DNS
  and connection details. Websites listen on `*` by default; do not assign private addresses to websites or DNS records.

## Firewall

The autoinstaller installs ufw, and ISPConfig manages it once you add a firewall record for a server
(System > Firewall). Include these ports, in addition to the services' own:

| Server | Port | From |
| --- | --- | --- |
| panel | 3306/tcp (MariaDB) | the ISPConfig servers (private network) |
| panel | 8080/tcp (interface), 8090/tcp (ispconfig-rest) | administrators, WHMCS |
| database servers | 3306/tcp | web servers (private network); customers with remote access |
| all servers | 22/tcp | the panel (server tools), administrators |
| web servers | 21/tcp and `ispconfig_ftp_passive_ports` | FTP users |
| DNS servers | 53/tcp+udp | everyone; transfers by TSIG key |

`ispconfig_firewall: false` leaves out ufw and ISPConfig's firewall management.

## Variables

Set them in `group_vars/all.yml`, or per group or host. They are listed with their defaults in
[`ispconfig_server`](roles/ispconfig_server/defaults/main.yml), [`ispconfig_web`](roles/ispconfig_web/defaults/main.yml)
and [`ispconfig_panel`](roles/ispconfig_panel/defaults/main.yml).

| Variable | Default | Meaning |
| --- | --- | --- |
| `ispconfig_admin_password` | (required) | ISPConfig `admin` login of the panel, 8+ characters |
| `ispconfig_master_db_admin_password` | (required with servers besides the panel) | password of the join account, 16+ characters |
| `ispconfig_address` | default IPv4 address | address the ISPConfig servers use for each other (per host) |
| `ispconfig_hostname` | inventory name | FQDN and ISPConfig server name |
| `ispconfig_php_versions` | 5.3, 5.6, 7.0–8.5 | PHP versions of web servers |
| `ispconfig_ftp_passive_ports` | `40110-40210` | Pure-FTPd passive port range |
| `ispconfig_webserver` | `nginx` | `nginx` or `apache` |
| `ispconfig_jailkit`, `ispconfig_quota` | `true` | jailkit and file system quota on web servers |
| `ispconfig_firewall` | `true` | ufw managed by ISPConfig |
| `ispconfig_ssl_cert` | `{}` | self-signed certificate fields: `country`, `state`, `locality`, `organisation`, `organisation_unit`, `email` |
| `ispconfig_autoinstaller_args` | `[]` | further autoinstaller options, e.g. `--monit`, `--unattended-upgrades=autoclean`, `--ssh-harden` |
| `ispconfig_autoinstaller_sha256` | empty | pin the autoinstaller download |
| `ispconfig_channel` | `stable` | ISPConfig `stable` or `dev` |
| `ispconfig_set_hostname`, `ispconfig_manage_hosts_file` | `true` | set the host name, manage `/etc/hosts` |
| `ispconfig_rest_install` | `true` | ispconfig-rest and the server tools on the panel |
| `ispconfig_rest_version` | `main` | branch or release tag of ispconfig-rest |
| `ispconfig_rest_port` | `8090` | HTTPS port of ispconfig-rest |

## After ISPConfig updates

- Updating a server that joined the panel asks for the "MySQL master server root" account: the join account and
  its password.
- If an update reconfigures services, it also rewrites `/etc/jailkit/jk_init.ini`. Run the playbook again to restore
  the PHP 5.3 jail section. If the update detects services and proposes to change them, keep the ones you have.

## Tests

```sh
# From the repository root: panel, web, database and DNS servers in systemd containers (Docker)
bash tests/Integration/ansible/run.sh
```

The test runs the real autoinstaller, so it takes long and downloads a lot. It installs a panel and three servers
joining it, builds PHP 5.3, and checks:

- the service flags and PHP versions in ISPConfig,
- PHP 5.3 and a sury PHP version serving through PHP-FPM,
- ispconfig-rest, the server tools and the catalog zone,
- that a second run changes nothing.
