#!/usr/bin/env python3
"""Stand-in for ispconfig-rest's ACME challenge endpoints (spec 057) for the client tests in run.sh.

A record starts `pending` and becomes `applied` on its second status read; only then is it published in
pebble-challtestsrv's DNS, as ISPConfig's DNS server would. A client that does not wait for `applied` fails validation.
GET /state lists the remaining records and the requests seen.
"""
import json
import os
import re
import threading
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

KEY = os.environ['STUB_KEY']
ZONES = os.environ.get('STUB_ZONES', 'example.test').split(',')
CHALLTESTSRV = os.environ['CHALLTESTSRV']
PATH = '/api/v1/dns/acme-challenges'

lock = threading.Lock()
records = {}
seen = []
next_id = [1]


def challtestsrv(path, payload):
    request = urllib.request.Request(CHALLTESTSRV + path, json.dumps(payload).encode(), {'Content-Type': 'application/json'})
    urllib.request.urlopen(request, timeout=10).read()


def publish_dns(name):
    """The TXT values of `name` in DNS: the applied records."""
    challtestsrv('/clear-txt', {'host': name + '.'})
    for record in records.values():
        if record['name'] == name and record['status'] == 'applied':
            challtestsrv('/set-txt', {'host': name + '.', 'value': record['value']})


def present(record_id):
    record = records[record_id]
    return {'id': record_id, 'zone_id': 1, 'zone': record['zone'], 'name': record['name'], 'value': record['value'],
            'ttl': 60, 'status': record['status']}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def reply(self, status, body=None):
        data = b'' if body is None else json.dumps(body).encode()
        self.send_response(status)
        if body is not None:
            self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def problem(self, status, detail):
        self.reply(status, {'type': 'about:blank', 'title': 'Error', 'status': status, 'detail': detail})

    def handle_any(self, method):
        url = urlparse(self.path)
        if url.path == '/state':
            with lock:
                return self.reply(200, {'records': [present(i) for i in records], 'seen': seen})
        if self.headers.get('X-API-Key') != KEY:
            return self.problem(401, 'The provided API key is invalid or has been revoked.')
        with lock:
            seen.append(method + ' ' + url.path)
            match = re.fullmatch(PATH + r'/(\d+)', url.path)
            if method == 'POST' and url.path == PATH:
                body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))) or b'{}')
                return self.create(str(body.get('name', '')).lower().rstrip('.'), str(body.get('value', '')))
            if method == 'DELETE' and url.path == PATH:
                query = parse_qs(url.query)
                name, value = query.get('name', [''])[0].lower().rstrip('.'), query.get('value', [''])[0]
                for record_id in [i for i, r in records.items() if r['name'] == name and r['value'] == value]:
                    del records[record_id]
                publish_dns(name)
                return self.reply(204)
            if match and int(match.group(1)) in records:
                record_id = int(match.group(1))
                if method == 'GET':
                    record = records[record_id]
                    record['reads'] += 1
                    if record['reads'] >= 2 and record['status'] == 'pending':
                        record['status'] = 'applied'
                        publish_dns(record['name'])
                    return self.reply(200, present(record_id))
                if method == 'DELETE':
                    name = records.pop(record_id)['name']
                    publish_dns(name)
                    return self.reply(204)
            return self.problem(404, 'Not found.')

    def create(self, name, value):
        if not name.startswith('_acme-challenge.') or not re.fullmatch(r'[A-Za-z0-9_-]+', value):
            return self.problem(422, 'The given data was invalid.')
        zone = next((z for z in sorted(ZONES, key=len, reverse=True) if name == z or name.endswith('.' + z)), None)
        if zone is None:
            return self.problem(404, 'No DNS zone available to this key hosts ' + name + '.')
        for record_id, record in records.items():
            if record['name'] == name and record['value'] == value:
                return self.reply(200, present(record_id))
        record_id = next_id[0]
        next_id[0] += 1
        records[record_id] = {'name': name, 'value': value, 'zone': zone, 'status': 'pending', 'reads': 0}
        return self.reply(201, present(record_id))

    def safely(self, method):
        try:
            self.handle_any(method)
        except Exception as error:  # an answer instead of a dropped connection
            self.problem(500, repr(error))

    def do_GET(self):
        self.safely('GET')

    def do_POST(self):
        self.safely('POST')

    def do_DELETE(self):
        self.safely('DELETE')


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()
