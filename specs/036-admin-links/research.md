# Research: Administration and File-Transfer Links for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-16.

## R1 — Where the links belong

**Options**: (a) a `links` block in `GET /me/capabilities`; (b) a block in `GET /me/mail-settings`; (c) a separate
`GET /me/hosting-links`.

**Findings**: `/me/capabilities` answers "what may this account do" — plan rules, not installation addresses (spec
021/025/035). `/me/mail-settings` is mail-specific and already carries the webmail address, the closest precedent:
spec 025 deliberately kept the installation's mail addresses out of the capabilities endpoint. The WHMCS module
(spec 006) shows these buttons only on its database and FTP pages, so an extra read costs nothing on other pages.

**Decision** (owner-delegated 2026-09-16): (c) `GET /me/hosting-links`, mirroring `/me/mail-settings` in shape,
target resolution (`AccountCapabilitiesService::resolveTarget()`, `ReadsAccountQuery`) and error behaviour.

## R2 — What legacy shows and where it comes from

| Setting | Legacy use | Placeholders |
|---|---|---|
| `phpmyadmin_url` | `sites/database_phpmyadmin.php:63-66` redirects to it | `[SERVERNAME]` → the database's server name, `[DATABASENAME]` → the database name |
| `dblist_phpmyadmin_link` | `sites/database_list.php:72` shows the per-database link only when `y` (and the database type is MySQL) | — |
| `webftp_url` | `sites/ftp_user_list.php:58-60` passes the value to the template unchanged when it is not empty | none — legacy substitutes nothing |
| `phppgadmin_url` | `sites/database_phppgadmin.php:70`, PostgreSQL only | out of scope |

All four live in the `[sites]` section of the `sys_ini` blob. isp-test has
`phpmyadmin_url=https://[SERVERNAME]:8081/phpmyadmin`, `dblist_phpmyadmin_link=y` and an empty `webftp_url`.

**Decision**: report the phpMyAdmin address per database server with `[SERVERNAME]` resolved and `[DATABASENAME]`
left in place (the API cannot know which database a link is for — the same reasoning that leaves `[DOMAINID]`
unresolved in the spec 035 prefixes), and the file-transfer address verbatim.

## R3 — Why the settings are not readable today

`SystemSitesConfig.yaml` states the exposed `[sites]` subset explicitly and excludes `phpmyadmin_url` and
`webftp_url`; `SystemConfigService::fieldMap()` does not list them either, so they are preserved untouched by a PUT
but never returned. The whole `system` module is admin-only for scoped keys (spec 011), so a customer key has no
path to them at all.

**Decision**: expose only the two resolved links through the new account endpoint, not through the system
configuration schema. The admin configuration surface stays unchanged, exactly as spec 035 exposed resolved prefixes
without exposing `webdavuser_prefix` as configuration.

## R4 — Which servers to list

The link is per database server. Spec 031 established the composition for an account's servers: the valid assigned
servers of the role in assignment order (`ServerAssignmentService::assignedServerIds()`, which skips mirrors,
missing servers and servers without the role flag), then the non-mirror servers of that role hosting the client's
rows, by id (`HostingAddressService::hostingServerIds()`).

**Decision**: apply that rule with the `db` role (`client.db_servers`, `server.db_server`) and the `web_database`
table, so every database the key can read has a matching entry, including databases on a server the account is not
assigned to. `server_name` is the only server data returned, as in spec 031.

## R5 — `available` semantics

`database_phpmyadmin.php` redirects whenever `phpmyadmin_url` is set, but the per-database link a panel would show
appears only when `dblist_phpmyadmin_link = y` (`database_list.php:72`).

**Decision**: `database_administration.available` is true when the link is switched on **and** an address is
configured, so a provider who turned the link off in ISPConfig does not see it reappear in the panel.
`file_transfer.available` is true when `webftp_url` is not empty. When a part is unavailable its address is `""` and,
for databases, the server list is still returned (the panel may cache it).

## R6 — Error and exposure boundary

Same as the other `/me` reads: 400 for an unknown query parameter, 401 without a key, 404 for a foreign or unknown
client, 422 when an admin key omits `client_id`. The response contains only `client_id`, the two links and the
account's database servers (`server_id`, `server_name`) — no other configuration value, no addresses, no other
client's resources. Nothing is written.
