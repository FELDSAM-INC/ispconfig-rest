"""Exercise generated web/FPM configurations in a disposable root container."""
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
import ssl

from test_installer_security import installer_function


@unittest.skipUnless(os.environ.get('ISPC_REST_TEST_WEB_STACK') == '1' and os.geteuid() == 0,
                     'Opt-in disposable web-stack container required')
class IsolatedWebTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='ispconfig-web-')
        cls.addClassCleanup(cls.temporary.cleanup)
        cls.root = Path(cls.temporary.name)
        cls.root.chmod(0o755)
        cls.user = 'ispconfig-web-' + str(os.getpid())
        subprocess.run(['useradd', '--system', '--user-group', '--no-create-home', cls.user], check=True)
        cls.addClassCleanup(subprocess.run, ['userdel', cls.user], check=False, capture_output=True)
        cls.identity = pwd.getpwnam(cls.user)
        cls.name = 'ispconfig-web-test-' + str(os.getpid())
        cls.run_dir = Path('/run') / cls.name
        cls.logs = Path('/var/log') / cls.name
        cls.run_dir.mkdir()
        cls.addClassCleanup(shutil.rmtree, cls.run_dir, ignore_errors=True)
        cls.logs.mkdir()
        cls.addClassCleanup(shutil.rmtree, cls.logs, ignore_errors=True)
        cls.public = cls.root / 'app/public'
        cls.public.mkdir(parents=True)
        (cls.public / 'index.php').write_text('<?php echo json_encode(["ok"=>true,"uid"=>posix_geteuid()]);')
        (cls.public / 'other.php').write_text('<?php echo "UNEXPECTED_SCRIPT";')
        (cls.public / 'OTHER.PHP').write_text('<?php echo "UNEXPECTED_SCRIPT";')
        (cls.public / 'nested').mkdir()
        (cls.public / 'nested/index.php').write_text('<?php echo "UNEXPECTED_SCRIPT";')
        (cls.public / '.private').write_text('PRIVATE_FIXTURE')
        cls.state = cls.root / 'state'
        cls.state.mkdir()
        for name in ['body', 'fastcgi']:
            path = cls.state / name
            path.mkdir(mode=0o700)
            os.chown(path, cls.identity.pw_uid, cls.identity.pw_gid)
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                        '-subj', '/CN=localhost', '-keyout', str(cls.root / 'ispserver.key'),
                        '-out', str(cls.root / 'ispserver.crt')], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.sock = cls.root / 'api.sock'
        cls.environment = dict(os.environ, RUN_USER=cls.user, SERVICE_NAME=cls.name, WEB_SERVICE=cls.name,
                               WEB_STATE=str(cls.state), INSTALL_DIR=str(cls.root / 'app'),
                               ISPCONFIG_SSL_DIR=str(cls.root), FPM_SOCK=str(cls.sock),
                               FPM_POOL_DIR=str(cls.root), FPM_SERVICE='php8.3-fpm', HOSTNAME_FQDN='localhost')
        subprocess.run(['bash', '-euc', 'systemctl(){ :; }; secure_fpm_socket(){ :; }; ok(){ :; };\n' +
                        installer_function('write_fpm_pool') + '\nwrite_fpm_pool'],
                       env=cls.environment, check=True)
        config = cls.root / 'fpm.conf'
        config.write_text('[global]\nerror_log=' + str(cls.root / 'fpm.log') + '\n' +
                          (cls.root / (cls.name + '.conf')).read_text())
        cls.fpm_output = (cls.root / 'fpm-output.log').open('w')
        cls.addClassCleanup(cls.fpm_output.close)
        cls.fpm = subprocess.Popen(['php-fpm8.3', '-F', '-y', str(config)],
                                   stdout=cls.fpm_output, stderr=subprocess.STDOUT)
        cls.addClassCleanup(cls.stop_process, cls.fpm)
        for _ in range(100):
            if cls.sock.exists():
                break
            if cls.fpm.poll() is not None:
                raise RuntimeError((cls.root / 'fpm-output.log').read_text())
            time.sleep(0.05)
        else:
            raise RuntimeError('FPM socket did not become ready')

    @staticmethod
    def stop_process(process):
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

    def test_shared_web_identity_cannot_connect_to_private_fpm_socket(self):
        stat = self.sock.stat()
        self.assertEqual(stat.st_uid, self.identity.pw_uid)
        self.assertEqual(stat.st_mode & 0o777, 0o600)
        result = subprocess.run(['runuser', '-u', 'www-data', '--', 'python3', '-c',
                                 'import socket,sys; socket.socket(socket.AF_UNIX).connect(sys.argv[1])', str(self.sock)],
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('PermissionError', result.stderr)

    def exercise_server(self, kind, environment=None):
        with socket.socket() as port:
            port.bind(('127.0.0.1', 0))
            number = port.getsockname()[1]
        env = dict(environment or self.environment, PUBLIC_PORT=str(number))
        # This optional bundle helper is a one-line function in the installer.
        generator = 'ssl_bundle_line_apache(){ :; };\n' + installer_function(kind + '_config')
        generated = subprocess.run(['bash', '-euc', generator + '\n' + kind + '_config'],
                                   env=env, check=True, capture_output=True, text=True).stdout
        config = self.root / (kind + '.conf')
        config.write_text(generated)
        command = (['nginx', '-c', str(config), '-g', 'daemon off;'] if kind == 'nginx'
                   else ['apache2', '-f', str(config), '-DFOREGROUND'])
        output_path = self.root / (kind + '-output.log')
        with output_path.open('w') as output:
            process = subprocess.Popen(command, stdout=output, stderr=subprocess.STDOUT)
            try:
                context = ssl.create_default_context(cafile=str(self.root / 'ispserver.crt'))
                for _ in range(100):
                    try:
                        with urllib.request.urlopen('https://localhost:' + str(number) + '/api/test',
                                                    context=context, timeout=1) as response:
                            body = json.load(response)
                        break
                    except (urllib.error.URLError, TimeoutError):
                        if process.poll() is not None:
                            self.fail(output_path.read_text())
                        time.sleep(0.05)
                else:
                    self.fail('HTTPS endpoint did not become ready: ' + output_path.read_text())
                self.assertEqual(body, {'ok': True, 'uid': self.identity.pw_uid})
                for path in ['/other.php', '/OTHER.PHP', '/nested/index.php', '/.private']:
                    with self.assertRaises(urllib.error.HTTPError) as error:
                        urllib.request.urlopen('https://localhost:' + str(number) + path,
                                               context=context, timeout=2)
                    self.assertIn(error.exception.code, [403, 404])
            finally:
                self.stop_process(process)

    def test_nginx_serves_only_the_api_entry_point(self):
        self.exercise_server('nginx')

    def test_apache_serves_only_the_api_entry_point(self):
        self.exercise_server('apache')

    def test_existing_fpm_socket_changes_owner_on_reload(self):
        directory = self.root / 'migration'
        directory.mkdir()
        sock = self.root / 'migration.sock'
        pool = directory / (self.name + '.conf')
        original = (self.root / (self.name + '.conf')).read_text()
        pool.write_text(original.replace(self.user, 'www-data').replace(str(self.sock), str(sock))
                        .replace('listen.mode = 0600', 'listen.mode = 0660'))
        config = directory / 'fpm.conf'
        log = directory / 'fpm.log'
        config.write_text('[global]\nerror_log=' + str(log) + '\ninclude=' + str(pool) + '\n')
        with (directory / 'output.log').open('w') as output:
            process = subprocess.Popen(['php-fpm8.3', '-F', '-y', str(config)],
                                       stdout=output, stderr=subprocess.STDOUT)
            try:
                for _ in range(100):
                    if sock.exists(): break
                    time.sleep(0.05)
                self.assertTrue(sock.exists())
                before = sock.stat()
                self.assertEqual(before.st_uid, pwd.getpwnam('www-data').pw_uid)
                env = dict(self.environment, FPM_SOCK=str(sock), FPM_POOL_DIR=str(directory),
                           FPM_TEST_PID=str(process.pid))
                # A real SIGUSR2 reload inherits the old listening descriptor. A fresh-start-only
                # fixture cannot catch its stale owner/mode after moving away from www-data.
                script = ('systemctl(){ [ "$1" = reload ]; kill -USR2 "$FPM_TEST_PID"; };\n'
                          'ok(){ :; }; die(){ echo "$*" >&2; exit 1; };\n' +
                          installer_function('secure_fpm_socket') + '\n' +
                          installer_function('write_fpm_pool') + '\nwrite_fpm_pool')
                subprocess.run(['bash', '-euc', script], env=env, check=True)
                for _ in range(100):
                    if log.read_text().count('ready to handle connections') >= 2: break
                    time.sleep(0.05)
                self.assertEqual(sock.stat().st_ino, before.st_ino, 'Must not restart shared FPM pools')
                self.assertEqual(sock.stat().st_uid, self.identity.pw_uid)
                self.assertEqual(sock.stat().st_gid, self.identity.pw_gid)
                self.assertEqual(sock.stat().st_mode & 0o777, 0o600)
                self.exercise_server('apache', env)
                self.exercise_server('nginx', env)
            finally:
                self.stop_process(process)
