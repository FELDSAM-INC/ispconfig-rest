# 054 — Own output log for each scheduled task

Owner request (2026-10-02): "each scheduled task should have logs in UI".

## Why not ISPConfig's `log` option

With `log = y`, ISPConfig appends ` >>private/cron.log 2>>private/cron_error.log` after the command. That captures
only the command's last part (`cd x && php y.php` logs `php y.php` only), a trailing `#` comment swallows it, and the
files are shared by every task of the website, without run boundaries. Per-task logs need the task's own redirection.

## Decisions (owner-delegated)

- `output_log` on scheduled tasks. When on, the native command is
  `command exec >>'<dir>/.ispcp-cron-<token>.log' 2>&1; trap 'echo "=== exit $? ==="' EXIT; echo "=== $(date -u) ==="; <command>`
  with `<dir>` = `/private` (chrooted) or `<document_root>/private` (full) and a random 32-hex token.
  - A prefix leaves the task's own text last and untouched: quoting, `&&`, background jobs and trailing comments keep
    their meaning. Verified in dash (cron's `/bin/sh`) and bash (the jail shell).
  - `command exec` keeps a failed redirection (missing private directory) from stopping the task; it then runs unlogged.
  - No `%` (cron line break) and no backslash (ISPConfig rejects them).
  - The API never returns the prefix: `command` is the task's own command; `output_log` reports the prefix. Edits keep
    the token, so the file survives; turning it off writes the plain command back. ISPConfig's own `log` is turned off
    while `output_log` is on.
  - For chrooted tasks a leading document root is removed from the command, as ISPConfig does at the start of a line.
  - URL tasks run through ISPConfig's wget line and ignore `output_log`.
- `GET /sites/cron-jobs/{id}/log?lines=` (default 200, max 1000) returns `ready` with the latest lines, `pending` until
  the web-log worker answered, `unavailable` without a worker or file access, `disabled` without `output_log`. It uses
  the `api_web_log_reads` transport of spec 051; the API never reads the website's files, even on its own server.
- The web-log worker reads the file as the website user (`timeout 10 runuser -u webN -- tail -c 256K`), so a link or
  special file in its place reaches nothing that user could not read and a FIFO cannot block the root worker. The task
  must still belong to the website and carry the prefix for that website's private directory. Once a minute it trims
  logs above 1 MiB to the last 512 KiB, also as the website user; a run writing meanwhile may lose lines.
- New grant for the web-logs component: `cron` SELECT on the master. Old workers answer `logs_unavailable`.
- WordPress cron takeover tasks have no own log but the same endpoint reads the WordPress worker's
  `private/wp-cron.log` for them (owner report 2026-10-02: existing tasks showed no logs at all).
- Not done: removing a task does not delete its file in private/ (the customer can); the native ISPConfig panel shows
  the prefixed command.
