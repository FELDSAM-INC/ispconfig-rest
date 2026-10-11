"""
The `dns-ispconfig-rest` authenticator answers DNS-01 challenges for zones hosted in ISPConfig through ispconfig-rest
(spec 057): it publishes the TXT record, waits until ISPConfig's DNS server has applied it, and removes it afterwards.

Named arguments
---------------
``--dns-ispconfig-rest-credentials``         credentials INI file (required)
``--dns-ispconfig-rest-propagation-seconds`` seconds to wait after ISPConfig applied the records, for secondary DNS
                                             servers (default 30)
``--dns-ispconfig-rest-timeout``             seconds to wait until ISPConfig applied a record (default 300)

Credentials
-----------
.. code-block:: ini

   dns_ispconfig_rest_url = https://panel.example.com:8090
   dns_ispconfig_rest_key = isp_0123456789abcdefghijABCDEFGHIJ0123456789
   # optional: CA file to trust for ispconfig-rest
   dns_ispconfig_rest_ca_bundle = /etc/ssl/certs/private-ca.pem

Create a key limited to challenges on the ISPConfig panel: ``ispconfig-rest key:create vps1 --acme [--zone example.com]``.
"""
