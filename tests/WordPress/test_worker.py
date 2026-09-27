import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

WORKER = Path(__file__).resolve().parents[2] / 'file-manager-worker'
sys.path.insert(0, str(WORKER))

def module(name, file):
    spec = importlib.util.spec_from_file_location(name, WORKER / file)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result

tools = module('wp_tools', 'wordpress-tools.py')
sandbox = module('wp_sandbox', 'wordpress-sandbox.py')

class WordPressWorkerTest(unittest.TestCase):
    def test_sql_identifiers_and_literals_are_not_shell_or_sql_fragments(self):
        for value in ('a` DROP TABLE users', 'a.b', '', 'x' * 65, '../outside', 'a\n'):
            with self.assertRaises(tools.Failure):
                tools.identifier(value)
        self.assertEqual('`wp_users`', tools.identifier('wp_users'))
        self.assertEqual('0x6164276d696e', tools.literal("ad'min"))

    def test_install_paths_cannot_escape_the_site(self):
        for path in ('../outside', '/etc', 'a/../b', 'a//b', 'a/./b'):
            with self.assertRaises(tools.Failure):
                tools.Toolkit({'path': path, 'php': []})

    def test_symlink_and_hardlink_config_are_rejected_without_touching_target(self):
        with tempfile.TemporaryDirectory() as temp:
            target = Path(temp, 'target')
            target.write_text('unchanged')
            link = Path(temp, 'config')
            link.symlink_to(target)
            with self.assertRaises(OSError):
                tools.safe_file(link)
            link.unlink()
            os.link(target, link)
            with self.assertRaises(tools.Failure):
                tools.safe_file(link)
            self.assertEqual('unchanged', target.read_text())

    def test_config_replacement_is_atomic_and_checks_expected_content(self):
        with tempfile.TemporaryDirectory() as temp:
            config = Path(temp, 'wp-config.php')
            config.write_bytes(b'old')
            with self.assertRaises(tools.Failure):
                tools.replace_file(config, b'new', 0o600, 'wrong')
            self.assertEqual(b'old', config.read_bytes())
            tools.replace_file(config, b'new', 0o600, tools.hashlib.sha256(b'old').hexdigest())
            self.assertEqual(b'new', config.read_bytes())
            self.assertEqual(0o600, config.stat().st_mode & 0o777)

    def test_sandbox_has_no_host_etc_or_other_webspaces_and_uses_pinned_descriptors(self):
        args = sandbox.command(1001, 1001, 12, 13, ['/usr/bin/php8.3'], True)
        self.assertIn('--unshare-user', args)
        self.assertIn('--disable-userns', args)
        self.assertIn('--clearenv', args)
        self.assertIn('--cap-drop', args)
        self.assertNotIn('/var/www', args)
        self.assertNotIn('/etc/php', args)
        self.assertNotIn('/usr/local/ispconfig', args)
        self.assertNotIn('--bind', args)
        offset = args.index('--ro-bind-fd')
        self.assertEqual(['12', '/site'], args[offset + 1:offset + 3])

    def test_identity_changes_on_transfer_domain_and_docroot(self):
        site = {'domain_id': 1, 'sys_groupid': 2, 'server_id': 1, 'domain': 'a.test', 'system_user': 'web1', 'system_group': 'client1'}
        first = sandbox.identity(site, '/var/www/web')
        for update in ({'sys_groupid': 3}, {'server_id': 2}, {'domain': 'b.test'}, {'system_user': 'web2'}):
            self.assertNotEqual(first, sandbox.identity(dict(site, **update), '/var/www/web'))
        self.assertNotEqual(first, sandbox.identity(site, '/var/www/web/sub'))

    def test_failure_after_one_way_change_restores_protected_snapshot(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(sandbox, 'STATE', Path(temp)), patch.object(sandbox, 'account', return_value=(os.geteuid(), os.getegid())):
            work = Path(temp, 'work');work.mkdir()
            (work / 'rollback.sql').write_bytes(b'-- SQL snapshot\n' * 10)
            (work / 'rollback-config').write_bytes(b'<?php // original')
            site = {'domain_id': 1}
            request = {'public_root': '/site', 'action': 'secure'}
            prepared = {'prefix': 'wp_', 'tables': ['wp_users']}
            with patch.object(sandbox.os, 'getuid', return_value=0), patch.object(sandbox, 'checkpoint') as save, patch.object(sandbox, 'execute', side_effect=[{'prepared': prepared}, {'error': 'http_verification_failed'}, {'restored': True}]) as run:
                # root ownership check is intentional; in a non-root unit test emulate only lstat uid.
                original = Path.lstat
                def root_state(path):
                    info = original(path)
                    values = list(info);values[4] = 0
                    return os.stat_result(values)
                with patch.object(Path, 'lstat', root_state):
                    result = sandbox.one_way(site, request, work, {'job': 'test'})
                self.assertEqual({'error': 'verification_failed_restored'}, result)
                self.assertEqual('restore', run.call_args.args[1]['action'])
                self.assertIsNotNone(run.call_args.kwargs['input_stream'])
                self.assertEqual('restored', save.call_args.args[1]['phase'])

if __name__ == '__main__':
    unittest.main()
