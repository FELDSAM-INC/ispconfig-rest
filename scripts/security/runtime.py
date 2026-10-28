#!/usr/bin/env python3
"""Real HTTP security smoke tests on loopback; no production URL or database input."""
import base64
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]


def main():
    with tempfile.TemporaryDirectory(prefix='ispconfig-runtime-') as temporary:
        directory = Path(temporary)
        database = directory / 'database.sqlite'
        database.touch()
        # Force every connection/cache setting, including Laravel's config-cache
        # location, so a developer's existing .env/cache cannot select a live DB.
        environment = {
            'PATH': os.environ.get('PATH', ''),
            'APP_ENV': 'production', 'APP_DEBUG': 'false',
            'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
            'APP_CONFIG_CACHE': str(directory / 'config.php'),
            'APP_ROUTES_CACHE': str(directory / 'routes.php'),
            'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(database), 'DB_URL': '',
            'DATABASE_URL': '', 'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
            'QUEUE_CONNECTION': 'sync', 'LOG_CHANNEL': 'stderr',
            'API_EXTENSIONS_PATH': str(directory / 'no-extensions'),
            'API_DEV_KEY': secrets.token_urlsafe(32), 'SECURITY_RUNTIME_FIXTURE': '1',
        }
        fixture = subprocess.run(['php', str(ROOT / 'scripts/security/runtime-seed.php')],
                                 cwd=ROOT, env=environment, check=True, capture_output=True, text=True)
        data = json.loads(fixture.stdout)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        environment['APP_URL'] = base
        # Do not use HTTP_PROXY inherited from the developer/runner environment.
        client = urllib.request.build_opener(urllib.request.ProxyHandler({}))

        def request(path, expected, key=None, method='GET', body=None):
            headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
            if key:
                headers['X-API-Key'] = key
            req = urllib.request.Request(base + path, headers=headers, method=method,
                                         data=json.dumps(body).encode() if body is not None else None)
            try:
                response = client.open(req, timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                content = response.read().decode()
                if response.status != expected:
                    # Do not include responses/keys in public CI logs on failure.
                    safe_path = path.split('?', 1)[0]
                    raise AssertionError(f'{method} {safe_path}: expected {expected}, got {response.status}')
                if expected in (401, 403, 404) and path.startswith('/api/'):
                    if not response.headers.get('Content-Type', '').startswith('application/problem+json'):
                        raise AssertionError('API errors must use problem+json')
                    if any(value in content for value in ('SQLSTATE', 'Stack trace', str(ROOT))):
                        raise AssertionError('Internal details leaked in error response')
                return json.loads(content) if content else None

        with (directory / 'server.log').open('w') as output:
            server = subprocess.Popen(
                ['php', '-S', f'127.0.0.1:{port}', str(ROOT / 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
                cwd=ROOT / 'public', env=environment, stdout=output, stderr=output,
            )
            try:
                for attempt in range(50):
                    if server.poll() is not None:
                        raise RuntimeError('HTTP fixture exited before readiness')
                    try:
                        request('/api/v1/ping', 401)
                        break
                    except urllib.error.URLError:
                        time.sleep(0.1)
                else:
                    raise RuntimeError('HTTP fixture did not become ready')

                admin = data['tenants']['admin']['key']
                client_a = data['tenants']['clientA']['key']
                reseller = data['tenants']['reseller']['key']
                own, foreign = data['domains']['clientA'], data['domains']['clientB']
                for key in ('invalid', data['revoked'], environment['API_DEV_KEY']):
                    request('/api/v1/ping', 401, key)
                request('/api/v1/ping?api_key=' + admin, 401)
                request('/api/v1/ping', 200, admin)
                request('/api/v1/mail/domains/' + str(own), 200, client_a)
                request('/api/v1/mail/domains/' + str(foreign), 404, client_a)
                request('/api/v1/mail/domains/' + str(foreign), 404, client_a, 'PUT', {'active': False})
                listing = request('/api/v1/mail/domains', 200, client_a)
                if [row['id'] for row in listing['data']] != [own]:
                    raise AssertionError('Tenant listing leaked another tenant')
                request('/api/v1/system/api-keys', 403, client_a)
                request('/api/v1/mail/domains/' + str(own), 200, reseller)
                request('/api/v1/mail/domains/' + str(foreign), 404, reseller)
                request('/api/v1/system/api-keys', 403, reseller)
                reseller_listing = request('/api/v1/mail/domains', 200, reseller)
                if [row['id'] for row in reseller_listing['data']] != [own]:
                    raise AssertionError('Reseller listing leaked an unrelated tenant')
                key_listing = request('/api/v1/system/api-keys', 200, admin)
                if any(tenant['key'] in json.dumps(key_listing) for tenant in data['tenants'].values()):
                    raise AssertionError('Key listing exposed a plaintext credential')
                after = request('/api/v1/mail/domains/' + str(foreign), 200, admin)
                if after['active'] is not True:
                    raise AssertionError('Rejected tenant update changed another tenant')
                request('/.env', 404)
                request('/vendor/composer/installed.json', 404)
                print('HTTP security checks passed: authentication, production dev-key rejection, tenant read/write isolation, admin-only routes, private files and error redaction.')
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)


if __name__ == '__main__':
    main()
