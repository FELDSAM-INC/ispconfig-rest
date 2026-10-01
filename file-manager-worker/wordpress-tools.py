#!/usr/bin/python3
"""Runs ONLY as the vhost UID inside bubblewrap. No ISPConfig/master credentials are visible."""
import hashlib
import http.client
import io
import json
import os
import pathlib
import re
import secrets
import selectors
import signal
import ssl
import stat
import subprocess
import sys
import tempfile
import time
import urllib.parse
import zipfile

SERVER = ('xmlrpc', 'config', 'htfiles', 'sensitive', 'potential', 'indexes', 'includes_php', 'uploads_php', 'cache_php', 'author', 'bots')
CONSTANTS = {'file_editor': ('DISALLOW_FILE_EDIT', 'true'), 'concatenate': ('CONCATENATE_SCRIPTS', 'false')}
SKIP = {'wp-includes', 'node_modules', '.git', '.svn', 'vendor', '.ispcp-trash'}
INTEGRITY_TEXT_LIMIT = 8 * 1024 * 1024
INTEGRITY_TEXT_TYPES = {'.php', '.js', '.css', '.txt', '.html', '.htm', '.xml', '.json', '.svg', '.md'}


class Failure(Exception):
    pass


class CronFailure(Failure):
    """A WordPress cron run that failed, with what WP-CLI printed."""

    def __init__(self, code, output):
        super().__init__(code)
        self.output = output


CRON_LOG = pathlib.Path('/trigger/wp-cron.log')
CRON_LOG_LIMIT = 1048576


def cron_log(path, started, status, output, log=None):
    """Appends one run to the website's private/wp-cron.log as the website user. Logging never stops a run."""
    log = log or CRON_LOG
    try:
        try:
            if os.lstat(log).st_size > CRON_LOG_LIMIT:
                os.replace(log, log.with_name(log.name + '.1'))
        except FileNotFoundError:
            pass
        fd = os.open(log, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'a', encoding='utf-8', errors='replace') as handle:
            handle.write('[%s UTC] /%s: %s\n%s\n' % (time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(started)), path, status, output.rstrip() or '(no output)'))
    except OSError:
        pass


def run(args, *, data=None, limit=262144, timeout=90, accepted=(0,), include_stderr=False):
    process = subprocess.Popen(args, stdin=subprocess.PIPE if data is not None else subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd='/tool', start_new_session=True)
    try:
        if data is not None:
            process.stdin.write(data)
            process.stdin.close()
        selector = selectors.DefaultSelector()
        selector.register(process.stdout, selectors.EVENT_READ, 'out')
        selector.register(process.stderr, selectors.EVENT_READ, 'err')
        output = {'out': bytearray(), 'err': bytearray()}
        started = time.monotonic()
        while selector.get_map():
            if time.monotonic() - started > timeout:
                raise Failure('command_timeout')
            for key, _ in selector.select(0.1):
                chunk = os.read(key.fileobj.fileno(), 16384)
                if not chunk:
                    selector.unregister(key.fileobj)
                else:
                    output[key.data].extend(chunk)
                if len(output[key.data]) > limit:
                    raise Failure('command_output_limit')
        process.wait(timeout=5)
        if process.returncode not in accepted:
            operation = next((name for name in ('config', 'db', 'eval', 'user', 'option') if name in args), 'command')
            raise Failure('wp_' + operation + '_failed')
        combined = output['out'] + (b'\n' + output['err'] if include_stderr else b'')
        return combined.decode('utf-8', errors='replace').strip(), process.returncode
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()


def identifier(value):
    if not isinstance(value, str) or not re.fullmatch(r'[A-Za-z0-9_]{1,64}', value):
        raise Failure('unsupported_database_layout')
    return '`' + value + '`'


def literal(value):
    # Hex literals, never interpolated quotes or backslash-dependent SQL escaping.
    return '0x' + value.encode().hex() if value else "''"


def safe_file(path, maximum=1048576):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > maximum or info.st_uid != os.geteuid():
            raise Failure('unsafe_wordpress_files')
        with os.fdopen(fd, 'rb', closefd=False) as source:
            return source.read(maximum + 1), info
    finally:
        os.close(fd)


def integrity_file(root, name):
    """Pin every ancestor and reject special files; checksum exceptions never follow links."""
    pieces = name.split('/')
    if any(piece in ('', '.', '..') for piece in pieces):
        raise Failure('unsafe_wordpress_files')
    parent = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for piece in pieces[:-1]:
            child = os.open(piece, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            os.close(parent)
            parent = child
        fd = os.open(pieces[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        with os.fdopen(fd, 'rb') as source:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.geteuid() or info.st_size > INTEGRITY_TEXT_LIMIT:
                raise Failure('unsafe_wordpress_files')
            return source.read(INTEGRITY_TEXT_LIMIT + 1)
    finally:
        os.close(parent)


def official_download(url, target, maximum, deadline):
    """HTTPS only, fixed WordPress hosts, bounded response/time; no ambient proxy or auth."""
    for _ in range(4):
        parsed = urllib.parse.urlsplit(url)
        if (parsed.scheme != 'https' or parsed.port is not None or parsed.username is not None or parsed.password is not None
                or not re.fullmatch(r'(?:api\.|downloads\.|[a-z]{2}\.)?wordpress\.org', parsed.netloc)):
            raise Failure('checksums_unavailable')
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise Failure('checksums_unavailable')
        connection = http.client.HTTPSConnection(parsed.hostname, timeout=min(15, remaining))
        try:
            connection.request('GET', parsed.path + ('?' + parsed.query if parsed.query else ''), headers={'User-Agent': 'ISPConfig-REST-WordPress/1', 'Accept-Encoding': 'identity'})
            response = connection.getresponse()
            if response.status in (301, 302, 303, 307, 308):
                url = urllib.parse.urljoin(url, response.getheader('Location', ''))
                continue
            if response.status != 200:
                raise Failure('checksums_unavailable')
            length = response.getheader('Content-Length')
            if length is not None and (not length.isdigit() or int(length) > maximum):
                raise Failure('checksums_unavailable')
            count = 0
            while True:
                if time.monotonic() >= deadline:
                    raise Failure('checksums_unavailable')
                chunk = response.read(min(65536, maximum + 1 - count))
                if not chunk:
                    return
                count += len(chunk)
                if count > maximum:
                    raise Failure('checksums_unavailable')
                target.write(chunk)
        finally:
            connection.close()
    raise Failure('checksums_unavailable')


def same_text_lines(local, official):
    # Only CRLF/LF conversion, never trimming whitespace, ignoring lines or binary changes.
    for data in (local, official):
        if len(data) > INTEGRITY_TEXT_LIMIT or re.search(rb'[\x00-\x08\x0b\x0e-\x1f\x7f]', data):
            return False
        try:
            data.decode('utf-8')
        except UnicodeDecodeError:
            return False
    return local != official and local.replace(b'\r\n', b'\n') == official.replace(b'\r\n', b'\n')


def replace_file(path, value, mode, expected=None):
    """Replace the directory entry; never write through a raced symlink or hardlink."""
    parent = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    temp = '.ispcp-wp-' + secrets.token_hex(12)
    try:
        if expected is not None:
            current, _ = safe_file(path)
            if hashlib.sha256(current).hexdigest() != expected:
                raise Failure('configuration_changed')
        fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode, dir_fd=parent)
        with os.fdopen(fd, 'wb') as output:
            output.write(value)
            output.flush()
            os.fsync(output.fileno())
        os.rename(temp, path.name, src_dir_fd=parent, dst_dir_fd=parent)
    finally:
        try:
            os.unlink(temp, dir_fd=parent)
        except FileNotFoundError:
            pass
        os.close(parent)


class Toolkit:
    def __init__(self, request):
        self.request = request
        self.path = request.get('path') or ''
        if self.path and (self.path.startswith('/') or any(x in ('', '.', '..') for x in self.path.split('/'))):
            raise Failure('invalid_installation')
        self.root = pathlib.Path('/site', self.path)
        # Symlinked installations are deliberately excluded, including every ancestor.
        current = pathlib.Path('/site')
        for piece in self.path.split('/') if self.path else []:
            current /= piece
            if not stat.S_ISDIR(current.lstat().st_mode):
                raise Failure('unsafe_wordpress_files')
        self.config = self.root / 'wp-config.php'
        self.php = request['php']
        self.undo = dict(request.get('undo') or {})

    def wp(self, *args, path=None, data=None, accepted=(0,), timeout=90, include_stderr=False):
        return run(self.php + ['/tool/wp-cli.phar', '--path=' + str(path or self.root), '--skip-plugins', '--skip-themes', '--skip-packages', '--no-color'] + list(args), data=data, accepted=accepted, timeout=timeout, include_stderr=include_stderr)

    def value(self, *args):
        return self.wp(*args)[0]

    def query(self, sql):
        return self.value('db', 'query', sql, '--skip-column-names', '--batch')

    def validate(self):
        safe_file(self.config)
        version, _ = safe_file(self.root / 'wp-includes/version.php')
        if not re.search(rb"\$wp_version\s*=\s*['\"][0-9]", version):
            raise Failure('invalid_installation')

    def config_values(self):
        rows = json.loads(self.value('config', 'list', '--format=json'))
        allowed = {'DISALLOW_FILE_EDIT', 'CONCATENATE_SCRIPTS', 'table_prefix', 'DB_NAME', 'DB_HOST', 'MULTISITE', 'CUSTOM_USER_TABLE', 'CUSTOM_USER_META_TABLE', 'DISABLE_WP_CRON'}
        result = {row['name']: row['value'] for row in rows if row.get('name') in allowed}
        # WP-CLI's JSON config parser returns PHP boolean literals as JSON booleans.
        for name in ('DISALLOW_FILE_EDIT', 'CONCATENATE_SCRIPTS', 'DISABLE_WP_CRON', 'MULTISITE'):
            if isinstance(result.get(name), bool):
                result[name] = 'true' if result[name] else 'false'
            elif type(result.get(name)) is int and result[name] in (0, 1):
                result[name] = str(result[name])
        names = {'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'}
        salts = [row['value'] for row in rows if row.get('name') in names]
        result['_salts_ok'] = len(salts) == 8 and all(isinstance(value, str) and len(value) >= 32 and len(set(value)) >= 10 and 'put your unique phrase here' not in value.lower() for value in salts) and len(set(salts)) == 8
        return result

    def config_change(self, name, value=None, *, variable=False, salts=False):
        original, info = safe_file(self.config)
        # WP-CLI config commands operate on a private copy; site PHP is not evaluated.
        staging = pathlib.Path('/work/config')
        staging.mkdir(mode=0o700, exist_ok=True)
        replace_file(staging / 'wp-config.php', original, 0o600)
        if salts:
            self.wp('config', 'shuffle-salts', path=staging)
        elif value is None:
            self.wp('config', 'delete', name, '--type=' + ('variable' if variable else 'constant'), path=staging)
        else:
            args = ['config', 'set', name, value, '--type=' + ('variable' if variable else 'constant')]
            if not variable:
                if value not in ('true', 'false', '0', '1'):
                    raise Failure('unsupported_constant')
                args.append('--raw')
            self.wp(*args, path=staging)
        modified, _ = safe_file(staging / 'wp-config.php')
        run(self.php + ['-l', str(staging / 'wp-config.php')])
        replace_file(self.config, modified, stat.S_IMODE(info.st_mode), hashlib.sha256(original).hexdigest())

    def metadata(self):
        data = json.loads(self.value('eval', 'echo json_encode(["url"=>get_option("siteurl"),"title"=>get_option("blogname"),"version"=>$GLOBALS["wp_version"],"multisite"=>is_multisite()]);'))
        url = str(data['url'])[:2048]
        if urllib.parse.urlsplit(url).scheme not in ('http', 'https'):
            url = ''
        return {'id': hashlib.sha256(self.path.encode()).hexdigest()[:32], 'path': self.path, 'url': url,
                'admin_url': url.rstrip('/') + '/wp-admin/' if url else '', 'version': str(data['version'])[:64],
                'title': str(data['title'])[:256], 'checked_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                'multisite': bool(data['multisite']), 'config_hash': hashlib.sha256(safe_file(self.config)[0]).hexdigest()}

    def database(self, config):
        prefix = config.get('table_prefix', '')
        identifier(prefix)
        if config.get('MULTISITE', '').lower() in ('true', '1') or config.get('CUSTOM_USER_TABLE') or config.get('CUSTOM_USER_META_TABLE'):
            raise Failure('unsupported_database_layout')
        tables = self.query('SHOW TABLES').splitlines()
        if not tables or any(not name.startswith(prefix) for name in tables):
            raise Failure('shared_database')
        for name in tables:
            identifier(name)
        return prefix, tables

    def check(self):
        self.validate()
        result = self.metadata()
        config = self.config_values()
        security = {}
        def status(ok, **extra):
            return {'status': 'ok' if ok else 'warning', **extra}
        for measure in SERVER:
            security[measure] = self.request.get('server_security', {}).get(measure, {'status': 'unavailable', 'reason': 'apache_required'})
        for measure, (name, desired) in CONSTANTS.items():
            value = config.get(name)
            if value is not None and str(value).lower() not in ('true', 'false', '0', '1'):
                security[measure] = {'status': 'unavailable', 'reason': 'unsupported_constant'}
            else:
                security[measure] = status(str(value).lower() in (('true', '1') if desired == 'true' else ('false', '0')), can_revert=measure in self.undo)
        security['salts'] = status(config['_salts_ok'])
        try:
            if not self.request.get('permissions_available'):
                raise Failure('site_php_user_required')
            security['permissions'] = status(self.permissions())
        except Failure as error:
            security['permissions'] = {'status': 'unavailable', 'reason': str(error)}
        security['languages'] = status(not any(self.request.get('languages', {}).values()))
        pingbacks = self.value('option', 'get', 'default_ping_status')
        security['pingbacks'] = status(pingbacks == 'closed', can_revert='pingbacks' in self.undo)
        security['prefix'] = status(config.get('table_prefix') != 'wp_')
        admin, code = self.wp('user', 'get', 'admin', '--field=ID', accepted=(0, 1))
        security['admin_login'] = status(code == 1)
        if code == 0 and not admin.isdigit():
            raise Failure('invalid_worker_result')
        try:
            prefix, _ = self.owned_database(config)
            self.database_engines(prefix)
            prior = self.undo.get('prefix', {})
            security['prefix']['can_revert'] = prior.get('database') == config['DB_NAME'] and prior.get('applied') == prefix and prior.get('previous') != prefix
            prior = self.undo.get('admin_login', {})
            security['admin_login']['can_revert'] = False
            if prior.get('database') == config['DB_NAME'] and type(prior.get('id')) is int and prior['id'] > 0:
                login = self.query('SELECT user_login FROM ' + identifier(prefix + 'users') + ' WHERE ID=' + str(prior['id']))
                taken = self.query('SELECT ID FROM ' + identifier(prefix + 'users') + ' WHERE user_login=' + literal(prior.get('previous', '')) + ' AND ID<>' + str(prior['id']))
                security['admin_login']['can_revert'] = login == prior.get('applied') and not taken
        except Failure as error:
            for key in ('prefix', 'admin_login'):
                security[key] = {'status': 'unavailable', 'reason': str(error)}
        # DB names/hosts are consumed only by the root bridge for ownership matching, never in REST output.
        result.update(security=security, undo=self.undo, database_name=config.get('DB_NAME', ''), database_host=config.get('DB_HOST', ''))
        return result

    def integrity(self):
        # Read version metadata as text; verification must work without loading broken site PHP.
        source, _ = safe_file(self.root / 'wp-includes/version.php')
        version = re.search(rb"\$wp_version\s*=\s*['\"]([0-9]+(?:\.[0-9]+){1,2}(?:-(?:beta|RC)[0-9]+)?)['\"]", source)
        locale = re.search(rb"\$wp_local_package\s*=\s*['\"]([A-Za-z_]{2,20})['\"]", source)
        if not version:
            raise Failure('integrity_version_unknown')
        version = version.group(1).decode()
        locale = locale.group(1).decode() if locale else 'en_US'
        # Pinned WP-CLI 2.12 has no JSON checksum formatter. Parse only its fixed
        # diagnostics, require the explicit success/failure marker, reject all other output.
        output, code = self.wp('core', 'verify-checksums', '--version=' + version, '--locale=' + locale,
                               '--include-root', accepted=(0, 1), timeout=180, include_stderr=True)
        kinds = {"File doesn't exist": 'missing', "File doesn't verify against checksum": 'changed', 'File should not exist': 'unexpected'}
        files = []
        success = failure = False
        for line in output.splitlines():
            if not line.strip():
                continue
            if line == 'Success: WordPress installation verifies against checksums.':
                success = True
                continue
            if line == "Error: WordPress installation doesn't verify against checksums.":
                failure = True
                continue
            match = re.fullmatch(r"Warning: (File doesn't exist|File doesn't verify against checksum|File should not exist): (.+)", line)
            if not match:
                raise Failure('checksums_unavailable')
            path = match.group(2)
            if len(path) > 1024 or path.startswith('/') or any(part in ('.', '..') for part in path.split('/')) or re.search(r'[\x00-\x1f\x7f]', path):
                raise Failure('invalid_worker_result')
            files.append({'file': path, 'status': kinds[match.group(1)]})
        changed = any(row['status'] in ('changed', 'missing') for row in files)
        if (code == 0 and (not success or failure or changed)) or (code != 0 and (not failure or not changed or success)):
            raise Failure('checksums_unavailable')
        self.integrity_line_endings(files, version, locale)
        # Keep actionable findings visible when the display limit is reached.
        files.sort(key=lambda row: row['status'] == 'line_endings')
        modified = any(row['status'] != 'line_endings' for row in files)
        return {'integrity': {'status': 'modified' if modified else 'clean', 'version': version, 'locale': locale,
                             'checked_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                             'files': files[:500], 'total': len(files), 'truncated': len(files) > 500}}

    def integrity_line_endings(self, files, version, locale):
        candidates = [row for row in files if row['status'] == 'changed' and pathlib.PurePosixPath(row['file']).suffix.lower() in INTEGRITY_TEXT_TYPES][:500]
        if not candidates:
            return
        # ZIP preserves upstream CRLF and mixed line endings; a tarball or blindly
        # converting everything to CRLF cannot establish the official file contents.
        deadline = time.monotonic() + 90
        try:
            manifest = io.BytesIO()
            official_download('https://api.wordpress.org/core/checksums/1.0/?' + urllib.parse.urlencode({'version': version, 'locale': locale}), manifest, 4 * 1024 * 1024, deadline)
            checksums = json.loads(manifest.getvalue()).get('checksums')
            if not isinstance(checksums, dict):
                return
            package_version = version[:-2] if version.count('.') == 2 and version.endswith('.0') else version
            host = 'wordpress.org' if locale == 'en_US' else locale[:2].lower() + '.wordpress.org'
            suffix = '' if locale == 'en_US' else '-' + locale
            with tempfile.TemporaryFile() as archive:
                official_download('https://' + host + '/wordpress-' + package_version + suffix + '.zip', archive, 96 * 1024 * 1024, deadline)
                archive.seek(0)
                with zipfile.ZipFile(archive) as package:
                    names = [entry.filename for entry in package.infolist()]
                    compared = 0
                    for row in candidates:
                        if time.monotonic() >= deadline or compared >= 64 * 1024 * 1024:
                            break
                        name = row['file']
                        expected = checksums.get(name)
                        member = 'wordpress/' + name
                        if not isinstance(expected, str) or not re.fullmatch(r'[a-f0-9]{32}', expected) or names.count(member) != 1:
                            continue
                        info = package.getinfo(member)
                        mode = stat.S_IFMT(info.external_attr >> 16)
                        if info.file_size > INTEGRITY_TEXT_LIMIT or mode not in (0, stat.S_IFREG) or info.flag_bits & 1:
                            continue
                        # No archive member is extracted or executed. Verify its exact
                        # checksum BEFORE using it as a normalization reference.
                        with package.open(info) as source:
                            official = source.read(INTEGRITY_TEXT_LIMIT + 1)
                        compared += len(official)
                        if len(official) != info.file_size or hashlib.md5(official).hexdigest() != expected:
                            continue
                        try:
                            local = integrity_file(self.root, name)
                        except (OSError, Failure):
                            continue
                        if same_text_lines(local, official):
                            row['status'] = 'line_endings'
        except (OSError, ValueError, AttributeError, Failure, http.client.HTTPException, zipfile.BadZipFile, RuntimeError, NotImplementedError):
            # An unavailable/untrusted reference never excuses a checksum mismatch.
            # Already verified rows stay informational; all others stay changed.
            return

    def cron_constant(self, enable):
        value = self.config_values().get('DISABLE_WP_CRON')
        if value is not None and str(value).lower() not in ('true', 'false', '0', '1'):
            raise Failure('unsupported_constant')
        if self.request['action'] == 'prepare_cron':
            return {'previous_value': value}
        previous = self.request.get('previous_value')
        if not enable and value not in (previous, 'true', '1'):
            raise Failure('configuration_changed')
        desired = 'true' if enable else previous
        # A crash may happen after the atomic config replacement but before the job checkpoint.
        # In particular, deleting an already-absent constant must not fail on resume.
        if value != desired:
            self.config_change('DISABLE_WP_CRON', desired)
        after = self.config_values().get('DISABLE_WP_CRON')
        if after != ('true' if enable else previous):
            raise Failure('configuration_changed')
        return {'cron_changed': True, 'config_hash': hashlib.sha256(safe_file(self.config)[0]).hexdigest()}

    def permissions(self, apply=False):
        count = 0
        correct = True
        for base, directories, files, directory_fd in os.fwalk(self.root, follow_symlinks=False):
            # ISPConfig regenerates this reserved directory and its 0640 access file.
            # Its permissions are not WordPress permissions and must remain native.
            if self.path == '' and pathlib.Path(base) == self.root:
                directories[:] = [name for name in directories if name != 'stats']
            current = os.fstat(directory_fd)
            if current.st_uid != os.geteuid():
                raise Failure('unsafe_wordpress_files')
            if stat.S_IMODE(current.st_mode) != 0o755:
                correct = False
                if apply:
                    os.fchmod(directory_fd, 0o755)
            for name in files + directories:
                count += 1
                if count > 200000:
                    raise Failure('file_count_limit')
                info = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                if stat.S_ISLNK(info.st_mode):
                    raise Failure('symlinked_files')
                is_dir = stat.S_ISDIR(info.st_mode)
                if (not is_dir and not stat.S_ISREG(info.st_mode)) or (not is_dir and info.st_nlink != 1) or info.st_uid != os.geteuid():
                    raise Failure('unsafe_wordpress_files')
                mode = 0o755 if is_dir else (0o600 if pathlib.Path(base, name) == self.config else 0o644)
                if stat.S_IMODE(info.st_mode) != mode:
                    correct = False
                    if apply:
                        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | (os.O_DIRECTORY if is_dir else 0), dir_fd=directory_fd)
                        try:
                            pinned = os.fstat(fd)
                            if (pinned.st_ino, pinned.st_dev, pinned.st_nlink) != (info.st_ino, info.st_dev, info.st_nlink):
                                raise Failure('files_changed')
                            os.fchmod(fd, mode)
                        finally:
                            os.close(fd)
        return correct

    def secure(self, revert=False):
        self.validate()
        for measure in self.request.get('measures', []):
            if measure in CONSTANTS:
                name, desired = CONSTANTS[measure]
                current = self.config_values().get(name)
                if current is not None and str(current).lower() not in ('true', 'false', '0', '1'):
                    raise Failure('unsupported_constant')
                if revert:
                    if measure not in self.undo:
                        raise Failure('no_previous_value')
                    if str(current).lower() not in (('true', '1') if desired == 'true' else ('false', '0')):
                        raise Failure('configuration_changed')
                    self.config_change(name, self.undo[measure])
                    del self.undo[measure]
                else:
                    if measure not in self.undo:
                        self.undo[measure] = current
                    self.config_change(name, desired)
            elif measure == 'pingbacks':
                current = self.value('option', 'get', 'default_ping_status')
                if current not in ('open', 'closed'):
                    raise Failure('unsupported_option')
                if revert:
                    if 'pingbacks' not in self.undo or current != 'closed':
                        raise Failure('configuration_changed')
                    self.wp('option', 'update', 'default_ping_status', self.undo.pop('pingbacks'))
                else:
                    self.undo.setdefault('pingbacks', current)
                    self.wp('option', 'update', 'default_ping_status', 'closed')
            elif measure == 'salts':
                self.config_change('', salts=True)
                self.request['salts_changed'] = True
            elif measure == 'permissions':
                if not self.request.get('permissions_available'):
                    raise Failure('site_php_user_required')
                self.permissions(apply=True)
        result = self.check()
        result['salts_changed'] = bool(self.request.get('salts_changed'))
        return result


    def http_verify(self, url):
        # A plain 200 can be an old page while FPM still caches wp-config.php.
        # A unique, expiring probe must bootstrap WordPress and report its actual prefix.
        token = secrets.token_hex(24)
        name = '.ispcp-wp-probe-' + token + '.php'
        path = self.root / name
        expected = {'token': token, 'prefix': self.config_values()['table_prefix'], 'url': url}
        script = "<?php if (time() > " + str(int(time.time()) + 600) + " || !hash_equals('" + token + "', $_SERVER['HTTP_X_ISPCP_WP_PROBE'] ?? '')) {http_response_code(404);exit;} ob_start(); require __DIR__ . '/wp-load.php'; ob_end_clean(); header('Cache-Control: no-store'); header('Content-Type: application/json'); echo json_encode(['token'=>'" + token + "','prefix'=>$GLOBALS['table_prefix'],'url'=>get_option('siteurl')]);"
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
        with os.fdopen(fd, 'w') as output:
            output.write(script)
        try:
            for attempt in range(10):
                try:
                    self.http_verify_once(url, name, token, expected)
                    return self.http_verify_once(url)
                except Failure as error:
                    if str(error) != 'http_verification_failed' or attempt == 9:
                        raise
                    time.sleep(2)
        finally:
            path.unlink(missing_ok=True)

    def verification_url(self, url, error='http_verification_unavailable'):
        # The bridge derives these hosts from the native website/server configuration,
        # never from WordPress or request input. Connections still target loopback only.
        hosts = {self.request['domain'].lower(), 'www.' + self.request['domain'].lower()}
        hosts.update(host.lower() for host in self.request.get('verification_hosts', []))
        try:
            if re.search(r'[\x00-\x20\x7f\\]', url):
                raise ValueError('invalid URL')
            parsed = urllib.parse.urlsplit(url)
            if parsed.scheme not in ('http', 'https') or parsed.hostname not in hosts or parsed.username or parsed.password or parsed.port not in (None, 80, 443):
                raise ValueError('unapproved host')
        except ValueError:
            raise Failure(error) from None
        return parsed

    def http_verify_once(self, url, probe=None, token=None, expected=None):
        parsed = self.verification_url(url)
        path = (parsed.path.rstrip('/') or '') + '/' + (probe or '')
        for _ in range(4):
            if parsed.scheme == 'https':
                connection = http.client.HTTPSConnection('127.0.0.1', 443, timeout=20, context=ssl._create_unverified_context())
            else:
                connection = http.client.HTTPConnection('127.0.0.1', 80, timeout=20)
            try:
                connection.request('GET', path, headers={'Host': parsed.hostname, 'User-Agent': 'ISPCP-WordPress-Verification/1', 'Cache-Control': 'no-cache', 'X-ISPCP-WP-Probe': token or ''})
                response = connection.getresponse()
                body = response.read(65536)
                if response.status == 200 and body:
                    if expected is not None:
                        try:
                            valid = json.loads(body) == expected
                        except (ValueError, UnicodeError):
                            valid = False
                        if not valid:
                            raise Failure('http_verification_failed')
                    return
                location = response.getheader('Location')
                if response.status not in (301, 302, 307, 308) or not location:
                    raise Failure('http_verification_failed')
                next_url = self.verification_url(urllib.parse.urljoin(parsed.geturl(), location), 'http_verification_failed')
                parsed = next_url
                path = parsed.path + ('?' + parsed.query if parsed.query else '')
            except (OSError, http.client.HTTPException):
                # ISPConfig may reload the webserver while applying the same batch.
                # Use the existing bounded verification retry; never accept an
                # interrupted response as proof that the database change worked.
                raise Failure('http_verification_failed') from None
            finally:
                connection.close()
        raise Failure('http_verification_failed')

    def maintenance(self, enable):
        path = self.root / '.maintenance'
        marker = 'ISPCP WP JOB ' + self.request['job']
        if enable:
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
            with os.fdopen(fd, 'w') as output:
                output.write('<?php /* ' + marker + ' */ $upgrading = ' + str(int(time.time())) + ';')
        elif path.exists():
            content, _ = safe_file(path)
            if marker.encode() not in content:
                raise Failure('maintenance_changed')
            path.unlink()

    def owned_database(self, config):
        prefix, tables = self.database(config)
        if not self.request.get('database_available') or config.get('DB_NAME') != self.request.get('database_name') or config.get('DB_HOST') not in ('localhost', '127.0.0.1', 'localhost:3306', '127.0.0.1:3306'):
            raise Failure('owned_database_required')
        return prefix, tables

    def database_engines(self, prefix):
        names = [prefix + suffix for suffix in ('options', 'usermeta', 'users')]
        engines = self.query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' + ','.join(literal(name) for name in names) + ')').splitlines()
        if len(engines) != 3 or any(line.split('\t')[-1] != 'InnoDB' for line in engines):
            raise Failure('unsupported_database_engine')

    def prefix_namespace(self, prefix, target):
        # Only user_roles is a prefix-dependent core option. Ordinary options such as
        # wp_calendar_block_has_published_posts keep their literal names across prefixes.
        if self.query('SELECT 1 FROM ' + identifier(prefix + 'options') + ' WHERE option_name=' + literal(target + 'user_roles') + ' LIMIT 1'):
            raise Failure('prefix_conflict')
        # Per-site user options use wpdb::get_blog_prefix(); reject an ambiguous inverse.
        if self.query('SELECT 1 FROM ' + identifier(prefix + 'usermeta') + ' WHERE LEFT(meta_key,' + str(len(target)) + ')=' + literal(target) + ' AND LEFT(meta_key,' + str(len(prefix)) + ')<>' + literal(prefix) + ' LIMIT 1'):
            raise Failure('prefix_conflict')

    def metadata_updates(self, table_prefix, source, target):
        if source == target:
            return []
        return [
            'UPDATE ' + identifier(table_prefix + 'options') + ' SET option_name=' + literal(target + 'user_roles') + ' WHERE BINARY option_name=' + literal(source + 'user_roles'),
            'UPDATE ' + identifier(table_prefix + 'usermeta') + ' SET meta_key=CONCAT(' + literal(target) + ',SUBSTRING(meta_key,' + str(len(source) + 1) + ')) WHERE BINARY LEFT(meta_key,' + str(len(source)) + ')=' + literal(source),
        ]

    def database_plan(self):
        self.validate()
        config = self.config_values()
        prefix, tables = self.owned_database(config)
        self.database_engines(prefix)
        metadata = self.metadata()
        if metadata['multisite']:
            raise Failure('unsupported_database_layout')
        selected = self.request['measures']
        reverting = self.request['direction'] == 'revert'
        target = prefix
        undo = dict(self.undo)
        if 'prefix' in selected:
            if reverting:
                prior = undo.get('prefix', {})
                if prior.get('database') != config['DB_NAME'] or prior.get('applied') != prefix:
                    raise Failure('no_previous_value')
                target = prior['previous']
                del undo['prefix']
            elif prefix == 'wp_':
                target = 'wp_' + secrets.token_hex(6) + '_'
                undo['prefix'] = {'previous': prefix, 'applied': target, 'database': config['DB_NAME']}
            else:
                raise Failure('already_secured')
            identifier(target)
            for table in tables:
                identifier(target + table[len(prefix):])
            self.prefix_namespace(prefix, target)
        admin = None
        if 'admin_login' in selected:
            prior = undo.get('admin_login', {})
            if reverting:
                if prior.get('database') != config['DB_NAME'] or type(prior.get('id')) is not int or prior['id'] < 1:
                    raise Failure('no_previous_value')
                lookup = str(prior['id'])
                login = prior['previous']
            else:
                lookup = 'admin'
                login = self.request.get('admin_login', '')
                if not re.fullmatch(r'[A-Za-z][A-Za-z0-9_.-]{2,59}', login) or login.lower() == 'admin':
                    raise Failure('invalid_admin_login')
            admin = json.loads(self.value('user', 'get', lookup, '--fields=ID,user_login,roles', '--format=json'))
            if 'administrator' not in admin.get('roles', []) or (reverting and admin['user_login'] != prior.get('applied')):
                raise Failure('configuration_changed')
            uid = int(admin['ID'])
            if self.query('SELECT ID FROM ' + identifier(prefix + 'users') + ' WHERE user_login=' + literal(login) + ' AND ID<>' + str(uid)):
                raise Failure('admin_login_exists')
            if reverting:
                del undo['admin_login']
            else:
                undo['admin_login'] = {'id': uid, 'previous': admin['user_login'], 'applied': login, 'database': config['DB_NAME']}
            admin['target'] = login
        self.http_verify(metadata['url'])
        return {'prepared': {'prefix': prefix, 'new_prefix': target, 'tables': tables, 'url': metadata['url'], 'admin': admin,
                             'database': config['DB_NAME'], 'config_hash': metadata['config_hash'], 'undo': undo}}

    def database_marker(self):
        job = self.request.get('job', '')
        if not re.fullmatch(r'[a-f0-9-]{36}', job):
            raise Failure('invalid_job')
        return '_ispcp_database_' + job

    def database_apply(self):
        before = self.request['prepared']
        prefix, target, admin = before['prefix'], before['new_prefix'], before['admin']
        try:
            config = self.config_values()
            current_prefix, tables = self.owned_database(config)
            if current_prefix != prefix or sorted(tables) != sorted(before['tables']) or hashlib.sha256(safe_file(self.config)[0]).hexdigest() != before['config_hash']:
                return {'error': 'configuration_changed', 'not_applied': True}
            if target != prefix:
                self.prefix_namespace(prefix, target)
            if admin and self.query('SELECT user_login FROM ' + identifier(prefix + 'users') + ' WHERE ID=' + str(int(admin['ID']))) != admin['user_login']:
                return {'error': 'configuration_changed', 'not_applied': True}
        except Failure as error:
            return {'error': str(error), 'not_applied': True}
        self.maintenance(True)
        if prefix != target:
            self.query('RENAME TABLE ' + ', '.join(identifier(name) + ' TO ' + identifier(target + name[len(prefix):]) for name in tables))
        statements = ["INSERT INTO " + identifier(target + 'options') + " (option_name,option_value,autoload) VALUES (" + literal(self.database_marker()) + ",'applied','no')"]
        statements += self.metadata_updates(target, prefix, target)
        if admin:
            statements.append('UPDATE ' + identifier(target + 'users') + ' SET user_login=' + literal(admin['target']) + ' WHERE ID=' + str(int(admin['ID'])) + ' AND BINARY user_login=' + literal(admin['user_login']))
        # Marker and all data changes commit together; a lost DB connection rolls all of them back.
        self.query('START TRANSACTION; ' + '; '.join(statements) + '; COMMIT;')
        if target != prefix:
            self.config_change('table_prefix', target, variable=True)
        self.wp('cache', 'flush')
        self.maintenance(False)
        self.verify_one_way(before)
        if admin:
            after = json.loads(self.value('user', 'get', str(int(admin['ID'])), '--fields=ID,user_login,roles', '--format=json'))
            if after['user_login'] != admin['target'] or after['roles'] != admin['roles']:
                raise Failure('verification_failed')
        self.undo = before['undo']
        return {'installation': self.check()}

    def database_restore(self):
        before = self.request['prepared']
        prefix, target, admin = before['prefix'], before['new_prefix'], before['admin']
        config = self.config_values()
        if config.get('DB_NAME') != before['database'] or config.get('DB_HOST') not in ('localhost', '127.0.0.1', 'localhost:3306', '127.0.0.1:3306') or config.get('table_prefix') not in (prefix, target):
            raise Failure('configuration_changed')
        current = sorted(self.query('SHOW TABLES').splitlines())
        original = sorted(before['tables'])
        renamed = sorted(target + name[len(prefix):] for name in before['tables'])
        if current not in (original, renamed):
            raise Failure('recovery_required')
        live = target if current == renamed else prefix
        marker = self.query('SELECT option_value FROM ' + identifier(live + 'options') + ' WHERE option_name=' + literal(self.database_marker()))
        if marker:
            if marker != 'applied' or live != target:
                raise Failure('recovery_required')
            statements = self.metadata_updates(live, target, prefix)
            if admin:
                login = self.query('SELECT user_login FROM ' + identifier(live + 'users') + ' WHERE ID=' + str(int(admin['ID'])))
                if login != admin['target']:
                    raise Failure('configuration_changed')
                if self.query('SELECT ID FROM ' + identifier(live + 'users') + ' WHERE user_login=' + literal(admin['user_login']) + ' AND ID<>' + str(int(admin['ID']))):
                    raise Failure('admin_login_exists')
                statements.append('UPDATE ' + identifier(live + 'users') + ' SET user_login=' + literal(admin['user_login']) + ' WHERE ID=' + str(int(admin['ID'])))
            statements.append('DELETE FROM ' + identifier(live + 'options') + ' WHERE option_name=' + literal(self.database_marker()))
            self.query('START TRANSACTION; ' + '; '.join(statements) + '; COMMIT;')
        if live != prefix:
            self.query('RENAME TABLE ' + ', '.join(identifier(target + name[len(prefix):]) + ' TO ' + identifier(name) for name in before['tables']))
        if config.get('table_prefix') != prefix:
            self.config_change('table_prefix', prefix, variable=True)
        self.wp('cache', 'flush')
        self.maintenance(False)
        self.verify_one_way(dict(before, new_prefix=prefix))
        if admin:
            restored = json.loads(self.value('user', 'get', str(int(admin['ID'])), '--fields=ID,user_login,roles', '--format=json'))
            if restored['user_login'] != admin['user_login'] or restored['roles'] != admin['roles']:
                raise Failure('recovery_required')
        return {'restored': True}

    def database_finalize(self):
        config = self.config_values()
        prefix, _ = self.owned_database(config)
        self.query('DELETE FROM ' + identifier(prefix + 'options') + ' WHERE option_name=' + literal(self.database_marker()))
        return {'cleaned': True}

    def prepare(self):
        if not self.request.get('confirmed') or not self.request.get('backup') or not self.request.get('database_available'):
            raise Failure('backup_required')
        self.validate()
        config = self.config_values()
        prefix, tables = self.database(config)
        metadata = self.metadata()
        if metadata['multisite']:
            raise Failure('unsupported_database_layout')
        self.http_verify(metadata['url'])
        old_admin = None
        if 'admin_login' in self.request['measures']:
            login = self.request.get('admin_login', '')
            if not re.fullmatch(r'[A-Za-z][A-Za-z0-9_.-]{2,59}', login) or login.lower() == 'admin':
                raise Failure('invalid_admin_login')
            old_admin = json.loads(self.value('user', 'get', 'admin', '--fields=ID,user_login,roles', '--format=json'))
            if 'administrator' not in old_admin.get('roles', []):
                raise Failure('not_administrator')
            existing = self.query('SELECT ID FROM ' + identifier(prefix + 'users') + ' WHERE user_login=' + literal(login))
            if existing:
                raise Failure('admin_login_exists')
        before = {'prefix': prefix, 'tables': tables, 'url': metadata['url'], 'admin': old_admin,
                  'config_hash': metadata['config_hash'], 'new_prefix': 'wp_' + secrets.token_hex(6) + '_' if 'prefix' in self.request['measures'] and prefix == 'wp_' else prefix}
        self.maintenance(True)
        try:
            original, info = safe_file(self.config)
            replace_file(pathlib.Path('/work/rollback-config'), original, 0o600)
            before['config_mode'] = stat.S_IMODE(info.st_mode)
            self.wp('db', 'export', '/work/rollback.sql', '--single-transaction', '--add-drop-table', timeout=900)
            backup = pathlib.Path('/work/rollback.sql').stat()
            if backup.st_size < 100:
                raise Failure('backup_failed')
            return {'prepared': before}
        except Exception:
            self.maintenance(False)
            raise

    def apply_one_way(self):
        before = self.request['prepared']
        prefix = before['prefix']
        new = before['new_prefix']
        # Configuration or DB changes while waiting for the protected backup invalidate the operation.
        if hashlib.sha256(safe_file(self.config)[0]).hexdigest() != before['config_hash']:
            return {'error': 'configuration_changed', 'not_applied': True}
        current_prefix, current_tables = self.database(self.config_values())
        if current_prefix != prefix or sorted(current_tables) != sorted(before['tables']):
            return {'error': 'database_changed', 'not_applied': True}
        if new != prefix:
            mapping = [(name, new + name[len(prefix):]) for name in before['tables']]
            self.query('RENAME TABLE ' + ', '.join(identifier(old) + ' TO ' + identifier(target) for old, target in mapping))
            for table, column in ((new + 'options', 'option_name'), (new + 'usermeta', 'meta_key')):
                self.query('UPDATE ' + identifier(table) + ' SET ' + identifier(column) + '=CONCAT(' + literal(new) + ',SUBSTRING(' + identifier(column) + ',' + str(len(prefix) + 1) + ')) WHERE LEFT(' + identifier(column) + ',' + str(len(prefix)) + ')=' + literal(prefix))
            self.config_change('table_prefix', new, variable=True)
        if before['admin']:
            uid = int(before['admin']['ID'])
            self.query('UPDATE ' + identifier(new + 'users') + ' SET user_login=' + literal(self.request['admin_login']) + ' WHERE ID=' + str(uid) + ' AND user_login=' + literal('admin'))
            self.wp('eval', 'clean_user_cache(' + str(uid) + ');')
            after = json.loads(self.value('user', 'get', self.request['admin_login'], '--fields=ID,user_login,roles', '--format=json'))
            if int(after['ID']) != uid or after['roles'] != before['admin']['roles']:
                raise Failure('verification_failed')
        self.maintenance(False)
        self.verify_one_way(before)
        return {'installation': self.check()}

    def verify_one_way(self, before):
        if self.value('option', 'get', 'siteurl') != before['url']:
            raise Failure('verification_failed')
        prefix, tables = self.database(self.config_values())
        if prefix != before['new_prefix'] or sorted(tables) != sorted(before['new_prefix'] + name[len(before['prefix']):] for name in before['tables']):
            raise Failure('verification_failed')
        self.http_verify(before['url'])

    def restore(self):
        import base64
        before = self.request['prepared']
        original = base64.b64decode(self.request['original_config'], validate=True)
        replace_file(self.config, original, before['config_mode'])
        current = self.query('SHOW TABLES').splitlines()
        allowed = set(before['tables']) | {before['new_prefix'] + name[len(before['prefix']):] for name in before['tables']}
        if any(table not in allowed for table in current):
            raise Failure('recovery_required')
        # The stream comes from the root-owned rollback snapshot. It is never parsed by a root DB process.
        path = pathlib.Path('/work/restore-' + secrets.token_hex(8) + '.sql')
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as output:
            while True:
                chunk = sys.stdin.buffer.read(1048576)
                if not chunk:
                    break
                output.write(chunk)
        if current:
            self.query('SET FOREIGN_KEY_CHECKS=0; DROP TABLE ' + ', '.join(identifier(table) for table in current) + '; SET FOREIGN_KEY_CHECKS=1;')
        self.wp('db', 'import', str(path), timeout=900)
        self.maintenance(False)
        self.verify_one_way(dict(before, new_prefix=before['prefix']))
        if before['admin']:
            admin = json.loads(self.value('user', 'get', 'admin', '--fields=ID,user_login,roles', '--format=json'))
            if int(admin['ID']) != int(before['admin']['ID']) or admin['roles'] != before['admin']['roles']:
                raise Failure('recovery_required')
        return {'restored': True}


def scan(request):
    installations = []
    count = 0
    incomplete = False
    start = time.monotonic()
    for base, dirs, files in os.walk('/site', followlinks=False):
        dirs[:] = [name for name in dirs if name not in SKIP and not pathlib.Path(base, name).is_symlink()]
        count += len(dirs) + len(files)
        if count > 100000 or len(installations) >= 20 or time.monotonic() - start > 120:
            incomplete = True
            break
        if 'wp-config.php' not in files or not pathlib.Path(base, 'wp-includes/version.php').is_file():
            continue
        path = str(pathlib.Path(base).relative_to('/site'))
        path = '' if path == '.' else path
        tool = Toolkit(dict(request, path=path))
        try:
            tool.validate()
            row = tool.metadata()
            config = tool.config_values()
            row.update(database_name=config.get('DB_NAME', ''), database_host=config.get('DB_HOST', ''), security={})
        except Exception:
            row = {'id': hashlib.sha256(path.encode()).hexdigest()[:32], 'path': path, 'url': '', 'title': '', 'version': '', 'admin_url': '', 'security': {}, 'error': 'wp_cli_failed'}
        installations.append(row)
        # Other installations can be below a subfolder, but never search this install's plugins/uploads.
        dirs[:] = [name for name in dirs if name != 'wp-content']
    return {'installations': installations, 'incomplete': incomplete}


def main(request):
    if os.geteuid() == 0:
        raise Failure('root_forbidden')
    pathlib.Path('/work/home').mkdir(mode=0o700, exist_ok=True)
    if request['action'] == 'probe':
        assert not pathlib.Path('/etc/passwd').exists()
        assert not pathlib.Path('/usr/local/ispconfig/server/lib/config.inc.php').exists()
        assert not pathlib.Path('/var/www').exists()
        assert not pathlib.Path('/proc/1/root/etc/shadow').exists()
        return {'uid': os.geteuid(), 'gid': os.getegid(), 'isolated': True, 'wp_cli': run(request['php'] + ['/tool/wp-cli.phar', '--version'])[0]}
    if request['action'] == 'cron_poll':
        token = request.get('cron_token', '')
        if not re.fullmatch(r'[a-f0-9-]{36}', token):
            raise Failure('invalid_job')
        marker = pathlib.Path('/trigger/.ispcp-wp-cron-' + token)
        if not marker.exists():
            return {'triggered': False}
        content, info = safe_file(marker, 0)
        if content:
            raise Failure('invalid_trigger')
        marker.unlink()
        return {'triggered': True}
    if request['action'] == 'rescan':
        return scan(request)
    toolkit = Toolkit(request)
    if request['action'] == 'verify_integrity':
        return toolkit.integrity()
    if request['action'] in ('prepare_cron', 'cron_enable', 'cron_disable'):
        return toolkit.cron_constant(request['action'] != 'cron_disable')
    if request['action'] == 'cron_run':
        if toolkit.config_values().get('DISABLE_WP_CRON') not in ('true', '1'):
            raise Failure('cron_changed')
        # Load plugins/themes here: their registered callbacks are the purpose of WordPress cron.
        started = time.time()
        try:
            output, code = run(request['php'] + ['/tool/wp-cli.phar', '--path=' + str(toolkit.root), '--skip-packages', '--no-color', 'cron', 'event', 'run', '--due-now'],
                               timeout=300, accepted=tuple(range(256)), include_stderr=True)
        except Failure as error:
            cron_log(request.get('path', ''), started, str(error), '')
            raise
        cron_log(request.get('path', ''), started, 'ok' if code == 0 else 'exit %d' % code, output)
        if code != 0:
            raise CronFailure('wp_command_failed', output[-8192:])
        return {'cron_ran': True, 'output': output[-8192:]}
    if request['action'] == 'prepare_security':
        values = toolkit.config_values()
        for measure in request.get('measures', []):
            if measure in CONSTANTS:
                current = values.get(CONSTANTS[measure][0])
                if current is not None and str(current).lower() not in ('true', 'false', '0', '1'):
                    raise Failure('unsupported_constant')
                toolkit.undo.setdefault(measure, current)
            elif measure == 'pingbacks':
                current = toolkit.value('option', 'get', 'default_ping_status')
                if current not in ('open', 'closed'):
                    raise Failure('unsupported_option')
                toolkit.undo.setdefault(measure, current)
        return {'installation': toolkit.check()}
    if request['action'] in ('database_plan', 'database_apply', 'database_restore', 'database_finalize'):
        return getattr(toolkit, request['action'])()
    if request['action'] == 'prepare':
        return toolkit.prepare()
    if request['action'] == 'one_way_apply':
        return toolkit.apply_one_way()
    if request['action'] == 'restore':
        return toolkit.restore()
    if request['action'] == 'maintenance_cleanup':
        toolkit.maintenance(False)
        return {'cleaned': True}
    if request['action'] == 'check':
        result = toolkit.check()
        return {'installation': result}
    if request['action'] in ('secure', 'revert'):
        return {'installation': toolkit.secure(request['action'] == 'revert')}
    raise Failure('unknown_action')


if __name__ == '__main__':
    try:
        print(json.dumps(main(json.loads(sys.stdin.buffer.readline(1048577)))))
    except Exception as error:
        result = {'error': str(error) if isinstance(error, Failure) else 'wp_cli_failed'}
        if isinstance(error, CronFailure):
            result['output'] = error.output
        if not isinstance(error, Failure):
            trace = error.__traceback__
            while trace.tb_next:
                trace = trace.tb_next
            result['diagnostic'] = {'exception': type(error).__name__, 'line': trace.tb_lineno}
        print(json.dumps(result))
