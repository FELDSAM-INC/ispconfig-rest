# Updater regression checks

Run with Python 3.8+, Bash and Git; root, PHP, Composer and a database are not
required:

```sh
python3 -m unittest discover -s tests/Integration/updater -p 'test_*.py' -v
```

Tests execute the CLI's actual functions against temporary Git origins and shallow
clones. They cover repeated depth-one fetch boundaries, stale saved branches,
pinned annotated tags, explicit channel
changes, missing/moved releases, local edits/commits, version reporting, and CLI
refresh from verified release blobs without downgrading to the old updater.
Deployment services and PHP/Composer are replaced with inert fixtures. No system
configuration or real installation is changed, and no network access is used.
