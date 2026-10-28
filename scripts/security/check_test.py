import importlib.util
import io
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('security_check', Path(__file__).with_name('check.py'))
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)


class SecurityRunnerTests(unittest.TestCase):
    def test_download_rejects_tampered_content(self):
        with patch.object(checker.urllib.request, 'urlopen', return_value=io.BytesIO(b'tampered')):
            with self.assertRaisesRegex(RuntimeError, 'Checksum mismatch'):
                checker.download('https://example.invalid/tool', '0' * 64)

    def test_snapshot_includes_local_edits_but_excludes_ignored_secrets_and_symlinks(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            subprocess.run(['git', 'init', '-q', str(root)], check=True)
            (root / '.gitignore').write_text('.env\nsecurity-reports/\n')
            (root / '.env').write_text('PRIVATE=must-not-copy')
            (root / 'source.php').write_text('original')
            subprocess.run(['git', 'add', 'source.php'], cwd=root, check=True)
            (root / 'source.php').write_text('edited')
            (root / 'new.php').write_text('new')
            (root / 'symlink').symlink_to(root / '.env')
            with patch.object(checker, 'ROOT', root), checker.source_snapshot() as snapshot:
                self.assertEqual((snapshot / 'source.php').read_text(), 'edited')
                self.assertEqual((snapshot / 'new.php').read_text(), 'new')
                self.assertFalse((snapshot / '.env').exists())
                self.assertFalse((snapshot / 'symlink').exists())
            self.assertFalse(snapshot.exists())

    def test_secret_failure_still_scans_worktree_and_redacts_both_reports(self):
        with tempfile.TemporaryDirectory() as temporary, \
                patch.object(checker, 'source_snapshot') as snapshot, patch.object(checker, 'test_secret_exceptions'), \
                patch.object(checker, 'binary', return_value='gitleaks'), \
                patch.object(checker, 'run', side_effect=[subprocess.CalledProcessError(2, 'gitleaks'), None]) as run:
            snapshot.return_value.__enter__.return_value = Path(temporary)
            with self.assertRaises(SystemExit) as result:
                checker.secrets()
            self.assertEqual(result.exception.code, 1)
            self.assertEqual(run.call_count, 2)
            self.assertIn('--log-opts=--all', run.call_args_list[0].args)
            for call in run.call_args_list:
                self.assertIn('--redact=100', call.args)


if __name__ == '__main__':
    unittest.main()
