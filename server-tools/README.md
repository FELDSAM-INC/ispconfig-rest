# Server tools (moved)

The server tools — database, web-log and file-manager workers, WAF, PHP-FPM limits and DNS catalog zones — are part
of the hosting extension since 1.1.0: [ispconfig-rest-hosting](https://github.com/FELDSAM-INC/ispconfig-rest-hosting).

```sh
sudo ispconfig-rest extension:install hosting
sudo ispconfig-rest server-tools install --help
```

`manage.py` and `remote.php` here are stubs: managers before 1.1.0 copy them during `ispconfig-rest update` before
replacing themselves with the current manager, which no longer uses them.
