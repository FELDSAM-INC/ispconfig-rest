FROM debian:trixie-slim
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends apache2 libapache2-mod-security2 libnginx-mod-http-modsecurity nginx modsecurity-crs php8.4-cli php8.4-mysql php8.4-mbstring curl ca-certificates procps && rm -rf /var/lib/apt/lists/*
