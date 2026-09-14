# Contract Changes: Client Lock and Cancel

No new paths, parameters, schemas or status codes. Description-only changes (authored before PHP, constitution
Principle I):

## `api/components/schemas/Client.yaml`

```yaml
locked:
  type: boolean
  description: |
    Account locked (suspended). Changing it to true disables all of the client's services
    (websites, mail domains, mailboxes receive and send, forwards, fetchmail, databases,
    FTP/shell/WebDAV users, protected folders, cron jobs) through the datalog and remembers
    their previous state; changing it back to false restores that state. Setting it on create
    only stores the flag. While locked, client and reseller keys cannot re-enable or add
    services of this client (403). Mirrors ISPConfig's client lock.
  default: false
  example: false

canceled:
  type: boolean
  description: |
    Control-panel login disabled. true makes the client's ISPConfig interface login inactive
    (also when set on create); false re-enables it. Services and API keys are not affected.
  default: false
  example: false
```

## `api/modules/client/clients.yaml`

- `POST /clients` description: append "`canceled: true` creates the control-panel login inactive; `locked: true`
  is stored without side effects."
- `PUT /clients/{id}` description: "Updates an existing client configuration. Changing `locked` locks or
  unlocks all of the client's services (ISPConfig client lock); changing `canceled` disables or enables the
  control-panel login. Unchanged values have no side effects."

## `api/modules/client/resellers.yaml`

- `POST /resellers` and `PUT /resellers/{id}` descriptions: same wording, noting that a reseller lock affects only
  the reseller's own services, not its clients'.

## Lock-list write operations (sites, mail)

`403` is already declared on every create/update operation of `api/modules/sites/{web-domains, databases,
ftp-users, shell-users, webdav-users, web-folders, web-folder-users, cron-jobs}.yaml` and
`api/modules/mail/{domains, users, forwards, fetchmail}.yaml`; the shared forbidden response covers the new
refusal reason, so no contract change is needed there. README documents the rule.
