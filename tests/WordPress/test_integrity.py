import hashlib
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import MagicMock, patch
import zipfile

from test_worker import tools


class WordPressIntegrityTest(unittest.TestCase):
    def reference(self, entries, checksums=None):
        archive = io.BytesIO()
        with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as package:
            for name, content in entries.items():
                package.writestr('wordpress/' + name, content)
        manifest = {'checksums': checksums if checksums is not None else {name: hashlib.md5(content).hexdigest() for name, content in entries.items()}}

        def download(url, target, maximum, deadline):
            self.assertGreater(maximum, 0)
            self.assertGreater(deadline, tools.time.monotonic())
            target.write(json.dumps(manifest).encode() if '/checksums/' in url else archive.getvalue())
        return download

    def check(self, root, entries, extra=(), download=None):
        toolkit = tools.Toolkit({'path': '', 'php': []})
        toolkit.root = root
        rows = [('changed', name) for name in entries] + list(extra)
        messages = {'changed': "File doesn't verify against checksum", 'missing': "File doesn't exist", 'unexpected': 'File should not exist'}
        output = '\n'.join('Warning: ' + messages[kind] + ': ' + name for kind, name in rows)
        output += "\nError: WordPress installation doesn't verify against checksums." if entries or any(kind == 'missing' for kind, _ in extra) else '\nSuccess: WordPress installation verifies against checksums.'
        version = b"<?php $wp_version='7.1.2'; $wp_local_package='cs_CZ'; die('must never execute');"
        with patch.object(tools, 'safe_file', return_value=(version, None)), patch.object(toolkit, 'wp', return_value=(output, int(bool(entries) or any(kind == 'missing' for kind, _ in extra)))), patch.object(tools, 'official_download', side_effect=download or self.reference(entries)) as fetch:
            result = toolkit.integrity()['integrity']
            if entries:
                self.assertIn('version=7.1.2&locale=cs_CZ', fetch.call_args_list[0].args[0])
            return result

    def test_fresh_text_with_crlf_lf_or_mixed_line_endings_passes_without_rewriting(self):
        entries = {'license.txt': b'first\r\nsecond\r\n', 'wp-config-sample.php': b'<?php\n// sample\n', 'csslint.js': b'// first\n// second\r\n// last\r\n'}
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            originals = {}
            for name, data in entries.items():
                originals[name] = data.replace(b'\r\n', b'\n') if b'\r\n' in data else data.replace(b'\n', b'\r\n')
                (root / name).write_bytes(originals[name])
            result = self.check(root, entries)
            self.assertEqual('clean', result['status'])
            self.assertEqual(3, result['total'])
            self.assertEqual(['line_endings'] * 3, [row['status'] for row in result['files']])
            self.assertEqual(originals, {name: (root / name).read_bytes() for name in entries})

    def test_content_whitespace_binary_changes_and_untrusted_references_stay_modified(self):
        official = b'<?php\r\n// original\r\n'
        cases = [(b'<?php\n// injected\n', official, None), (b'<?php\n // original\n', official, None),
                 (official.replace(b'\r\n', b'\n') + b'<?php evil();', official, None),
                 (b'\x00binary\n', b'\x00binary\r\n', None), (b'\xff\n', b'\xff\r\n', None),
                 (b'<?php\n// original\n', official, {'file.php': '0' * 32}),
                 (b'<?php\n// original\n', official, {})]
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            for local, reference, manifest in cases:
                with self.subTest(local=local, manifest=manifest):
                    (root / 'file.php').write_bytes(local)
                    result = self.check(root, {'file.php': reference}, download=self.reference({'file.php': reference}, manifest))
                    self.assertEqual('modified', result['status'])
                    self.assertEqual('changed', result['files'][0]['status'])
            # A binary extension must not be normalized even if its bytes look textual.
            (root / 'image.png').write_bytes(b'looks like text\n')
            toolkit = tools.Toolkit({'path': '', 'php': []}); toolkit.root = root
            rows = [{'file': 'image.png', 'status': 'changed'}]
            with patch.object(tools, 'official_download') as download:
                toolkit.integrity_line_endings(rows, '7.1.2', 'cs_CZ')
                download.assert_not_called()
                self.assertEqual('changed', rows[0]['status'])

    def test_reference_failures_never_turn_mismatches_clean(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root / 'file.txt').write_bytes(b'content\n')
            for failure in (OSError('offline'), tools.Failure('checksums_unavailable'), zipfile.BadZipFile(), tools.http.client.IncompleteRead(b'')):
                result = self.check(root, {'file.txt': b'content\r\n'}, download=MagicMock(side_effect=failure))
                self.assertEqual('modified', result['status'])
                self.assertEqual('changed', result['files'][0]['status'])

    def test_missing_unexpected_and_findings_beyond_display_limit_still_fail(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root / 'file.txt').write_bytes(b'content\n')
            for extra in ([('missing', 'index.php')], [('unexpected', 'injected.php')], [('unexpected', f'extra-{n}.php') for n in range(501)]):
                result = self.check(root, {'file.txt': b'content\r\n'}, extra)
                self.assertEqual('modified', result['status'])
                self.assertEqual(extra[0][0], result['files'][0]['status'], 'Actionable findings precede informational rows')
                self.assertEqual(len(extra) + 1, result['total'])
                self.assertEqual(len(extra) > 499, result['truncated'])
                self.assertLessEqual(len(result['files']), 500)
            result = self.check(root, {})
            self.assertEqual('clean', result['status'])
            self.assertEqual([], result['files'])

    def test_local_symlinks_hardlinks_fifos_oversized_files_and_traversal_are_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); outside = root / 'outside'; outside.mkdir()
            (outside / 'file.txt').write_bytes(b'content\n')
            (root / 'link').symlink_to(outside, target_is_directory=True)
            (root / 'symlink.txt').symlink_to(outside / 'file.txt')
            os.link(outside / 'file.txt', root / 'hardlink.txt')
            os.mkfifo(root / 'fifo.txt')
            with (root / 'big.txt').open('wb') as file: file.truncate(tools.INTEGRITY_TEXT_LIMIT + 1)
            for name in ('link/file.txt', 'symlink.txt', 'hardlink.txt', 'fifo.txt', 'big.txt', '../outside/file.txt', '/etc/passwd', 'a//file.txt'):
                with self.subTest(name=name), self.assertRaises((OSError, tools.Failure)):
                    tools.integrity_file(root, name)

    def test_archive_symlink_and_oversized_entries_cannot_be_a_reference(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root / 'file.txt').write_bytes(b'content\n')
            original = b'content\r\n'; manifest = self.reference({'file.txt': original})
            for mode, data in ((stat.S_IFLNK | 0o777, original), (stat.S_IFREG | 0o644, b'x' * (tools.INTEGRITY_TEXT_LIMIT + 1))):
                archive = io.BytesIO()
                with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as package:
                    info = zipfile.ZipInfo('wordpress/file.txt'); info.external_attr = mode << 16; info.compress_type = zipfile.ZIP_DEFLATED
                    package.writestr(info, data)
                def download(url, target, maximum, deadline):
                    if '/checksums/' in url: manifest(url, target, maximum, deadline)
                    else: target.write(archive.getvalue())
                result = self.check(root, {'file.txt': original}, download=download)
                self.assertEqual('changed', result['files'][0]['status'])

    def test_download_rejects_external_redirects_and_oversized_streams(self):
        for destination in ('http://wordpress.org/file', 'https://evil.test/file', 'https://wordpress.org.evil.test/file', 'https://user@wordpress.org/file', 'https://wordpress.org:443/file'):
            with patch.object(tools.http.client, 'HTTPSConnection') as connection:
                response = connection.return_value.getresponse.return_value
                response.status = 302; response.getheader.return_value = destination
                with self.assertRaises(tools.Failure):
                    tools.official_download('https://wordpress.org/file', io.BytesIO(), 20, tools.time.monotonic() + 10)
                self.assertEqual(1, connection.call_count)
                connection.return_value.close.assert_called_once()
        with patch.object(tools.http.client, 'HTTPSConnection') as connection:
            response = connection.return_value.getresponse.return_value
            response.status = 200; response.getheader.return_value = None; response.read.return_value = b'x' * 21
            with self.assertRaises(tools.Failure):
                tools.official_download('https://wordpress.org/file', io.BytesIO(), 20, tools.time.monotonic() + 10)
            connection.return_value.close.assert_called_once()
