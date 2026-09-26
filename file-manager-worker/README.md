# File manager worker

Optional server component for the WHMCS file manager. Install it on
**each ISPConfig webserver** whose websites should offer file management, even
when the REST API runs only on the master. Like the database worker, the installed
code is root-owned and runs once a minute. The file manager UI, customer authorization
and SFTP client stay in WHMCS; this worker exposes no HTTP endpoint.

## Install or upgrade

Requires Linux, Python 3.8+, PHP CLI with pdo_mysql/posix, OpenSSH internal-sftp
and DisableForwarding, openssl, mount, logger and standard account tools.

1. Generate a dedicated Ed25519 key on WHMCS, outside its document root, readable
   only by its PHP user. Copy **only the public key** to the webserver.
2. Copy this directory to a trusted root-owned staging directory on the webserver.
   Do not run a web-writable API checkout from root cron. From the staging directory:

   ```sh
   sudo sh install.sh /root/whmcs-file-manager.pub WHMCS_EGRESS_IP
   ```

3. Obtain the server's public SSH host key through a trusted administrator
   connection. Configure WHMCS's private `ISPCP_FILE_MANAGER_CONFIG` JSON mapping
   with the webserver address, pinned host key and private key path. Select the
   same IP family as the allowed WHMCS egress address.

Re-run the installer from the updated REST release to upgrade. Installed paths:

| Purpose | Path |
| --- | --- |
| Root-owned code | `/usr/local/lib/ispconfig-rest-file-manager-worker/` |
| One-minute cron | `/etc/cron.d/ispconfig-rest-file-manager-worker` |
| Configuration and public keys | `/etc/ispcp-files/` |
| Jails and shared reconciliation lock | `/var/lib/ispcp-files/` |
| Dedicated accounts | `ispcp-fm-<website ID>` |
| Diagnostics | syslog tag `ispconfig-rest-file-manager-worker` |

The installer validates SSH before reloading `ssh.service`. When upgrading the
original helper distributed in WHMCS, it replaces the recognized
`/etc/cron.d/ispcp-files` job. Both versions use the same lock. Existing private
configuration, public keys, account names, jails and bind mounts are preserved;
no customer file permissions change. Old code under `/usr/local/lib/ispcp-files`
is no longer scheduled and can be removed after checking the new installation.

## Security and operation

The worker reads local active vhost/vhostsubdomain/vhostalias identities from
ISPConfig's database using its local server configuration. It never writes
ISPConfig tables or consumes client SSH-account limits. Root-owned website
parents, website directory ownership and symlink-free paths are checked before
each account's key is enabled. Inactive/deleted websites lose their keys; invalid
identities or paths fail closed. A failed website is logged by ID without
credentials or customer file contents. Other websites still reconcile.

Each login shares the website's UID/GID to preserve ownership and filesystem
quotas. Its root-owned OpenSSH chroot contains only a bind mount of that website's
web directory, a private trash mount and a read-only identity fingerprint. No shell, PTY, forwarding,
password login or user rc is allowed. The public key is restricted to the WHMCS
egress IP. Normal website/SSH accounts are unaffected. Vhost aliases/subdomains
have separate jails for their web folders. Mounts are restored after reboot.

The private trash directory is `<document_root>/private/.ispcp-trash-<website ID>`,
mode 0700, owned by the website UID/GID. It is outside the public web folder and
bind-mounted as `/trash`. Creation runs as the website UID through a pinned
directory descriptor; the ISPConfig parent remains immutable. The existing
private directory must be owned by the website UID/GID, must not be writable
by group/others, and must share the web directory’s filesystem. Even vhosts sharing a UID get separate trash mounts.
Existing unsafe owners, permissions or symlinks fail closed, without changing
customer directory permissions. Reserved trash paths cannot be used as web
folders. Trash remains charged to the site's filesystem quota; it is not purged
automatically. Updating the worker is required before using WHMCS trash/restore.
WHMCS verifies a recovery copy before removing originals; separate bind mounts
require copying (OpenSSH `copy-data` where supported), with temporary extra disk
space. The worker never reads or deletes trash contents as root.

The `/identity` fingerprint is SHA-256 of newline-joined fields, with null values
represented as empty strings: `id`, `server_id`, `sys_groupid`, `domain`, `type`,
`document_root`, `web_folder`, `system_user`, `system_group`. WHMCS checks this
against the currently authorized website after authenticating the pinned server.

Abandoned `.ispcp-upload-<32 hex digits>` files older than 24 hours are cleaned in
a chrooted process running as the website user, once daily, with bounded traversal.
Large trees may need manual cleanup. The worker does not raise filesystem quotas.

## Disable or remove

First disable the server in WHMCS's private file-manager configuration. Remove or
disable `/etc/cron.d/ispconfig-rest-file-manager-worker` (and any recognized legacy
job) before removing authorized keys; otherwise reconciliation recreates them.
Unmount both `jail/web` and `jail/trash` before removing a jail. **Never recursively delete a
mounted jail: it contains customer files.** Remove only dedicated `ispcp-fm-*`
accounts without `userdel -r`, because their UID is shared with a website.
Remove the dedicated SSH include only after validating with `sshd -t`.

The disposable OpenSSH fixture lives in `tests/Integration/file-manager-worker`.
The WHMCS repository's `tests/Integration/file-manager/sftp.php` exercises it on
PHP 7.4/8.3, including raw SFTP escape attempts and forced-command enforcement.
