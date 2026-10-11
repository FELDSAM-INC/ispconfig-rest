# ACME clients for tests/Integration/acme-dns/run.sh: certbot (pip) and acme.sh; the ispconfig-rest plugins are
# installed from the mounted repository at run time.
FROM python:3.12-slim
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends curl git openssl ca-certificates bind9-dnsutils >/dev/null \
 && rm -rf /var/lib/apt/lists/* \
 && pip install --quiet --no-cache-dir 'certbot>=2.0' \
 && git clone --quiet --depth 1 --branch 3.1.6 https://github.com/acmesh-official/acme.sh /opt/acme.sh-src \
 && cd /opt/acme.sh-src && ./acme.sh --install --nocron --home /root/.acme.sh --accountemail test@example.test >/dev/null
