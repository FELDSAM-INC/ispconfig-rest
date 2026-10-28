"""Exercise Composer bootstrap with mocked downloads and real PHP verification."""
import hashlib
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
PHP = shutil.which('php')
INSTALLER = (ROOT / 'install.sh').read_text()
BOOTSTRAP = 'install_composer() (' + INSTALLER.split('install_composer() (', 1)[1].split('\n)\n', 1)[0] + '\n)\n'


@unittest.skipUnless(PHP, 'PHP is required for checksum verification')
class ComposerBootstrapTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.download = self.root / 'download.php'
        self.download.write_text("<?php file_put_contents(getenv('EXECUTED'), 'yes'); "
                                 "file_put_contents(__DIR__.'/composer.phar', 'fixture-composer');")
        self.signature = self.root / 'signature'
        self.signature.write_text(hashlib.sha384(self.download.read_bytes()).hexdigest())
        self.wrapper = self.root / 'php-fixture'
        self.wrapper.write_text('''#!/usr/bin/env python3
import os, pathlib, sys
args = sys.argv[1:]
if args[0] == '-r' and 'copy(' in args[1]:
    url, target = args[2:]
    if os.environ.get('DOWNLOAD_FAIL') == url:
        sys.exit(1)
    path = pathlib.Path(target)
    if path.parent.stat().st_mode & 0o077:
        raise RuntimeError('Download directory is accessible to other users')
    source = 'SIGNATURE' if url.endswith('installer.sig') else 'DOWNLOAD'
    path.write_bytes(pathlib.Path(os.environ[source]).read_bytes())
    sys.exit(0)
os.execv(os.environ['REAL_PHP'], [os.environ['REAL_PHP'], *args])
''')
        self.wrapper.chmod(0o755)
        self.environment = dict(os.environ, TMPDIR=str(self.root), PHP_BIN=str(self.wrapper), REAL_PHP=PHP,
                                DOWNLOAD=str(self.download), SIGNATURE=str(self.signature),
                                EXECUTED=str(self.root / 'executed'))

    def bootstrap(self):
        result = subprocess.run(['bash', '-c', BOOTSTRAP + '\ninstall_composer ' + shlex.quote(str(self.root / 'composer'))],
                                env=self.environment, capture_output=True, text=True)
        self.assertEqual(list(self.root.glob('ispconfig-composer.*')), [], 'Private download directory was not cleaned')
        return result

    def test_valid_checksum_installs_repeatably_without_touching_shared_temporary_files(self):
        victim = self.root / 'victim'
        victim.write_text('unchanged')
        (self.root / 'composer-setup.php').symlink_to(victim)
        (self.root / 'composer.phar').symlink_to(victim)
        for _ in range(2):
            result = self.bootstrap()
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual((self.root / 'composer').read_text(), 'fixture-composer')
            self.assertEqual((self.root / 'composer').stat().st_mode & 0o777, 0o755)
            self.assertEqual(victim.read_text(), 'unchanged')
            self.assertTrue((self.root / 'composer-setup.php').is_symlink())

    def test_modified_download_is_never_executed_or_installed(self):
        self.download.write_text(self.download.read_text() + '\n// tampered')
        result = self.bootstrap()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('checksum verification failed', result.stderr)
        self.assertFalse((self.root / 'executed').exists())
        self.assertFalse((self.root / 'composer').exists())

    def test_invalid_or_empty_signature_is_rejected(self):
        for signature in ['', 'invalid', '0' * 96]:
            with self.subTest(signature=signature):
                self.signature.write_text(signature)
                self.assertNotEqual(self.bootstrap().returncode, 0)
                self.assertFalse((self.root / 'executed').exists())
                self.assertFalse((self.root / 'composer').exists())

    def test_download_failure_stops_before_execution_and_preserves_existing_installation(self):
        (self.root / 'composer').write_text('existing-composer')
        for url in ['https://composer.github.io/installer.sig', 'https://getcomposer.org/installer']:
            with self.subTest(url=url):
                self.environment['DOWNLOAD_FAIL'] = url
                self.assertNotEqual(self.bootstrap().returncode, 0)
                self.assertFalse((self.root / 'executed').exists())
                self.assertEqual((self.root / 'composer').read_text(), 'existing-composer')


def installer_function(name, subshell=False):
    opening, closing = ('(', ')') if subshell else ('{', '}')
    start = name + '() ' + opening
    return start + INSTALLER.split(start, 1)[1].split('\n' + closing + '\n', 1)[0] + '\n' + closing + '\n'


@unittest.skipUnless(PHP, 'PHP is required')
class InstallerPrivilegeTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.runtime = self.root / 'runtime'
        self.runtime.mkdir()
        self.stage = self.root / 'private'
        (self.stage / 'app').mkdir(parents=True)
        self.environment = dict(os.environ, PHP_BIN=PHP, INSTALL_DIR=str(self.runtime),
                                PRIVILEGED_STAGE=str(self.stage), DB_ADMIN_USER='fixture-admin',
                                DB_ADMIN_PASS='fixture-password', MARKER=str(self.root / 'marker'))

    def run_script(self, script, data=None):
        return subprocess.run(['bash', '-euc', script], env=self.environment,
                              input=data, capture_output=True, text=True)

    def test_environment_replaces_symlink_without_writing_or_chmodding_target(self):
        victim = self.root / 'victim'
        victim.write_text('unchanged')
        victim.chmod(0o640)
        (self.runtime / '.env').symlink_to(victim)
        script = 'run_as() { (cd "$INSTALL_DIR" && "$@"); }\n' + installer_function('write_runtime_env')
        for data in ['APP_KEY=first\n', 'APP_KEY=second\n']:
            result = self.run_script(script + 'write_runtime_env', data)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertFalse((self.runtime / '.env').is_symlink())
            self.assertEqual((self.runtime / '.env').read_text(), data)
            self.assertEqual((self.runtime / '.env').stat().st_mode & 0o777, 0o600)
            self.assertEqual(victim.read_text(), 'unchanged')
            self.assertEqual(victim.stat().st_mode & 0o777, 0o640)
            self.assertEqual(list(self.runtime.glob('.env-install-*')), [])

    def test_timezone_sync_replaces_symlink_without_changing_target(self):
        manager = (ROOT / 'bin' / 'ispconfig-rest').read_text()
        functions = ''
        for name in ['env_timezone', 'write_timezone', 'sync_timezone']:
            start = name + '(){'
            functions += start + manager.split(start, 1)[1].split('\n}\n', 1)[0] + '\n}\n'
        victim = self.root / 'victim'
        script = r'''run_as(){ (cd "$INSTALL_DIR" && "$@"); }
info(){ :; }
ok(){ :; }
warn(){ :; }
set_state(){ :; }
chown(){ echo 'Unexpected privileged chown' >&2; return 1; }
chmod(){ echo 'Unexpected privileged chmod' >&2; return 1; }
detect_system_timezone(){ echo Europe/Prague; }
valid_timezone(){ return 0; }
''' + functions + 'sync_timezone'
        for content in ['APP_KEY=keep\n', 'APP_KEY=keep\nAPP_TIMEZONE=UTC\n']:
            with self.subTest(content=content):
                victim.write_text(content)
                victim.chmod(0o640)
                original_uid = victim.stat().st_uid
                env = self.runtime / '.env'
                env.unlink(missing_ok=True)
                env.symlink_to(victim)
                result = self.run_script(script)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(victim.read_text(), content)
                self.assertEqual(victim.stat().st_mode & 0o777, 0o640)
                self.assertEqual(victim.stat().st_uid, original_uid)
                self.assertFalse(env.is_symlink())
                self.assertEqual(env.read_text(), 'APP_KEY=keep\nAPP_TIMEZONE=Europe/Prague\n')
                self.assertEqual(env.stat().st_mode & 0o777, 0o600)
                self.assertEqual(list(self.runtime.glob('.env-timezone-*')), [])

    def test_web_serving_requires_fpm_and_an_unshared_nonroot_identity(self):
        script = r'''die(){ echo "$*" >&2; exit 1; }
id(){
  case "$2" in
    root) echo 0;;
    www-data|web-alias) echo 33;;
    ispconfig-rest) echo 999;;
    *) return 1;;
  esac
}
''' + installer_function('validate_runtime_identity') + 'validate_runtime_identity'
        for mode, fpm, user, expected in [
            ('apache', '0', 'ispconfig-rest', False),
            ('nginx', '0', 'ispconfig-rest', False),
            ('apache', '1', 'www-data', False),
            ('apache', '1', 'web-alias', False),
            ('apache', '1', 'root', False),
            ('apache', '1', 'ispconfig-rest', True),
            ('nginx', '1', 'new-dedicated-user', True),
            ('none', '0', 'ispconfig-rest', True),
        ]:
            with self.subTest(mode=mode, fpm=fpm, user=user):
                self.environment.update(SERVE_MODE=mode, FPM_AVAILABLE=fpm, RUN_USER=user, WEB_USER='www-data')
                result = self.run_script(script)
                self.assertEqual(result.returncode == 0, expected, result.stderr)

    def test_privileged_migration_executes_only_private_source_for_both_db_auth_modes(self):
        (self.runtime / 'artisan').write_text('<?php file_put_contents(getenv("MARKER"), "unsafe");')
        (self.stage / 'app' / 'artisan').write_text(
            '<?php file_put_contents(getenv("MARKER"), json_encode(['
            'getcwd(), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), '
            'getenv("API_EXTENSIONS_PATH"), $argv]));')
        import json
        for user, password in [('root', ''), ('fixture-admin', 'fixture-password')]:
            self.environment.update(DB_ADMIN_USER=user, DB_ADMIN_PASS=password)
            result = self.run_script(installer_function('migrate_privileged', True) + 'migrate_privileged')
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads((self.root / 'marker').read_text()), [
                str(self.stage / 'app'), user, password, str(self.stage / 'app' / 'extensions'),
                ['artisan', 'migrate', '--force']])

    def test_failed_private_migration_does_not_fall_back_to_runtime(self):
        (self.runtime / 'artisan').write_text('<?php file_put_contents(getenv("MARKER"), "unsafe");')
        result = self.run_script(installer_function('migrate_privileged', True) + 'migrate_privileged')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'marker').exists())

    def test_fresh_source_ignores_runtime_git_config_and_uses_private_dependencies(self):
        source = self.root / 'published'
        source.mkdir()
        subprocess.run(['git', 'init', '-b', 'main', str(source)], check=True, capture_output=True)
        (source / 'bin').mkdir()
        (source / 'bin' / 'ispconfig-rest').write_text('trusted-manager')
        subprocess.run(['git', '-C', str(source), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(source), '-c', 'user.email=fixture@example.test',
                        '-c', 'user.name=Fixture', 'commit', '-m', 'fixture'], check=True, capture_output=True)
        (self.runtime / '.git').mkdir()
        (self.runtime / '.git' / 'config').write_text('malformed and untrusted')
        self.environment.update(REPO_URL=str(source), BRANCH='main', COMPOSER='/bin/true',
                                FIXTURE_TMP=str(self.root))
        # The test substitutes only transport and /root allocation; real Git
        # clones an actual fixture repository and real mktemp enforces privacy.
        wrapper = r'''
mktemp() { command mktemp -d "$FIXTURE_TMP/stage.XXXXXXXX"; }
git() {
  if [ "$1" = -c ]; then
    [ "$2" = core.hooksPath=/dev/null ] && [ "$4" = protocol.file.allow=never ] || exit 1
    shift 4
  fi
  command git "$@"
}
'''
        script = wrapper + installer_function('prepare_privileged_source') + r'''
prepare_privileged_source
[ "$(stat -c %a "$PRIVILEGED_STAGE")" = 700 ]
[ "$(cat "$PRIVILEGED_STAGE/app/bin/ispconfig-rest")" = trusted-manager ]
[ "$INSTALL_DIR" != "$PRIVILEGED_STAGE/app" ]
'''
        result = self.run_script(script)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(list(self.root.glob('stage.*')), [], 'Private stage must be cleaned')

    def test_extension_migrations_use_published_source_and_reject_unrelated_revision(self):
        source = self.root / 'extension-published'
        source.mkdir()
        def git(*args):
            return subprocess.check_output(['git', '-C', str(source), *args], stderr=subprocess.DEVNULL, text=True).strip()
        git('init', '-b', 'main')
        git('config', 'user.email', 'fixture@example.test')
        git('config', 'user.name', 'Fixture')
        (source / 'migration.php').write_text('trusted-migration')
        git('add', '.')
        git('commit', '-m', 'published')
        published = git('rev-parse', 'HEAD')
        git('checkout', '--orphan', 'unrelated')
        (source / 'migration.php').write_text('untrusted-migration')
        git('add', '.')
        git('commit', '-m', 'unrelated')
        unrelated = git('rev-parse', 'HEAD')
        git('checkout', 'main')
        state = self.root / 'state'
        state.mkdir()
        (state / 'install.conf').write_text('EXTENSIONS="hosting"\n'
            'EXTENSION_HOSTING_REPOSITORY="https://fixture.example/extension.git"\n'
            'EXTENSION_HOSTING_REF="refs/heads/main"\n')
        self.environment.update(STATE_DIR=str(state), RUN_USER='fixture', FIXTURE_SOURCE=str(source))
        wrapper = r'''
run_as() { printf '%s' "$INSTALLED_REVISION"; }
git() {
  local value args=()
  for value in "$@"; do
    case "$value" in
      protocol.file.allow=never) args+=(protocol.file.allow=always);;
      https://fixture.example/extension.git) args+=("$FIXTURE_SOURCE");;
      *) args+=("$value");;
    esac
  done
  command git "${args[@]}"
}
'''
        for revision, allowed in [(published, True), (unrelated, False)]:
            with self.subTest(allowed=allowed):
                target = self.stage / 'app' / 'extensions'
                shutil.rmtree(target, ignore_errors=True)
                self.environment['INSTALLED_REVISION'] = revision
                result = self.run_script(wrapper + installer_function('prepare_privileged_extensions', True)
                                         + 'prepare_privileged_extensions')
                if allowed:
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual((target / 'hosting' / 'migration.php').read_text(), 'trusted-migration')
                else:
                    self.assertNotEqual(result.returncode, 0)
                    self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
