# 058 — Extensions

Owner request (2026-10-11): ispconfig-rest is the ISPConfig REST API; the features of the FeldHost WHMCS deployment
(database operations, website logs and runtime, file manager and WordPress tools, WAF, PHP-FPM limits and product PHP
policies, dns-catalog, server tools) move to the repository ispconfig-rest-hosting. Breaking changes are accepted (one
outside user, on 1.0.0); the release is 1.1.0 (owner). The HTTP API and the WHMCS module stay unchanged when the
extension is installed.

## Coupling found

Core resources carried extension data and behaviour: website responses (`logs_available`, `waf_available`, `waf_mode`,
`wordpress`, `runtime_capabilities`, `public_document_root`; show: `runtime_settings`, `php_settings`), database
`operations`, scheduled task `output_log` and `wordpress_managed` plus the unwrapped `command`; website updates accept
`php_settings` and `runtime_settings` (preflight, apply), cron writes `output_log` (command wrapped), clients
`web_php_policy` and `web_resource_limits` (normalised, stored after save, shown, forgotten on delete), client templates
pass client attributes through the PHP policy; WordPress jobs guard website, database and database user writes and
managed cron tasks; website and client deletes clean extension rows; the WAF refreshes a website's identity on writes.

## Decisions

- An extension is a directory `extensions/<name>/` (git checkout, untracked in core) with `extension.json`: name,
  version, `requires.ispconfig-rest` (space-separated comparisons, e.g. `>=1.1.0 <1.2.0`), PSR-4 autoload and service
  providers. Core registers the autoload and the providers at boot; an incompatible or broken extension is skipped and
  reported (`GET /`, `ispconfig-rest status`), never half loaded.
- Providers use Laravel's own mechanisms for routes (inside the `api.key`/`change.set` group), migrations (same file
  names, so existing installations do not run them again), config and commands.
- `App\Extensions\Hooks` offers typed points keyed by model class, not table (`WebChildDomain` shares `web_domain`
  but is a separate resource; callbacks of a class also apply to its subclasses, so a `ClientReseller` is a `Client`):
  JSON attributes and attribute filters, show-only detail, payload fields (rules, boolean fields, authorization,
  normalisation; their top-level keys are split off before the core write), preflight, saving and saved callbacks,
  guards (update/delete, run before and inside the transaction as before), deleted callbacks (also for records removed
  through the datalog, such as child websites), and named filters for rare points (`client.attributes`: client columns
  enforced after templates and writes). Without extensions core behaves as an ISPConfig API: the fields are absent,
  `public_document_root` is `document_root/web`.
- Order kept from before the split: on a website update the extensions' saving callbacks run after the forced plan
  values and before the configuration checks (which then also see what the extensions set); on a website create they
  run before the raw insert and the saved callbacks right after it, with the new id. Client saved callbacks run after
  the client and its control-panel user exist and before the templates.
- OpenAPI: the served root document merges each extension's `api/openapi.yaml` (paths, tags, schema registrations);
  module and component files fall back to the extensions' `api/` directories (same relative layout, so `$ref`s keep
  working); `api/patches/<core file>` adds properties to core schemas when served. symfony/yaml becomes a runtime
  dependency.
- CLI: `ispconfig-rest extension:install NAME [--branch B | --tag T]`, `extension:update`, `extension:list`,
  `extension:remove`; `update` updates installed extensions; commands core does not know are offered to the
  extensions (`extensions/*/bin/ispconfig-rest-extension`), so `ispconfig-rest server-tools ...` keeps working.
