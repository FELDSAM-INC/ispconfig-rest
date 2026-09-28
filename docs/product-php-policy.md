# Administrator product PHP policy

`POST /api/v1/clients` and `PUT /api/v1/clients/{id}` accept `web_php_policy` from
administrator keys only. Omission preserves the policy; `null` removes it.
Client and reseller writes, including explicit null, are forbidden. GET detail,
create and update responses return the stored policy for provisioning confirmation.
See `api/components/schemas/Client.yaml` for all required fields and validation.

Apply migration `2026_09_28_000003_create_client_web_php_policies.php` before use.
It adds only API-owned tables for policies and original website settings. This is
an unreleased feature after 1.0.2. No additional privileged worker is required.
The existing PHP configuration snapshot worker continues to supply inherited
values in website detail responses.

A policy contains `force_fpm` (opt-in in WHMCS), native `php_fpm_use_socket`,
`php_fpm_chroot`, `pm` (ondemand/dynamic), the six native `pm_*` settings, and `ini`
with memory_limit, max_execution_time, max_input_time, post_max_size and
upload_max_filesize. It is not a raw INI or vhost-directive input.

The client transaction reapplies client templates, preserves the FPM restriction,
and updates all owned primary and child vhosts atomically with complete datalog
entries. New and edited vhosts receive the policy through WebDomainService even
when the caller uses a scoped client key. Shared aliases are not independent PHP
runtimes. Applying the policy does not require a fresh worker heartbeat.

INI limits use a delimited block appended after existing custom directives.
Other settings are preserved; removal restores saved native pool fields and
removes the block. PHP mode/version are saved only when Force PHP-FPM manages
them. Compatible FPM versions are retained; unsupported versions fall back to an
available FPM default/version. Conflicting required PHP snippets, sectioned INI,
invalid pool relationships and malformed managed markers abort the transaction.
Literal numeric 0/1 are quoted as "0"/"1" because ISPConfig otherwise renders them
as boolean php_admin_flag entries in the FPM pool.

The server's native ISPConfig plugins generate Apache/nginx pools, socket and
chroot configuration. Native ISPConfig-created websites bypass the REST creation
hook and need a subsequent REST edit or product reapply. Administrator changes
outside REST are not continuously reconciled. Required snippet conflicts must be
resolved by the administrator. Global server PHP policy and jail prerequisites
remain under server administrator control.

## Verification

Automated coverage checks administrator/client/reseller boundaries, atomic package
changes, primary and child vhosts, preserved customer PHP preferences, conflicting
snippets, restoration, invalid pool configuration, and cleanup. The real FPM
fixture in `tests/Integration/web-php-settings/check.php` exercises both process
managers with sockets/TCP and chroot on/off, including numeric 0/1 INI values and
an attempted application `ini_set()` override.

On 2026-09-28 the development ISPConfig server generated and served a temporary
primary site, vhost subdomain and vhost alias with the five product limits. All
three passed HTTP checks in ondemand/socket mode and again after changing the
product policy to dynamic/socket/chroot. Native pool files matched the policy,
and application changes to the memory limit were denied. The temporary client
and its domains/vhosts were deleted through REST afterwards.
