# Updater regression checks

Run with Python 3.8+, Bash and Git; root, PHP, Composer and a database are not
required:

```sh
python3 -m unittest discover -s tests/Integration/updater -p 'test_*.py' -v
```

Tests execute the CLI's actual functions against temporary Git origins and shallow
clones. They cover repeated depth-one fetch boundaries, root-registered channels,
pinned annotated tags, explicit channel
changes, missing/moved releases, local edits/commits, version reporting, and CLI
refresh from exact trusted release commits, including refusal of a stale runtime origin.
Deployment services and PHP/Composer are replaced with inert fixtures. No system
configuration or real installation is changed, and no network access is used.

The optional web-stack tests run real Apache, nginx and PHP-FPM processes inside a disposable Ubuntu
container (`ISPC_REST_TEST_WEB_STACK=1`). The release workflow runs them separately: HTTPS must serve the
API entry point, reject other PHP paths and dotfiles, and prevent the shared web identity from connecting
to the private FPM socket. Never enable these tests on a production host.
