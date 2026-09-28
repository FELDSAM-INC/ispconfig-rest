#!/usr/bin/python3
"""Root orchestrator for the existing file-manager worker. Never executes site code as root."""
import contextlib
import base64
import shutil
import hashlib
import json
import os
import pathlib
import pwd
import grp
import re
import resource
import signal
import stat
import subprocess
import tempfile
import time

from reconcile import open_directory

RUNTIME = pathlib.Path('/usr/local/share/ispconfig-rest-wordpress')
STATE = pathlib.Path('/var/lib/ispcp-files/wordpress')
MAX_RESULT = 1024 * 1024


class Unavailable(Exception):
    pass


def trusted(path, executable=False):
    resolved = pathlib.Path(path).resolve(strict=True)
    for parent in (resolved, *resolved.parents):
        item = parent.stat()
        if item.st_uid != 0 or item.st_mode & 0o022:
            raise Unavailable('unsafe_runtime')
    info = resolved.stat()
    if info.st_mode & (stat.S_ISUID | stat.S_ISGID) or not stat.S_ISREG(info.st_mode):
        raise Unavailable('unsafe_runtime')
    if executable and not os.access(resolved, os.X_OK):
        raise Unavailable('runtime_missing')
    return str(resolved)


def identity(site, root):
    return hashlib.sha256(('|'.join(str(site.get(k) or '') for k in ('domain_id', 'server_id', 'sys_groupid', 'domain', 'system_user', 'system_group')) + '|' + root).encode()).hexdigest()


def runtime(php):
    php = trusted(php, True)
    with open(php, 'rb') as executable:
        if executable.read(4) != b'\x7fELF':
            raise Unavailable('unsupported_php_layout')
    trusted('/usr/bin/bwrap', True)
    trusted(RUNTIME / 'wp-cli.phar')
    trusted(RUNTIME / 'wordpress-tools.py')
    # Read CLI-only extension configuration; never expose FPM pool files to a site.
    nobody = pwd.getpwnam('nobody')
    info = subprocess.run([php, '-n', '-r', 'echo json_encode([PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION,ini_get("extension_dir")]);'], user=nobody.pw_uid, group=nobody.pw_gid, extra_groups=[], env={'PATH': '/usr/bin:/bin'}, capture_output=True, timeout=10, check=True)
    version, extension_dir = json.loads(info.stdout)
    if not re.fullmatch(r'[0-9]+\.[0-9]+', version) or not re.fullmatch(r'/usr/lib/php/[0-9]+', extension_dir):
        raise Unavailable('unsupported_php_layout')
    args = [php, '-n', '-d', 'memory_limit=256M', '-d', 'max_execution_time=300', '-d', 'date.timezone=UTC', '-d', 'error_reporting=22527', '-d', 'display_errors=stderr']
    for name in ('mysqlnd', 'mysqli', 'mbstring', 'tokenizer', 'ctype', 'phar', 'iconv', 'curl', 'dom', 'xml', 'simplexml', 'xmlreader', 'xmlwriter', 'zip', 'intl'):
        so = pathlib.Path(extension_dir, name + '.so')
        if so.exists():
            trusted(so)
            args.extend(['-d', 'extension=' + str(so)])
    return args


def account(site):
    if not re.fullmatch(r'web[1-9][0-9]*', site['system_user']) or not re.fullmatch(r'client[0-9]+', site['system_group']):
        raise Unavailable('unsafe_site_identity')
    user = pwd.getpwnam(site['system_user'])
    group = grp.getgrnam(site['system_group'])
    if user.pw_uid < 1000 or group.gr_gid < 1000 or user.pw_gid != group.gr_gid:
        raise Unavailable('unsafe_site_identity')
    return user.pw_uid, group.gr_gid


def limits():
    os.umask(0o077)
    # Bounds apply to PHP and all of its descendants; no shell, terminal, or ambient credentials.
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_NOFILE, (256, 256))
    resource.setrlimit(resource.RLIMIT_NPROC, (128, 128))
    resource.setrlimit(resource.RLIMIT_FSIZE, (32 * 1024**3, 32 * 1024**3))


def command(uid, gid, rootfd, workfd, php, readonly=False, backup=None):
    args = ['/usr/bin/bwrap', '--unshare-all', '--unshare-user', '--share-net', '--die-with-parent', '--new-session', '--disable-userns', '--cap-drop', 'ALL', '--clearenv',
            '--setenv', 'PATH', '/usr/bin:/bin', '--setenv', 'HOME', '/work/home', '--setenv', 'LANG', 'C.UTF-8',
            '--setenv', 'WP_CLI_CONFIG_PATH', '/tool/empty.yml', '--setenv', 'WP_CLI_PACKAGES_DIR', '/work/packages', '--setenv', 'WP_CLI_CACHE_DIR', '/work/cache',
            '--ro-bind', '/usr/bin', '/usr/bin', '--ro-bind', '/usr/lib', '/usr/lib', '--symlink', 'usr/bin', '/bin', '--symlink', 'usr/sbin', '/sbin']
    args.extend(['--ro-bind', '/usr/share/zoneinfo', '/usr/share/zoneinfo'])
    if pathlib.Path('/usr/lib64').exists():
        args.extend(['--ro-bind', '/usr/lib64', '/usr/lib64'])
    for name in ('lib', 'lib64'):
        if pathlib.Path('/' + name).exists():
            if pathlib.Path('/' + name).is_symlink():
                args.extend(['--symlink', os.readlink('/' + name), '/' + name])
            else:
                args.extend(['--ro-bind', '/' + name, '/' + name])
    args += ['--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp', '--dir', '/etc', '--dir', '/run', '--dir', '/site', '--dir', '/work',
             '--ro-bind', str(RUNTIME), '/tool', '--ro-bind-fd' if readonly else '--bind-fd', str(rootfd), '/site',
             '--bind-fd', str(workfd), '/work']
    for name in ('resolv.conf', 'hosts', 'nsswitch.conf'):
        if pathlib.Path('/etc', name).exists():
            args.extend(['--ro-bind', str(pathlib.Path('/etc', name).resolve()), '/etc/' + name])
    if pathlib.Path('/etc/ssl/certs').is_dir():
        args.extend(['--ro-bind', '/etc/ssl/certs', '/etc/ssl/certs'])
    if pathlib.Path('/run/mysqld').is_dir():
        args.extend(['--ro-bind', '/run/mysqld', '/run/mysqld', '--symlink', '/run', '/var/run'])
    if backup is not None:
        args.extend(['--ro-bind-fd', str(backup), '/backup'])
    args.extend(['--chdir', '/tool', '/usr/bin/python3', '-I', '/tool/wordpress-tools.py'])
    return args


def execute(site, request, workspace, *, backup=None, timeout=360, input_stream=None):
    uid, gid = account(site)
    root = request['public_root']
    folder = 'web' if site['type'] == 'vhost' else site['web_folder']
    base = site['document_root'].rstrip('/') + '/' + folder
    if root != base and not root.startswith(base + '/'):
        raise Unavailable('unsafe_document_root')
    with contextlib.ExitStack() as stack:
        rootfd = open_directory(root)
        stack.callback(os.close, rootfd)
        info = os.fstat(rootfd)
        if info.st_uid != uid or info.st_gid != gid or info.st_mode & 0o002:
            raise Unavailable('unsafe_document_root')
        workfd = open_directory(str(workspace))
        stack.callback(os.close, workfd)
        php = runtime(site['php_cli'])
        request = dict(request, php=php, domain=site['domain'], verification_hosts=site.get('verification_hosts', []), uid=uid, gid=gid)
        args = command(uid, gid, rootfd, workfd, php, request['action'] in ('rescan', 'check', 'prepare_security', 'verify_integrity', 'prepare_cron', 'cron_poll'), backup)
        descriptors = (rootfd, workfd) + (() if backup is None else (backup,))
        if request['action'] == 'cron_poll':
            privatefd = open_directory(site['document_root'].rstrip('/') + '/private')
            stack.callback(os.close, privatefd)
            private = os.fstat(privatefd)
            if private.st_uid != uid or private.st_gid != gid or private.st_mode & 0o022:
                raise Unavailable('unsafe_private_directory')
            args[1:1] = ['--bind-fd', str(privatefd), '/trigger']
            descriptors += (privatefd,)
        # Passing an already-pinned directory prevents path substitution between validation and bind.
        process = subprocess.Popen(args, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                   pass_fds=descriptors, user=uid, group=gid, extra_groups=[], env={'PATH': '/usr/bin:/bin'},
                                   preexec_fn=limits, start_new_session=True)
        try:
            # stdout is a tiny control result, never unbounded command output. The inner runner captures
            # WP-CLI output in bounded files, and this parent kills a malicious output flood promptly.
            import selectors
            result = bytearray()
            selector = selectors.DefaultSelector()
            selector.register(process.stdout, selectors.EVENT_READ)
            os.set_blocking(process.stdin.fileno(), False)
            selector.register(process.stdin, selectors.EVENT_WRITE)
            pending = json.dumps(request).encode() + b'\n'
            started = time.monotonic()
            while selector.get_map():
                if time.monotonic() - started > timeout:
                    raise Unavailable('command_timeout')
                for key, _ in selector.select(0.2):
                    if key.fileobj is process.stdin:
                        if not pending and input_stream is not None:
                            pending = input_stream.read(65536)
                        if not pending:
                            selector.unregister(process.stdin)
                            process.stdin.close()
                            continue
                        try:
                            written = os.write(process.stdin.fileno(), pending)
                            pending = pending[written:]
                        except BrokenPipeError:
                            selector.unregister(process.stdin)
                            process.stdin.close()
                    else:
                        chunk = os.read(key.fileobj.fileno(), 65536)
                        if not chunk:
                            selector.unregister(key.fileobj)
                        else:
                            result.extend(chunk)
                        if len(result) > MAX_RESULT:
                            raise Unavailable('invalid_worker_result')
            process.wait(timeout=5)
            if process.returncode:
                raise Unavailable('sandbox_failed')
            result = json.loads(result)
            if not isinstance(result, dict):
                raise Unavailable('invalid_worker_result')
            return result
        finally:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()



def protected_backup(source, target, uid, maximum):
    fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != uid or not 0 < info.st_size <= maximum:
            raise Unavailable('backup_failed')
        out = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(out, 'wb') as sink, os.fdopen(fd, 'rb', closefd=False) as stream:
            copied = 0
            while True:
                chunk = stream.read(1048576)
                if not chunk:
                    break
                copied += len(chunk)
                if copied > maximum:
                    raise Unavailable('backup_failed')
                sink.write(chunk)
            sink.flush()
            os.fsync(sink.fileno())
        if os.stat(target).st_size != info.st_size:
            raise Unavailable('backup_failed')
    finally:
        os.close(fd)


def checkpoint(path, value):
    temp = path.with_suffix('.new')
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as output:
        json.dump(value, output)
        output.flush()
        os.fsync(output.fileno())
    os.replace(temp, path)
    parent = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(parent)
    finally:
        os.close(parent)


def one_way(site, request, work, data):
    uid, gid = account(site)
    recovery = STATE / (data['job'] + '.recovery')
    recovery.mkdir(mode=0o700, exist_ok=True)
    info = recovery.lstat()
    if info.st_uid != 0 or info.st_mode & 0o077 or not stat.S_ISDIR(info.st_mode):
        raise Unavailable('unsafe_workspace')
    state_path = recovery / 'state.json'
    if data.get('resuming') and not state_path.exists():
        execute(site, dict(request, action='maintenance_cleanup'), work)
        return {'error': 'interrupted_check_required'}
    if state_path.exists():
        state = json.loads(state_path.read_text())
        if state['identity'] != identity(site, request['public_root']):
            raise Unavailable('site_changed')
        if state['phase'] == 'abandoned':
            return {'error': 'configuration_changed'}
        if state['phase'] == 'restored':
            return {'error': 'interrupted_restored'}
        if state['phase'] == 'completed':
            return execute(site, dict(request, action='check'), work)
        # A killed process may have committed an SQL rename before writing its result.
        # Always restore the protected snapshot; never repeat an uncertain mutation.
        prepared = state['prepared']
        failure = 'interrupted_restored'
        cause = 'interrupted'
    else:
        # No mutation is attempted until both existing DB export and protected rollback files exist.
        try:
            prepared_result = execute(site, dict(request, action='prepare'), work, timeout=1000)
            if 'error' in prepared_result:
                return prepared_result
            prepared = prepared_result['prepared']
            protected_backup(work / 'rollback.sql', recovery / 'database.sql', uid, 32 * 1024**3)
            protected_backup(work / 'rollback-config', recovery / 'wp-config.php', uid, 1048576)
            state = {'phase': 'prepared', 'identity': identity(site, request['public_root']), 'prepared': prepared}
            checkpoint(state_path, state)
        except Exception:
            execute(site, dict(request, action='maintenance_cleanup'), work)
            return {'error': 'backup_failed'}
        try:
            result = execute(site, dict(request, action='one_way_apply', prepared=prepared), work, timeout=1000)
            if 'error' not in result:
                checkpoint(state_path, dict(state, phase='completed'))
                return result
            if result.get('not_applied') is True:
                checkpoint(state_path, dict(state, phase='abandoned'))
                execute(site, dict(request, action='maintenance_cleanup'), work)
                return {'error': result['error']}
            cause = result.get('error', 'worker_failed')
            failure = 'verification_failed_restored'
        except Exception:
            cause = 'worker_failed'
            failure = 'verification_failed_restored'
    try:
        config = base64.b64encode((recovery / 'wp-config.php').read_bytes()).decode()
        with (recovery / 'database.sql').open('rb') as sql:
            restored = execute(site, dict(request, action='restore', prepared=prepared, original_config=config), work, timeout=1000, input_stream=sql)
        if restored.get('restored') is not True:
            return {'error': 'recovery_required', 'recovery_required': True}
        checkpoint(state_path, dict(state, phase='restored', cause=cause))
        return {'error': failure}
    except Exception:
        return {'error': 'recovery_required', 'recovery_required': True}


def database_change(site, request, work, data):
    """Small protected intent journal; inverse SQL preserves content added after the change."""
    recovery = STATE / (data['job'] + '.recovery')
    recovery.mkdir(mode=0o700, exist_ok=True)
    info = recovery.lstat()
    if info.st_uid != 0 or info.st_mode & 0o077 or not stat.S_ISDIR(info.st_mode):
        raise Unavailable('unsafe_workspace')
    state_path = recovery / 'state.json'
    request = dict(request, direction=request['action'])
    if state_path.exists():
        state = json.loads(state_path.read_text())
        if state.get('kind') != 'database_change' or state['identity'] != identity(site, request['public_root']):
            raise Unavailable('site_changed')
        if state['phase'] in ('restored', 'abandoned'):
            return {'error': 'interrupted_restored' if state['phase'] == 'restored' else 'configuration_changed'}
        prepared = state['prepared']
        if state['phase'] == 'completed':
            try:
                execute(site, dict(request, action='database_finalize'), work)
            except Exception:
                pass  # The verified change is committed; marker cleanup may be retried.
            return execute(site, dict(request, action='check', undo=prepared['undo']), work)
        failure = 'interrupted_restored'
    else:
        if data.get('resuming'):
            return {'error': 'interrupted_check_required'}
        result = execute(site, dict(request, action='database_plan'), work)
        if 'error' in result:
            return result
        prepared = result['prepared']
        state = {'kind': 'database_change', 'phase': 'prepared', 'identity': identity(site, request['public_root']), 'prepared': prepared}
        checkpoint(state_path, state)  # No database mutation precedes this durable root-owned record.
        try:
            result = execute(site, dict(request, action='database_apply', prepared=prepared), work)
            if 'error' not in result:
                checkpoint(state_path, dict(state, phase='completed'))
                try:
                    execute(site, dict(request, action='database_finalize'), work)
                except Exception:
                    pass  # Never roll back a committed change because marker cleanup failed.
                return result
            if result.get('not_applied'):
                checkpoint(state_path, dict(state, phase='abandoned'))
                return {'error': result['error']}
        except Exception:
            pass
        failure = 'verification_failed_restored'
    try:
        restored = execute(site, dict(request, action='database_restore', prepared=prepared), work)
        if restored.get('restored') is not True:
            return {'error': 'recovery_required', 'recovery_required': True}
        checkpoint(state_path, dict(state, phase='restored'))
        return {'error': failure}
    except Exception:
        return {'error': 'recovery_required', 'recovery_required': True}


def cleanup(jobs):
    # IDs come from terminal database rows, never from site-controlled paths.
    # shutil.rmtree on this platform pins descriptors and does not follow symlinks.
    if not shutil.rmtree.avoids_symlink_attacks:
        raise Unavailable('unsafe_runtime')
    for job in jobs:
        if not re.fullmatch(r'[a-f0-9-]{36}', job):
            raise Unavailable('invalid_job')
        for suffix in ('', '.recovery'):
            path = STATE / (job + suffix)
            if path.is_symlink():
                path.unlink()
            elif path.exists():
                shutil.rmtree(path)
    return {'cleaned': True}


def main():
    if os.geteuid() != 0:
        raise SystemExit(1)
    data = json.load(__import__('sys').stdin)
    if 'cleanup' in data:
        print(json.dumps(cleanup(data['cleanup'])))
        return
    site, request = data['site'], data['request']
    request['job'] = data['job']
    STATE.mkdir(mode=0o711, parents=True, exist_ok=True)
    uid, gid = account(site)
    # Persistent job paths are server-generated UUIDs, never user filenames.
    job = data['job']
    if not re.fullmatch(r'[a-f0-9-]{36}', job):
        raise Unavailable('invalid_job')
    work = STATE / job
    work.mkdir(mode=0o700, exist_ok=True)
    if work.is_symlink() or work.stat().st_uid not in (0, uid):
        raise Unavailable('unsafe_workspace')
    os.chown(work, uid, gid)
    irreversible = request['action'] == 'secure' and bool(set(request.get('measures', [])) & {'prefix', 'admin_login'})
    if request.get('database_change') and request['action'] in ('secure', 'revert'):
        result = database_change(site, request, work, data)
        if 'error' not in result:
            remaining = [key for key in request['measures'] if key not in ('prefix', 'admin_login')]
            if remaining:
                next_result = execute(site, dict(request, measures=remaining, undo=result['installation']['undo']), work)
                if 'error' in next_result:
                    result['operation_error'] = next_result['error']
                else:
                    result = next_result
    elif irreversible:
        if not data.get('backup_id'):
            raise Unavailable('backup_required')
        result = one_way(site, request, work, data)
        if 'error' not in result:
            # Apply selected reversible/local measures after the verified one-way DB transaction.
            remaining = [key for key in request['measures'] if key not in ('prefix', 'admin_login')]
            if remaining:
                result = execute(site, dict(request, measures=remaining), work)
    else:
        result = execute(site, request, work)
    print(json.dumps(result))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Do not reveal WP output, SQL, configuration values, credentials or exception bodies.
        safe = str(error) if isinstance(error, Unavailable) else 'worker_failed'
        print(json.dumps({'error': safe}))
