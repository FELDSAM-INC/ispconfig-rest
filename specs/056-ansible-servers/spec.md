# 056 — Ansible installation of ISPConfig servers

Owner request (2026-10-11): Ansible playbooks to install ISPConfig servers, with groups set by hand in the hosts file.
Roles: the panel (ISPConfig master and the ispconfig-rest service), web with FTP/SSH, database, primary DNS. Use the
public ISPConfig autoinstaller; web servers need an FTP passive port range and PHP versions: all available ones
(7.0–8.5) plus 5.3 and 5.6 for old websites, compiled where necessary.

## Findings (autoinstaller of 2026-10, ISPConfig 3.3.1p1)

- The autoinstaller (`ispconfig.ai.php`) takes `--use-php=5.6,...,8.5` (sury repository, adds the system version),
  `--use-ftp-ports=FROM-TO`, `--use-nginx`, `--no-web|mail|dns|ftp|jailkit|quota|pma|...`. Unattended, it generates an
  `autoinstall.ini` with `join_multiserver_setup=n`: joining a master is only possible interactively (`--interactive`,
  ISPConfig's expert mode). It exits with status 0 also after errors and refuses to run once ISPConfig is installed.
- ISPConfig's `install.php` reads the ini as `[install] + [ssl_cert] + [expert] + [update]` (first key wins); a missing
  key falls back to a prompt on stdin. The join keys are `join_multiserver_setup`, `mysql_master_hostname`,
  `mysql_master_port`, `mysql_master_root_user`, `mysql_master_root_password`, `mysql_master_database`.
- The installer always registers a server as database server and grants the server's account on the master by host
  name and by the address the name resolves to on the server. The monitor of a server reads its services from the
  server's own copy of `server`.
- PHP 5.6 and 7.0–8.5 are in the sury repository for all supported systems; 5.3 is not. PHP 5.3.29 needs OpenSSL 1.0.x,
  `-fcommon` (GCC 10+), relaxed GCC 14 errors and gnu89 inline semantics; with FPM it builds no CGI binary. It builds on
  Ubuntu 22.04/24.04 and Debian 12/13 without implicit declarations; the bundled libzip reads zlib's 32-bit CRC table
  as 64-bit (patched). Its FPM accepts ISPConfig's pool template (ondemand, env, php_admin_value).
- ISPConfig restarts an additional PHP version through the basename of `php_fpm_init_script` as a systemd unit.

## Decisions (owner-delegated)

- Directory `ansible/` in this repository, roles `ispconfig_server` (shared), `ispconfig_panel`, `ispconfig_web`,
  `ispconfig_db`, `ispconfig_dns`; groups of the same names. A server may be in several groups: the autoinstaller runs
  once with the services of all its groups. Exactly one panel. Mail is not covered. Built-in Ansible modules only.
- The autoinstaller is patched at one line so its generated ini goes through `files/autoinstall.php`, which applies
  JSON values (join keys, admin password, certificate fields); the patch is verified to match exactly once, the values
  file is root-only and deleted after the run. Success is ISPConfig's config plus "Installation ready" in the log.
- The panel's MariaDB gets a join account (`ispconfig_join`, all privileges with grant option) per server address; it
  stays for ISPConfig updates of those servers. All servers are listed in `/etc/hosts` with the address they use.
- Service flags (web/FTP, database, DNS) follow the groups on every run, in the master database and the server's copy.
  MariaDB (owner, 2026-10-11): the panel listens on loopback and `ispconfig_address` only (the private network;
  MariaDB 10.11+ takes both, older only the address), database servers on all addresses for customers' remote
  access, web and DNS servers on loopback; a warning when the panel's address is public. Databases of websites on
  another server need no manual step: ISPConfig's server IP is `ispconfig_address`, ispconfig-rest adds it to the
  database's remote access (legacy parity), and the WHMCS module names the database server as the host.
- PHP: sury versions through the autoinstaller first, later additions installed and registered by the web role (as
  the autoinstaller does: master row, then copied with its ID). PHP 5.3.29 built from pinned sources with a static
  OpenSSL 1.0.2u and libcurl 7.88.1 into `/opt/php-5.3`, Debian-like configuration in `/etc/php/5.3`, `php5.3-fpm`
  systemd unit, registered for PHP-FPM only, jailkit section `php5_3`. PHP 8.3 on every server for ispconfig-rest and
  the server tools, the default `php` where the system's is older.
- ispconfig-rest installed with its own installer (non-interactive, no key); the panel gets an SSH key authorized for
  root on the servers from its address, host keys come through Ansible's connection, then
  `ispconfig-rest server-tools install` (database, web-logs, waf; file-manager with the WHMCS key; dns-catalog on DNS
  servers), repeated only when servers or components change.
- `site.yml` imports `dns-catalog/ansible/secondary.yml` when `ispconfig_dns_secondaries` has hosts.
- Tests: unit test of `autoinstall.php`; CI syntax checks; `tests/Integration/ansible/run.sh` runs the real
  autoinstaller on four systemd containers, websites on PHP 5.3 and 5.6 through the API, and a second run.
