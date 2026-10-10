# DNS catalog zones for secondaries

The `dns-catalog` server tool publishes a **catalog zone** (RFC 9432, schema version 2) on an ISPConfig DNS server. A
secondary that consumes it provisions every DNS zone of that server by itself, and removes it again, without anyone
adding zones to the secondary by hand. Zones count no matter how they were created: in the ISPConfig interface, through
its remote API, through ispconfig-rest or the WHMCS module.

## How it works

- ISPConfig rewrites `/etc/bind/named.conf.local` whenever a zone is added, changed, disabled or removed. A systemd
  path unit follows that file and updates the catalog within seconds; a timer checks every five minutes.
- **Members** are the zones ISPConfig serves as primary. A zone with its own **Allow zone transfers** list in
  ISPConfig is left out: it is meant for another secondary. Disabling or deleting a zone removes it from the secondary.
- The catalog zone is `catalog.<server name>.invalid`. Its records are only for secondaries; the `.invalid` name can
  never clash with a real domain. Every member keeps a stable ID (the SHA-1 of its name).
- Transfers are authorised by a **TSIG key** created on the server (`ispcp-catalog.<server name>`), so no secondary
  address is needed. The key is added to the global `allow-transfer` list of `named.conf.options` (ISPConfig's
  `allow-transfer {none;};` becomes the key alone). A copy of that file goes to
  `/usr/local/ispconfig/server/conf-custom/install/named.conf.options.master`, the template ISPConfig uses when an update
  reconfigures services, so updates keep it. If you edit `named.conf.options` later, update that copy as well.
- Registered secondaries (`ispconfig-dns-catalog secondary add IP`) are notified of changes of the catalog and of every
  member zone, also as hidden secondaries that the zones' NS records do not name. Without NOTIFY a secondary still sees
  catalog changes within five minutes and zone changes at their SOA refresh.
- The tool reads no database and changes nothing in ISPConfig. Its files: `/etc/bind/ispcp-catalog.key` (root:bind 0640),
  `/etc/bind/named.conf.ispcp-catalog` (included at the end of `/etc/bind/named.conf`), the zone file
  `/var/lib/bind/ispcp-catalog.db` and `/etc/ispconfig-dns-catalog/config.json`.

## Install on the ISPConfig DNS server

Requirements: an ISPConfig DNS server with the BIND plugin, BIND 9.18 or newer with its utilities (`named-checkconf`,
`named-checkzone`, `rndc`, `tsig-keygen`), systemd and PHP CLI 8.3. From the REST host:

```sh
sudo ispconfig-rest dns-catalog:install     # same as: server-tools install --components dns-catalog
sudo ispconfig-rest dns-catalog:update      # after an API update
```

The component is never installed by default and only goes to DNS servers. Without server-tools, run
`bash dns-catalog/install.sh` as root from a root-owned release; `--catalog NAME` chooses another catalog zone name on
the first installation. The name cannot be changed later: secondaries would drop every zone. Each step is validated
with `named-checkconf` before `rndc reconfig`; a refused configuration restores the previous files.

```sh
ispconfig-dns-catalog status                  # members, zones left out, whether transfers and updates are covered
ispconfig-dns-catalog sync                    # update now (normally automatic)
ispconfig-dns-catalog export                  # catalog name and TSIG key as JSON, for setting up secondaries
ispconfig-dns-catalog secondary add 198.51.100.20     # notify this secondary of changes
ispconfig-dns-catalog secondary remove 198.51.100.20
```

`export` prints the TSIG secret: treat its output like a password. A server with several DNS servers in ISPConfig gets
one catalog per server; install the tool on each and let the secondaries consume all of them.

## Set up a secondary with Ansible

`ansible/secondary.yml` configures Debian/Ubuntu secondaries with BIND 9.18+ for the catalogs of all listed ISPConfig
DNS servers. It reads each server's catalog name and key with `ispconfig-dns-catalog export`, registers the secondaries
for NOTIFY there, installs BIND, writes the keys (root:bind 0640), the catalog zones and the `catalog-zones` block of
`named.conf.options`, checks the configuration and reloads BIND. Running it again changes nothing; it also adds new
primaries or secondaries.

```sh
cd dns-catalog/ansible
cp inventory.example.yml inventory.yml     # list the ISPConfig DNS servers and the secondaries
ansible-playbook -i inventory.yml secondary.yml
```

The playbook needs root (`become`) on both groups and Ansible 2.15 or newer. Variables:

| Variable | Host group | Default | Meaning |
| --- | --- | --- | --- |
| `ispcp_primary_addresses` | primaries | the host's IPv4 address | addresses the secondaries transfer from (IP addresses, not names) |
| `ispcp_secondary_addresses` | secondaries | the host's IPv4 address | addresses the primaries notify |
| `ispcp_install_packages` | secondaries | `true` | install `bind9` |
| `ispcp_manage_service` | secondaries | `true` | enable and start `named` |

## Set up a secondary by hand

On the ISPConfig DNS server run `ispconfig-dns-catalog export` and, for NOTIFY, `ispconfig-dns-catalog secondary add
<secondary IP>`. On a BIND 9.18+ secondary, with the exported values:

```
# /etc/bind/ispcp-catalog-keys.conf (root:bind 0640), included at the top of named.conf
key "ispcp-catalog.ns1.example.com" { algorithm hmac-sha256; secret "<secret>"; };

# inside options { } of named.conf.options
catalog-zones {
    zone "catalog.ns1.example.com.invalid" default-primaries { 203.0.113.10 key "ispcp-catalog.ns1.example.com"; }
        zone-directory "catz" in-memory no min-update-interval 10;
};

# /etc/bind/named.conf.ispcp-catalog, included from named.conf
zone "catalog.ns1.example.com.invalid" {
    type secondary;
    primaries { 203.0.113.10 key "ispcp-catalog.ns1.example.com"; };
    file "catz/catalog.ns1.example.com.invalid.db";
};
```

Create `/var/cache/bind/catz` owned by `bind`, check with `named-checkconf` and run `rndc reconfig`. Knot DNS 3.x and
PowerDNS 4.7+ also consume catalog zones version 2; configure them with the same catalog name, primary addresses and key.

Allow DNS (TCP and UDP port 53) from the secondary to the primary for transfers, and from the primary to the secondary
for NOTIFY. Add the secondary to the zones' NS records (for example in ISPConfig's DNS templates) when it should answer
publicly.

## Check it

```sh
# On the primary: the catalog over TSIG (without the key the transfer is refused)
dig @127.0.0.1 -y hmac-sha256:ispcp-catalog.ns1.example.com:<secret> catalog.ns1.example.com.invalid AXFR
# On the secondary: zones it provisioned, and one of them
rndc zonestatus example.com
dig @127.0.0.1 example.com SOA +norec      # answers with the aa flag
```

The secondary logs `catz: adding zone ... from catalog` and `catz: deleting zone ...`.

## Remove

Stop the tool with `systemctl disable --now ispconfig-dns-catalog.path ispconfig-dns-catalog.timer`, remove the
`include "/etc/bind/named.conf.ispcp-catalog";` line from `named.conf`, the key from `allow-transfer` and the line marked
`// ispconfig-dns-catalog` from `named.conf.options` and its conf-custom copy, then `rndc reconfig`. Remove the catalog on
the secondaries first, or they delete every member zone when the catalog disappears.

## Tests

```sh
php vendor/bin/phpunit --filter DnsCatalog
bash tests/Integration/dns-catalog/run.sh   # disposable BIND 9.18 primary + secondary + Ansible controller (Docker)
```

The integration check installs the tool on an ISPConfig-like primary, configures a plain secondary with the playbook
(twice, the second run changing nothing) and verifies provisioning, a zone left out for its own transfer list, a new
zone, record changes and removal.
