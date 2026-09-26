import importlib.util
import os
import pathlib
import subprocess
import json

spec = importlib.util.spec_from_file_location('helper', '/fixture/helper/reconcile.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
for path in ['/etc/ispcp-files/keys', '/var/lib/ispcp-files/jails']:
    pathlib.Path(path).mkdir(parents=True, exist_ok=True)
subprocess.run(['groupadd', '--system', 'ispcp-files'], check=True)
key = pathlib.Path('/fixture/key.pub').read_text().strip()
for number in [20001, 20002]:
    group = 'client' + str(number)
    user = 'web' + str(number)
    subprocess.run(['groupadd', '-g', str(number), group], check=True)
    subprocess.run(['useradd', '-u', str(number), '-g', group, '-M', user], check=True)
    root = pathlib.Path('/var/www/clients') / group / user
    root.mkdir(parents=True)
    (root/'web').mkdir(mode=0o750)
    os.chown(root/'web', number, number)
    (root/'web'/'hello.txt').write_text('hello '+str(number))
    os.chown(root/'web'/'hello.txt', number, number)
    (root/'private').mkdir(mode=0o710)
    os.chown(root/'private', number, number)
    (root/'private'/'secret.txt').write_text('outside-web-root')
    os.symlink('/etc/passwd', root/'web'/'escape')
    os.symlink('../private', root/'web'/'sibling')
    site = {'id': number, 'server_id': 1, 'sys_groupid': number, 'domain': str(number)+'.test', 'type':'vhost', 'document_root':str(root), 'web_folder':'', 'system_user':user, 'system_group':group}
    original_mkdir = helper.os.mkdir
    def immutable_parent(path, mode=0o777, *, dir_fd=None):
        if dir_fd is not None:
            parent = os.fstat(dir_fd)
            if (parent.st_dev, parent.st_ino) == (root.stat().st_dev, root.stat().st_ino):
                raise PermissionError('immutable website parent')
        return original_mkdir(path, mode, dir_fd=dir_fd)
    helper.os.mkdir = immutable_parent
    try:
        helper.provision(site, 'restrict '+key)
    finally:
        helper.os.mkdir = original_mkdir
    pathlib.Path('/fixture/output/'+str(number)+'.json').write_text(json.dumps(site))
# A vhost sharing the primary site's UID still gets its own web/trash mounts.
root = pathlib.Path('/var/www/clients/client20001/web20001')
(root/'subweb').mkdir(mode=0o750)
os.chown(root/'subweb', 20001, 20001)
(root/'subweb'/'hello.txt').write_text('hello shared-uid vhost')
os.chown(root/'subweb'/'hello.txt', 20001, 20001)
site = {'id':20003, 'server_id':1, 'sys_groupid':20001, 'domain':'sub.test', 'type':'vhostsubdomain', 'document_root':str(root), 'web_folder':'subweb', 'system_user':'web20001', 'system_group':'client20001'}
helper.provision(site, 'restrict '+key)
pathlib.Path('/fixture/output/20003.json').write_text(json.dumps(site))
# Existing symlinks in the reserved private location fail closed without chmod
# or writes through the link. No account key may survive failed reconciliation.
os.symlink(root/'web', root/'private'/'.ispcp-trash-20004')
try:
    helper.provision(dict(site, id=20004), 'restrict '+key)
    raise AssertionError('unsafe trash path accepted')
except OSError:
    assert not (helper.ETC/'keys'/'ispcp-fm-20004').exists()
assert (root/'web').stat().st_mode & 0o777 == 0o750
pathlib.Path('/etc/ssh/sshd_config').write_text('Port 22\nHostKey /etc/ssh/ssh_host_ed25519_key\nUsePAM no\nSubsystem sftp internal-sftp\nInclude /fixture/helper/sshd.conf\n')
pathlib.Path('/fixture/output/hostkey.pub').write_text(pathlib.Path('/etc/ssh/ssh_host_ed25519_key.pub').read_text())
os.execv('/usr/sbin/sshd', ['/usr/sbin/sshd', '-D', '-e'])
