# 055 — DNS catalog zones for secondaries

Owner request (2026-10-10): secondaries of the ISPConfig BIND servers should be synchronised by a catalog zone, also
for zones created in the ISPConfig interface. Generic server tool; the secondary is set up from an installation manual,
preferably an Ansible playbook. No secondary address is known yet.

## Findings (ISPConfig 3.3.1p1, BIND 9.18.39)

- `bind_plugin` rewrites the whole `named.conf.local` on every dns_soa/dns_slave change: active zones of the server
  whose zone file exists, with per-zone `allow-transfer`/`also-notify`/`allow-update` from the zone's fields. Nothing
  can be added to that file.
- `configure_bind()` of the installer (also on updates that reconfigure services) rewrites `named.conf.options`, unless
  `server/conf-custom/install/named.conf.options.master` exists. `named.conf` is never touched.
- A per-zone `allow-transfer` replaces the global list, so such a zone cannot be transferred by the catalog's secondary.
- Verified with disposable BIND 9.18 containers: a secondary provisions, updates and deletes member zones from the
  catalog alone, over TSIG, without `allow-new-zones`.

## Decisions (owner-confirmed or owner-delegated)

- Catalog name `catalog.<server name>.invalid` (owner: fine), fixed after installation.
- Zones with their own transfer list are left out (owner: they are meant for another secondary); also-notify is not
  examined.
- Server tool `dns-catalog`, opt-in, routed to DNS servers only (new `dns` role in the server-tools inventory). It reads
  `named-checkconf -p named.conf.local`, so the members are what BIND serves, whatever created them; no database access.
  systemd path unit on `named.conf.local` plus a five-minute timer; the zone file is rewritten only when membership
  changes (serial `max(previous + 1, now)`), checked with `named-checkzone`, then `rndc reload` of the catalog.
- TSIG key `ispcp-catalog.<server name>` (hmac-sha256) authorises transfers of the catalog and, through the global
  `allow-transfer` of `named.conf.options` (and its conf-custom copy), of every member zone. No secondary address needed.
- `secondary add|remove IP` maintains NOTIFY targets: the catalog's `also-notify` and a marked global `also-notify` line
  for member zones (hidden secondaries). An administrator's own global also-notify list is left alone.
- Installation is validated with `named-checkconf` and rolled back on refusal; a second run keeps key and name.
- Secondary: Ansible playbook `dns-catalog/ansible/secondary.yml` (export from the primaries, NOTIFY registration,
  BIND keys, catalog zones, `catalog-zones` block via blockinfile, check, reload) and a manual BIND configuration.
- Mirror servers are skipped by server-tools as for every component.
