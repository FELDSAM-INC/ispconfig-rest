#!/usr/bin/python3
"""Root-owned jail provisioning. No browser inputs, API key, shell expansion or data-plane root process."""
import fcntl
import grp
import hashlib
import json
import os
import pathlib
import pwd
import re
import secrets
import stat
import subprocess
import sys
import time

BASE = pathlib.Path('/var/lib/ispcp-files')
ETC = pathlib.Path('/etc/ispcp-files')
HERE = pathlib.Path('/usr/local/lib/ispconfig-rest-file-manager-worker')


def run(args, **kwargs):
    return subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)


def binding(site):
    fields = ['id', 'server_id', 'sys_groupid', 'domain', 'type', 'document_root', 'web_folder', 'system_user', 'system_group']
    return hashlib.sha256('\n'.join(str(site.get(k) if site.get(k) is not None else '') for k in fields).encode()).hexdigest()


def open_directory(path):
    """Hold each component by descriptor, rejecting symlinks even during concurrent renames."""
    if not path.startswith('/') or '..' in path.split('/'):
        raise ValueError('invalid directory')
    fd = os.open('/', os.O_PATH | os.O_DIRECTORY)
    try:
        for part in path.strip('/').split('/'):
            if not part or part == '.':
                raise ValueError('invalid directory')
            child = os.open(part, os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
        return fd
    except BaseException:
        os.close(fd)
        raise


def atomic(path, value, mode=0o600):
    temporary = path.with_name(path.name + '.new-' + secrets.token_hex(8))
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(descriptor, 'w') as stream:
        os.chmod(temporary, mode)
        stream.write(value)
    os.replace(temporary, path)


def provision(site, key):
    website = int(site['id'])
    if website < 1:
        raise ValueError('invalid website')
    name = 'ispcp-fm-' + str(website)
    auth = ETC / 'keys' / name
    # Revoke before any identity lookup; failed/deleted Linux identities must not retain access.
    auth.unlink(missing_ok=True)
    if website < 1 or not re.fullmatch(r'web[0-9]+', site['system_user']) or not re.fullmatch(r'client[0-9]+', site['system_group']):
        raise ValueError('invalid identity')
    user = pwd.getpwnam(site['system_user'])
    group = grp.getgrnam(site['system_group'])
    if user.pw_uid < 1000 or group.gr_gid < 1000 or user.pw_gid != group.gr_gid:
        raise ValueError('unsafe identity')
    root = site['document_root'].rstrip('/')
    folder = site.get('web_folder') if site['type'] != 'vhost' else 'web'
    if not folder or not re.fullmatch(r'[a-zA-Z0-9_./-]+', folder) or folder.startswith('/'):
        raise ValueError('invalid web folder')
    source = root + '/' + folder
    jail = BASE / 'jails' / name
    rootfd = open_directory(root)
    fd = None
    try:
        rootstat = os.fstat(rootfd)
        if rootstat.st_uid != 0 or rootstat.st_mode & 0o022:
            raise ValueError('website parent must be root owned')
        # Resolve below the already pinned root descriptor, not the potentially renamed path.
        fd = os.dup(rootfd)
        for part in folder.split('/'):
            if part in ('', '.', '..'):
                raise ValueError('invalid web folder')
            child = os.open(part, os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
        source_stat = os.fstat(fd)
        if source_stat.st_uid != user.pw_uid:
            raise ValueError('web directory owner changed')
        jail.mkdir(mode=0o755, parents=True, exist_ok=True)
        jailfd = open_directory(str(jail))
        jailstat = os.fstat(jailfd)
        os.close(jailfd)
        if jailstat.st_uid != 0 or jailstat.st_mode & 0o022:
            raise ValueError('unsafe jail directory')
        try:
            account = pwd.getpwnam(name)
            if account.pw_uid != user.pw_uid or account.pw_gid != group.gr_gid or account.pw_dir != str(jail) or account.pw_shell != '/usr/sbin/nologin':
                raise ValueError('account identity changed')
        except KeyError:
            # A random unknown password hash keeps OpenSSH public-key auth usable; password authentication is forbidden.
            password = run(['/usr/bin/openssl', 'passwd', '-6', '-stdin'], input=secrets.token_hex(48).encode()).stdout.decode().strip()
            run(['/usr/sbin/useradd', '--non-unique', '--uid', str(user.pw_uid), '--gid', str(group.gr_gid), '--groups', 'ispcp-files', '--no-create-home', '--home-dir', str(jail), '--shell', '/usr/sbin/nologin', '--password', password, name])
        target = jail / 'web'
        target.mkdir(mode=0o755, exist_ok=True)
        current = target.stat()
        if (current.st_dev, current.st_ino) != (source_stat.st_dev, source_stat.st_ino):
            if os.path.ismount(target):
                run(['/usr/bin/umount', str(target)])
            run(['/usr/bin/mount', '--no-canonicalize', '--bind', '/proc/self/fd/' + str(fd), str(target)], pass_fds=(fd,))
            run(['/usr/bin/mount', '-o', 'remount,bind,nosuid,nodev,noexec', str(target)])
        atomic(jail / 'identity', binding(site) + '\n', 0o644)
        atomic(auth, key + '\n', 0o644)
        stamp = jail / 'cleanup-at'
        if not stamp.exists() or stamp.stat().st_mtime < time.time() - 86400:
            child = os.fork()
            if child == 0:
                try:
                    os.chroot(jail)
                    os.chdir('/web')
                    os.setgroups([])
                    os.setgid(group.gr_gid)
                    os.setuid(user.pw_uid)
                    started = time.monotonic()
                    count = 0
                    for directory, dirs, files in os.walk('/web', followlinks=False):
                        if time.monotonic() - started > 5 or count > 10000:
                            break
                        count += len(files) + len(dirs)
                        for name in files:
                            if re.fullmatch(r'\.ispcp-upload-[a-f0-9]{32}', name):
                                path = os.path.join(directory, name)
                                info = os.lstat(path)
                                if stat.S_ISREG(info.st_mode) and info.st_uid == user.pw_uid and info.st_mtime < time.time() - 86400:
                                    os.unlink(path)
                finally:
                    os._exit(0)
            os.waitpid(child, 0)
            atomic(stamp, str(int(time.time())))
    finally:
        if fd is not None:
            os.close(fd)
        os.close(rootfd)


def main():
    if os.geteuid() != 0:
        raise SystemExit('Run the installed helper as root.')
    lock = open(BASE / 'reconcile.lock', 'w')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        return
    config = json.loads((ETC / 'config.json').read_text())
    public = (ETC / 'client.pub').read_text().strip().split()
    if len(public) < 2 or public[0] not in ('ssh-ed25519', 'ssh-rsa'):
        raise ValueError('invalid public key')
    # from= is a further restriction; only the configured WHMCS egress IP can use this key.
    import ipaddress
    source = str(ipaddress.ip_address(config['whmcs_ip']))
    key = 'restrict,from="' + source + '" ' + public[0] + ' ' + public[1]
    sites = json.loads(run(['/usr/bin/php', str(HERE / 'sites.php')]).stdout)
    active = {'ispcp-fm-' + str(int(site['id'])) for site in sites}
    for path in (ETC / 'keys').iterdir():
        if path.name not in active:
            path.unlink()
    failed = False
    for site in sites:
        try:
            provision(site, key)
        except Exception:
            failed = True
            print('File manager jail unavailable for website ' + str(int(site['id'])), file=sys.stderr)
    if failed:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
