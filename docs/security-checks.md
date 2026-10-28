# Security checks

This setup follows `mailcow-rest-v2`, adapted to a Laravel API installed on an ISPConfig server.
Codex findings are advisory. Deterministic checks and application tests block the GitHub release job.

| Check | Coverage | When / policy |
| --- | --- | --- |
| Semgrep | Selected PHP/Laravel and Python security rules; explicit rejection of PHP object deserialization | PRs, configured branch pushes, release tags, weekly, manual; findings and scanner errors fail |
| Composer audit | All locked production and development packages | Same events; any reported advisory or abandoned package fails |
| Gitleaks | Full fetched Git history plus current tracked and non-ignored new files | Same events; findings and scanner errors fail; reports redact secret values |
| PHPStan/Larastan | Level 1 across `app`, routes, config, database and application bootstrap; level 5 on middleware, API keys, base-model authorization and scope/error support | Same events; no error baseline or blanket suppressions |
| PHPUnit | Full feature/unit suite, including tenant isolation, ACME restrictions, permissions, secret visibility and datalog behavior | PRs, configured branch pushes and tags; PHP 8.3, 8.4 and 8.5 |
| Installer/updater checks | Bash syntax and updater integration tests, including published-ref provenance and safe, checksum-verified Composer bootstrap | Same events as PHPUnit |
| HTTP smoke tests | Actual loopback HTTP requests as admin, reseller and client; invalid/revoked/dev keys, tenant reads/writes/listing, admin-only route, private files, error redaction | Same events as PHPStan; disposable SQLite, production environment |
| CodeQL | GitHub Actions, Python and JavaScript tooling | PRs, main/develop, weekly, manual; uploads code-scanning alerts |
| Codex Security | Local repository scan with ChatGPT sign-in; optional trusted PR diff scan with API credentials | Advisory; never a release dependency; no paid full scans on push or schedules |

CodeQL does **not** analyze PHP or the Bash installer. PHP is checked by Semgrep, Larastan and tests.
CodeQL alerts require a repository code-scanning ruleset to enforce severity at merge time; the analysis action alone
does not fail when it finds a vulnerability. The release job explicitly depends on the standard security workflow,
PHPUnit and updater jobs. Codex results do not override those gates.

There is no shipped container image in this repository, so there is no product image scan or container SBOM gate.
Scanner images are pinned by digest. Host OS/PHP packages, the deployed web-server configuration and ISPConfig itself
are outside these checks. The HTTP fixture is a small smoke test, not a full DAST scan or a production penetration test.
The deterministic core checks exclude the independent `extensions/hosting` checkout and ACME plugins. A local
Codex scan may inspect a nested checkout; its coverage report records which extension surfaces were reviewed.

## Run standard checks locally

Use PHP 8.3+ with the project's required extensions, Composer, Python 3 and Docker. The pinned Gitleaks download
requires Linux x86_64. Run from the repository root:

```bash
composer install --prefer-dist --no-interaction
composer audit --locked --no-plugins --no-scripts
python3 scripts/security/check.py secrets
python3 scripts/security/check.py source
APP_ENV=testing API_EXTENSIONS_PATH=/nonexistent/extensions DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpstan analyse
APP_ENV=testing API_EXTENSIONS_PATH=/nonexistent/extensions DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpstan analyse -c phpstan-security.neon
python3 scripts/security/runtime.py
python3 -m unittest discover -s tests/Integration/updater -p 'test_*.py'
bash -n install.sh bin/ispconfig-rest
vendor/bin/phpunit
```

PHPUnit needs a local application encryption key, as in the normal project test setup. The HTTP runner creates its own
key/database/config-cache locations and forces production mode, without accepting a production target URL.
It never runs ISPConfig services. Do not reuse a production `.env` for ordinary development commands.

Standard scanner results go to ignored `security-reports/`; the directory is created private on Linux.
The source scanner copies only tracked/non-ignored files, without following symlinks or including ignored local
credentials, vendor trees and reports. Dependencies have their separate lockfile audit. Gitleaks detects ISPConfig-format keys as well as its standard credential patterns. Exceptions are
reviewed historical fingerprints and exact fixture/documentation matches restricted to their paths and rules.
Each scan first injects synthetic keys into exception-bearing fixtures and verifies that they are detected and redacted.

## Local Codex Security with a subscription

Use Node.js 24+, Python 3.10+ and the Codex Security CLI's ChatGPT sign-in:

```bash
npx --yes @openai/codex-security@0.6.0 login
node scripts/security/scan-local.mjs --dry-run
node scripts/security/scan-local.mjs
```

On Linux, use a Bubblewrap build that supports `--argv0` and verify which executable is selected:

```bash
command -v bwrap
bwrap --version
bwrap --help | rg -- --argv0
```

The Oracle Linux 9 package `0.6.3` lacks that option. With the current Codex Security runtime, its fallback
tries to execute `codex-linux-sandbox` inside the deliberately denied credential directory and fails with
`Permission denied` before scanning. Bubblewrap `0.13.1` resolves this on the local host. It is installed under
`~/.local/lib/codex-security/bubblewrap-0.13.1/bin/bwrap`, linked from `~/.local/bin/bwrap`, ahead of `/usr/bin`
on `PATH`. The system package remains installed. Keep the credential-directory denial and sandbox enabled;
do not fix this error by granting access to the credential directory. Codex uses the first `bwrap` on `PATH`
as described in the [sandbox documentation](https://learn.chatgpt.com/docs/sandboxing).

Select an installed Python explicitly with `--python /path/to/python3.12`, or a model available to the account with
`--model MODEL`. The runner uses `gpt-6-sol` by default, matching the reference project. The account must have access
to the chosen model **through Codex Security**; a successful dry run checks configuration only, not entitlement.

The runner forces `--auth chatgpt --provider openai`, removes API credentials and inherited project-config overrides,
and has no API fallback. Reports are written outside the repository under a private temporary directory printed at
startup. Review findings and coverage there; do not commit reports or copy their contents to public PRs.
Only structured rate-limit failures retry, at most three attempts with a shared wait budget (`--max-wait-seconds`,
default 900). Authentication/model-access, billing and other failures stop immediately. Each retry consumes plan usage.

The subscription runner tests use fake subprocess results and make no model calls:

```bash
node --test scripts/security/scan-local.test.mjs
python3 -m unittest discover -s scripts/security -p '*_test.py'
```

For access errors, first check `npx --yes @openai/codex-security@0.6.0 login status`. If the sign-in needs refreshing,
run `npx --yes @openai/codex-security@0.6.0 login` and select the intended account/workspace, then retry the runner.
Use `node scripts/security/scan-local.mjs --verbose` to save additional diagnostics privately as `attempt-N.log`
alongside each attempt's JSON result. Verbose diagnostics are not printed to the terminal and can contain sensitive data.
The CLI's generic model-access error does not establish whether an account lacks Trusted Access or which permission failed;
inspect the underlying diagnostics before attributing a cause. Even verbose mode may only report `forbidden`.

## PR-only Codex CI

The workflow runs only for non-draft, same-repository PRs, excluding Dependabot. It never uses `pull_request_target`,
executes fork code with a scan credential, scans on push/schedule, or participates in release gating.
Without `CODEX_SECURITY_API_KEY`, it records a skip. Add that Actions secret only to enable paid PR diff scans.
Optional repository variables: `CODEX_SECURITY_MODEL` (default `gpt-6-sol`) and `CODEX_SECURITY_PR_MAX_COST` (default 5 USD).
The cost setting is an estimated stop threshold, not a hard spending cap; in-flight requests can exceed it.

For this public repository, verbose findings, annotations, summaries and downloadable Codex artifacts are disabled.
Validated findings are sent to GitHub code scanning using the scanned SHA/ref. Maintainers should review them privately
and reproduce/fix confirmed issues before disclosure. Scanner failures remain advisory and do not constitute a clean scan.

## Maintenance and repository settings

`security/tools.json` pins scanner versions, image digests, the upstream Semgrep rule revision and rule checksums.
Rules do not silently change on every CI run. Review scanner/rule updates regularly, verify their upstream checksums,
and rerun both the rule fixtures and project scans when changing pins. Composer audit uses current advisory data;
weekly scheduled scans detect new advisories even when source code has not changed.
Dependabot proposes weekly Composer and GitHub Actions updates against `develop`; it does not update custom scanner pins.

Repository administrators should enable private vulnerability reporting, secret scanning/push protection, Dependabot
alerts, and protected-branch rules requiring the standard test/security jobs. Configure code-scanning severity rules
for CodeQL if those alerts should block merges. Do not require the advisory Codex job. These are GitHub settings;
committing workflow files alone cannot enable them. This session did not change repository administration settings.

## Initial local audit — 2026-10-28

The dependency audit on base commit `88e3bb8` reported 23 advisories across Guzzle, Laravel, CommonMark, Flysystem
and Symfony HttpFoundation. The lockfile now updates these within existing major-version constraints, plus Guzzle's
required transitive dependencies. A subsequent Composer audit reported no known advisories. An affected dependency
does not by itself establish that its vulnerable code path is reachable through this API.

The source review checked API-key authentication, scope resolution/model authorization, serialization sinks, documentation
file resolution, and the installer/updater's root-executed source provenance. The remote-action model now has an explicit
fillable list, and unused Laravel session/password authentication scaffolding pointing to a nonexistent User model was
removed. The Composer bootstrap now uses a private temporary directory, verifies the official SHA-384 checksum
before execution, stops on download failure and always cleans up. Four integration tests exercise those properties
without network or host changes. No blanket scanner suppression was added. The wider level 5 PHPStan run exposed existing typing debt;
the enforced levels and paths above describe the actual coverage.

The standalone Codex Security CLI could not complete a scan: both the reference model and the CLI default failed
at preflight with a generic model-access error. A verbose run only classified the failure as `forbidden`, while
`login status` reported ChatGPT authentication. Capturing the original SDK exception subsequently exposed HTTP 401
`token_revoked` and a local Bubblewrap `Permission denied` error executing the sandbox helper. These diagnostics
do not establish missing Trusted Access or model entitlement. Device-code sign-in subsequently completed successfully;
the repeated diagnostic no longer contained the OAuth error. The local sandbox failure reproduced in CLI 0.6.0
(Codex 0.163.0), CLI 0.8.0 (Codex 0.164.0), and with Codex 0.165.0. Tracing Bubblewrap arguments identified
the old system Bubblewrap's missing `--argv0` support as the cause: its fallback helper path was inside the
runtime's denied credential directory. Installing user-local Bubblewrap 0.13.1 allowed the original CLI 0.6.0
to start the scan and enter discovery. The release archive's SHA-256 was checked against the upstream release.
An actual Codex sandbox check confirmed that credential-directory reads and writes outside the workspace
remain blocked, while workspace writes succeed. The configured CLI pin remains unchanged. No API-key fallback
or sandbox weakening was used.

The follow-up Standard scan completed successfully on 2026-10-28 using CLI/SDK 0.6.0, `gpt-6-sol`, high effort
and ChatGPT authentication. Its finalized manifest records `status: completed`, and the SDK exited successfully.
It fully reviewed 72 source paths from the 1,202-file inventory using offline static source tracing and local
dependency inspection. This was not exhaustive coverage or a runtime exploit test; admin modules and other
resource operations still need follow-up. Findings, evidence and coverage remain in the private report directory
outside the repository. The in-session review and deterministic checks above were separate from this scan.

Local validation: 1,428 PHP tests (11,305 assertions), 23 installer/updater integration tests, both PHPStan gates,
Semgrep and its rule fixtures, Gitleaks history/worktree scans and negative controls, HTTP smoke checks,
15 local subscription-runner tests, 3 scanner-runner tests, and workflow syntax validation.


## Remediation validation — 2026-10-28

The follow-up fixes have regression coverage for resource ownership, DNS write permissions, restricted-key
change visibility, scoped mail cleanup and archive streaming during filesystem entry replacement. Installer
checks also verify atomic runtime environment replacement, migrations from private sources, and extension
revision provenance. Privileged installer code and dependencies are fetched separately under `/root`; the
runtime checkout is accessed as its runtime user. Only extensions recorded in the root-owned manager registry
are included in privileged migrations. A new install directory needs an existing root-owned parent directory
that other users cannot write.

A completed repeat scan reviewed 109 paths and reported six additional findings in client authority,
private template references, TLS/DKIM permissions and the hosting database worker. Those paths now have
regression tests. A later review identified further ownership-transfer, template-serialization, legacy
conversion, mail-filter and installer/updater issues. Its report could not be finalized because the scanner
returned an incomplete-draft error; it is not evidence of a passing scan. Its independently reproduced or
source-confirmed issues were fixed, including a shared model-level group ownership check. Reports remain
private outside the repository.

Current local validation on PHP 8.4 passed 1,459 core tests (11,491 assertions), 241 hosting tests (1,768
assertions), 30 installer/updater tests, both PHPStan gates, HTTP checks, Semgrep, Gitleaks and their negative
controls, 15 subscription-runner tests, 3 scanner-runner tests, and workflow syntax validation. Hosting's
server-tools and WordPress suites passed 54 tests. A fresh Composer audit found no advisories after updating
Symfony Mime to 7.4.22 for CVE-2026-45070. The final release remains conditional on a completed Codex review
of the frozen release candidate and the GitHub Actions gates. No production installation was run.
