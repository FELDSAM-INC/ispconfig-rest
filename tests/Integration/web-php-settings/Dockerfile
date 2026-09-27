FROM debian:trixie-slim
RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends php8.4-fpm php8.4-cgi php8.4-cli && rm -rf /var/lib/apt/lists/*
