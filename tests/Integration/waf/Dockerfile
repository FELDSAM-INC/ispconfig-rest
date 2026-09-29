# Default: Debian 13. Ubuntu 24.04: --build-arg BASE=ubuntu:24.04 --build-arg PHP=php8.3
ARG BASE=debian:trixie-slim
FROM ${BASE}
ARG PHP=php8.4
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends apache2 libapache2-mod-security2 libnginx-mod-http-modsecurity nginx ${PHP}-cli ${PHP}-mysql ${PHP}-mbstring curl gpgv ca-certificates procps && rm -rf /var/lib/apt/lists/*
