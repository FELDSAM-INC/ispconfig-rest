"""Error paths of the ispconfig-rest client; tests/Integration/acme-dns/run.sh covers issuing certificates."""
import unittest
from unittest import mock

import requests
from certbot import errors

from certbot_dns_ispconfig_rest._internal.dns_ispconfig_rest import _IspconfigRestClient


def response(status, body=None):
    result = mock.Mock(spec=requests.Response)
    result.status_code = status
    result.json.side_effect = (lambda: body) if body is not None else ValueError('no JSON')
    return result


class ClientTest(unittest.TestCase):
    def setUp(self):
        self.client = _IspconfigRestClient('https://panel.example.com:8090/', 'isp_key', '/etc/ssl/ca.pem')
        self.client.POLL_SECONDS = 0
        self.request = mock.patch.object(self.client.session, 'request').start()
        self.addCleanup(mock.patch.stopall)

    def test_sends_the_key_and_trusts_the_configured_ca(self):
        self.request.return_value = response(201, {'id': 7, 'status': 'applied'})
        self.client.publish('_acme-challenge.example.com', 'token')
        self.request.assert_called_once_with('POST', 'https://panel.example.com:8090/api/v1/dns/acme-challenges', timeout=30,
                                             verify='/etc/ssl/ca.pem', json={'name': '_acme-challenge.example.com', 'value': 'token'})
        self.assertEqual('isp_key', self.client.session.headers['X-API-Key'])

    def test_a_refusal_carries_the_problem_detail(self):
        self.request.return_value = response(404, {'detail': 'No DNS zone available to this key hosts _acme-challenge.x.test.'})
        with self.assertRaisesRegex(errors.PluginError, 'HTTP 404: No DNS zone available'):
            self.client.publish('_acme-challenge.x.test', 'token')

    def test_waits_until_applied(self):
        self.request.side_effect = [response(200, {'id': 7, 'status': 'pending'}), response(200, {'id': 7, 'status': 'applied'})]
        self.client.wait({'id': 7, 'name': 'n', 'status': 'pending'}, 60)
        self.assertEqual(2, self.request.call_count)
        self.request.assert_called_with('GET', 'https://panel.example.com:8090/api/v1/dns/acme-challenges/7', timeout=30,
                                        verify='/etc/ssl/ca.pem')

    def test_a_failed_or_late_record_stops_the_order(self):
        self.request.return_value = response(200, {'id': 7, 'name': 'n', 'status': 'failed'})
        with self.assertRaisesRegex(errors.PluginError, 'status failed'):
            self.client.wait({'id': 7, 'name': 'n', 'status': 'pending'}, 60)
        with self.assertRaisesRegex(errors.PluginError, 'within 0 seconds'):
            self.client.wait({'id': 7, 'name': 'n', 'status': 'pending'}, 0)

    def test_cleanup_only_warns(self):
        self.request.return_value = response(500)
        with self.assertLogs('certbot_dns_ispconfig_rest._internal.dns_ispconfig_rest', 'WARNING'):
            self.client.remove('_acme-challenge.example.com', 'token')
        self.request.side_effect = requests.ConnectionError('refused')
        with self.assertLogs('certbot_dns_ispconfig_rest._internal.dns_ispconfig_rest', 'WARNING'):
            self.client.remove('_acme-challenge.example.com', 'token')


if __name__ == '__main__':
    unittest.main()
