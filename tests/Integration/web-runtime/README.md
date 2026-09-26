# Apache/nginx runtime integration

This starts isolated Apache and nginx with PHP-FPM. Requests verify literal
application values (including configuration/template injection strings), the
public child directory, exclusion of files in the old web root, and real FPM
chroots. The nginx directives run through ISPConfig's actual location merger.

From the repository root, with an ISPConfig source checkout at `/tmp/ispconfig3_install`:

```sh
mkdir -p /tmp/ispcp-runtime-verify
cp tests/Integration/web-runtime/generate.php tests/Integration/web-runtime/check.sh /tmp/ispcp-runtime-verify/
BUILDX_CONFIG=/tmp/ispcp-runtime-buildx docker build -t ispcp-web-runtime-test tests/Integration/web-runtime

docker run --rm --network none -u "$(id -u):$(id -g)" \
  -v "$PWD:/app:ro" -v /tmp/ispcp-runtime-verify:/out \
  -v /tmp/ispconfig3_install:/isp:ro -w /app php:8.3-cli php /out/generate.php

docker run --rm --network none -v "$PWD:/app:ro" \
  -v /tmp/ispcp-runtime-verify:/out ispcp-web-runtime-test sh /out/check.sh
```

All four requests (Apache/nginx, normal/chroot) must pass. No host service is
modified, and the containers expose no ports. The build needs package downloads;
test execution has no network other than the container's own loopback.
