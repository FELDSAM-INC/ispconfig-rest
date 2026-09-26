FROM debian:trixie-slim
RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends nginx apache2 libapache2-mod-fcgid php8.4-fpm php8.4-cli curl ca-certificates && rm -rf /var/lib/apt/lists/*
