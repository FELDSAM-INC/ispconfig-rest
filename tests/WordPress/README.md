# WordPress detection and security milestone

Run policy/API/tenant/locking tests with `php vendor/bin/phpunit tests/Feature/WordPressApiTest.php`.
Run worker boundary, rollback, interrupted recovery, config compare-and-swap and
cleanup tests with `python3 -m unittest discover -s tests/WordPress`.

The Apache fixture must run **only in a disposable container**:

```sh
docker build -t ispcp-waf-test tests/Integration/waf
docker run --rm -v "$PWD:/app:ro" ispcp-waf-test php /app/tests/WordPress/apache.php
```

It checks the generated managed block, encoded author enumeration, sensitive files,
directory browsing and handler overrides from `.htaccess`, while ordinary assets
remain readable. Worker tests additionally cover UID isolation and pinned mounts;
live development verification is recorded in `docs/WORDPRESS-TOOLKIT.md`.
