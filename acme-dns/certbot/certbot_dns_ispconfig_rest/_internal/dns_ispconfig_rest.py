"""DNS authenticator for zones hosted in ISPConfig, through ispconfig-rest (spec 057)."""
import logging
import time
from typing import Any, Callable, Dict, Optional

import requests
from certbot import errors
from certbot.plugins import dns_common
from certbot.plugins.dns_common import CredentialsConfiguration

logger = logging.getLogger(__name__)

PATH = '/api/v1/dns/acme-challenges'


class Authenticator(dns_common.DNSAuthenticator):
    """Publishes DNS-01 challenges in ISPConfig through ispconfig-rest and waits until they are live."""

    description = ('Obtain certificates using a DNS TXT record, for zones hosted in ISPConfig and reachable '
                   'through ispconfig-rest.')

    def __init__(self, *args: Any, **kwargs: Any) -> None:
        super().__init__(*args, **kwargs)
        self.credentials: Optional[CredentialsConfiguration] = None

    @classmethod
    def add_parser_arguments(cls, add: Callable[..., None], default_propagation_seconds: int = 30) -> None:
        super().add_parser_arguments(add, default_propagation_seconds)
        add('credentials', help='ispconfig-rest credentials INI file.')
        add('timeout', type=int, default=300, help='Seconds to wait until ISPConfig has applied a record.')

    def more_info(self) -> str:
        return ('This plugin publishes the challenge through the ispconfig-rest API of an ISPConfig installation and '
                'waits until its DNS server has applied it.')

    def _setup_credentials(self) -> None:
        self.credentials = self._configure_credentials(
            'credentials',
            'ispconfig-rest credentials INI file',
            {
                'url': 'address of ispconfig-rest, e.g. https://panel.example.com:8090',
                'key': 'API key, e.g. from "ispconfig-rest key:create vps1 --acme"',
            },
        )

    def _perform(self, domain: str, validation_name: str, validation: str) -> None:
        client = self._client()
        record = client.publish(validation_name, validation)
        client.wait(record, int(self.conf('timeout')))

    def _cleanup(self, domain: str, validation_name: str, validation: str) -> None:
        self._client().remove(validation_name, validation)

    def _client(self) -> '_IspconfigRestClient':
        if not self.credentials:  # pragma: no cover
            raise errors.Error('Plugin has not been prepared.')
        return _IspconfigRestClient(
            self.credentials.conf('url'), self.credentials.conf('key'), self.credentials.conf('ca_bundle') or None)


class _IspconfigRestClient:
    """The three calls of the ACME challenge endpoints."""

    POLL_SECONDS = 5

    def __init__(self, url: str, key: str, ca_bundle: Optional[str] = None) -> None:
        self.url = url.rstrip('/')
        self.session = requests.Session()
        self.session.headers.update({'X-API-Key': key, 'Accept': 'application/json'})
        # Passed with every request: requests lets REQUESTS_CA_BUNDLE override a session's verify setting.
        self.verify = ca_bundle if ca_bundle else True

    def publish(self, name: str, value: str) -> Dict[str, Any]:
        """Publish the TXT record; the same name and value again returns the existing one."""
        response = self._request('POST', PATH, json={'name': name, 'value': value})
        if response.status_code not in (200, 201):
            raise errors.PluginError(f'ispconfig-rest refused {name}: {self._detail(response)}')
        record = response.json()
        logger.debug('Published %s as record %s (%s)', name, record.get('id'), record.get('status'))
        return record

    def wait(self, record: Dict[str, Any], timeout: int) -> None:
        """Return once ISPConfig's DNS server has applied the record."""
        deadline = time.monotonic() + timeout
        while True:
            status = record.get('status')
            if status == 'applied':
                return
            if status in ('failed', 'stalled'):
                raise errors.PluginError(f'ISPConfig did not apply {record.get("name")} (status {status}).')
            if time.monotonic() >= deadline:
                raise errors.PluginError(f'ISPConfig did not apply {record.get("name")} within {timeout} seconds.')
            time.sleep(self.POLL_SECONDS)
            response = self._request('GET', f'{PATH}/{record["id"]}')
            if response.status_code != 200:
                raise errors.PluginError(f'Could not read the status of {record.get("name")}: {self._detail(response)}')
            record = response.json()

    def remove(self, name: str, value: str) -> None:
        """Remove the TXT record; a record that is gone already is fine."""
        try:
            response = self._request('DELETE', PATH, params={'name': name, 'value': value})
        except errors.PluginError as error:
            logger.warning('Could not remove %s: %s', name, error)
            return
        if response.status_code != 204:
            logger.warning('Could not remove %s: %s', name, self._detail(response))

    def _request(self, method: str, path: str, **kwargs: Any) -> requests.Response:
        try:
            return self.session.request(method, self.url + path, timeout=30, verify=self.verify, **kwargs)
        except requests.RequestException as error:
            raise errors.PluginError(f'Could not reach ispconfig-rest at {self.url}: {error}') from error

    @staticmethod
    def _detail(response: requests.Response) -> str:
        try:
            body = response.json()
        except ValueError:
            return f'HTTP {response.status_code}'
        detail = body.get('detail') if isinstance(body, dict) else None
        return f'HTTP {response.status_code}: {detail}' if detail else f'HTTP {response.status_code}'
