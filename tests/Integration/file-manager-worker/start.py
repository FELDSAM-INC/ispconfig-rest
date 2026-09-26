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
    (root/'private').mkdir()
    (root/'private'/'secret.txt').write_text('outside-web-root')
    os.symlink('/etc/passwd', root/'web'/'escape')
    os.symlink('../private', root/'web'/'sibling')
    site = {'id': number, 'server_id': 1, 'sys_groupid': number, 'domain': str(number)+'.test', 'type':'vhost', 'document_root':str(root), 'web_folder':'', 'system_user':user, 'system_group':group}
    helper.provision(site, 'restrict '+key)
    pathlib.Path('/fixture/output/'+str(number)+'.json').write_text(json.dumps(site))
pathlib.Path('/etc/ssh/sshd_config').write_text('Port 22\nHostKey /etc/ssh/ssh_host_ed25519_key\nUsePAM no\nSubsystem sftp internal-sftp\nInclude /fixture/helper/sshd.conf\n')
pathlib.Path('/fixture/output/hostkey.pub').write_text(pathlib.Path('/etc/ssh/ssh_host_ed25519_key.pub').read_text())
os.execv('/usr/sbin/sshd', ['/usr/sbin/sshd', '-D', '-e'])
