#!/usr/bin/env python3
"""Pinned local/CI scanners; findings and scanner failures both fail the check."""
import argparse
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import secrets as random_secrets
import shutil
import string
import subprocess
import tarfile
import tempfile
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
LOCK = json.loads((ROOT / 'security/tools.json').read_text())
CACHE = Path(os.environ.get('SECURITY_TOOLS_CACHE', Path.home() / '.cache/ispconfig-rest-security'))
REPORTS = ROOT / 'security-reports'


def download(url, digest):
    with urllib.request.urlopen(url, timeout=120) as response:
        data = response.read()
    if hashlib.sha256(data).hexdigest() != digest:
        raise RuntimeError('Checksum mismatch: ' + url)
    return data


def binary(name):
    if platform.system() != 'Linux' or platform.machine() not in ('x86_64', 'AMD64'):
        raise RuntimeError('The pinned CLI binaries require Linux x86_64.')
    spec = LOCK[name]
    target = CACHE / (name + '-' + spec['version']) / name
    if not target.exists():
        data = download(spec['url'], spec['sha256'])
        with tarfile.open(fileobj=io.BytesIO(data)) as archive:
            member = archive.extractfile(name)
            if member is None:
                raise RuntimeError('Binary missing from archive')
            payload = member.read()
        target.parent.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(dir=target.parent, delete=False) as temporary:
            temporary.write(payload)
            temporary_path = Path(temporary.name)
        temporary_path.chmod(0o755)
        temporary_path.replace(target)
    return str(target)


def run(*args, **kwargs):
    subprocess.run(args, cwd=ROOT, check=True, **kwargs)


@contextlib.contextmanager
def source_snapshot():
    # Include local edits and new files, but never ignored .env/vendor/extension
    # directories or private reports. Do not follow symlinks outside the checkout.
    paths = subprocess.check_output(
        ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=ROOT
    ).decode().split('\0')
    with tempfile.TemporaryDirectory(prefix='ispconfig-source-') as temporary:
        target = Path(temporary)
        for path in paths:
            if not path:
                continue
            source = ROOT / path
            if source.is_symlink() or not source.is_file() or not source.resolve().is_relative_to(ROOT.resolve()):
                continue
            destination = target / path
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, destination)
        yield target


def secrets():
    scanner = binary('gitleaks')
    test_secret_exceptions(scanner)
    common = ('--redact=100', '--no-banner', '--report-format=json',
              '--gitleaks-ignore-path=' + str(ROOT / '.gitleaksignore'))
    failed = False
    # Run both checks even if history already contains a finding.
    with source_snapshot() as snapshot:
        for mode, target, extra, report in [
            ('git', ROOT, ['--log-opts=--all'], 'secrets-history.json'),
            ('dir', snapshot, [], 'secrets-worktree.json'),
        ]:
            try:
                run(scanner, mode, str(target), *extra, *common,
                    '--config=' + str(ROOT / '.gitleaks.toml'),
                    '--report-path=' + str(REPORTS / report))
            except subprocess.CalledProcessError:
                failed = True
    if failed:
        raise SystemExit(1)


def test_secret_exceptions(scanner):
    # Real-scanner negative controls prevent fixture exceptions from hiding new credentials.
    paths = ['README.md', 'tests/Feature/DnsAcmeChallengeApiTest.php',
             'api/components/schemas/MailDomain.yaml']
    with tempfile.TemporaryDirectory(prefix='ispconfig-secret-control-') as temporary:
        directory = Path(temporary)
        for name in paths:
            target = directory / name
            target.parent.mkdir(parents=True, exist_ok=True)
            generic = ''.join(random_secrets.SystemRandom().sample(string.ascii_letters + string.digits, 48))
            target.write_text((ROOT / name).read_text() + '\nisp_' + random_secrets.token_hex(20)
                              + "\nAPI_KEY = '" + generic + "'\n")
        report = directory / 'report.json'
        result = subprocess.run(
            [scanner, 'dir', str(directory), '--config=' + str(ROOT / '.gitleaks.toml'),
             '--redact=100', '--no-banner', '--report-format=json', '--report-path=' + str(report)],
            cwd=ROOT, capture_output=True, text=True,
        )
        if result.returncode != 1 or not report.exists():
            raise RuntimeError('Secret scanner negative control failed: injected credentials were not detected.')
        findings = json.loads(report.read_text())
        for rule in ['ispconfig-api-key', 'generic-api-key']:
            detected = {Path(f['File']).relative_to(directory).as_posix() for f in findings
                        if f['RuleID'] == rule and f['Secret'] == 'REDACTED'}
            if detected != set(paths):
                raise RuntimeError('Secret exceptions hid an injected credential or the report was not redacted.')


def source():
    spec = LOCK['semgrep']
    selection = hashlib.sha256(json.dumps(spec['rules'], sort_keys=True).encode()).hexdigest()[:16]
    rules = CACHE / ('rules-' + spec['revision'] + '-' + selection)
    rules.mkdir(parents=True, exist_ok=True)
    for path, digest in spec['rules'].items():
        target = rules / path.replace('/', '--')
        if not target.exists() or hashlib.sha256(target.read_bytes()).hexdigest() != digest:
            target.write_bytes(download('https://raw.githubusercontent.com/semgrep/semgrep-rules/'
                                        + spec['revision'] + '/' + path, digest))
    with source_snapshot() as snapshot:
        common = ('docker', 'run', '--rm', '--network=none', '--cap-drop=ALL',
                  '--security-opt=no-new-privileges', '--user', f'{os.getuid()}:{os.getgid()}',
                  '-e', 'SEMGREP_LOG_FILE=/tmp/semgrep.log',
                  '-e', 'SEMGREP_SETTINGS_FILE=/tmp/semgrep-settings.yml',
                  '-v', str(snapshot) + ':/src:ro', '-v', str(rules) + ':/rules:ro',
                  '-v', str(REPORTS) + ':/reports', '-w', '/src', spec['image'], 'semgrep')
        run(*common, '--test', '--metrics=off', '--disable-version-check', 'security/semgrep')
        run(*common, 'scan', '--config=/rules', '--config=security/semgrep/unsafe-deserialization.yaml',
            '--metrics=off', '--disable-version-check', '--strict', '--error',
            '--jobs=2', '--max-memory=1800', '--timeout=30',
            '--sarif-output=/reports/source.sarif', '--json-output=/reports/source.json',
            *LOCK['source_paths'])
        # Semgrep may report per-file errors while returning zero. Do not turn
        # incomplete parsing or timeouts into a clean security gate.
        errors = json.loads((REPORTS / 'source.json').read_text()).get('errors', [])
        if errors:
            raise RuntimeError('Semgrep reported incomplete analysis; inspect source.json.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('check', choices=['secrets', 'source'])
    args = parser.parse_args()
    REPORTS.mkdir(mode=0o700, exist_ok=True)
    globals()[args.check]()


if __name__ == '__main__':
    main()
