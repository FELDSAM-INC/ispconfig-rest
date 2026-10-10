# Disposable BIND 9.18 hosts for the dns-catalog checks: an ISPConfig-like primary and a plain secondary.
FROM ubuntu:24.04
RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends bind9 bind9-dnsutils php8.3-cli python3 sudo >/dev/null && rm -rf /var/lib/apt/lists/*
