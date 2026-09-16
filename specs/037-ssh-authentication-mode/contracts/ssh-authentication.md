# Contract: SSH Authentication Mode

OpenAPI sources: `api/components/schemas/AccountSitesCapabilities.yaml`, `api/modules/me/capabilities.yaml`,
`api/components/schemas/ShellUser.yaml`, `api/modules/sites/shell-users.yaml`.

## GET /me/capabilities — one more field in `sites.shell`

```json
{
  "sites": {
    "shell": {
      "available": true,
      "chroot_options": ["no", "jailkit"],
      "authentication": "password_or_key"
    }
  }
}
```

`authentication` is required and is one of:

| Value | Meaning |
|---|---|
| `password_or_key` | an SSH account may carry a password, a key, or both (the installation's default) |
| `password` | only a password is accepted |
| `key` | only a key is accepted |

It reports the installation's setting, so it is present even when `available` is false.

## POST /sites/shell-users, PUT /sites/shell-users/{id} — refusal

Client and reseller keys only.

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#validation-failed",
  "title": "Unprocessable Entity",
  "status": 422,
  "detail": "The given data was invalid.",
  "errors": {
    "password": ["This hosting accepts an SSH key only; a password cannot be set."]
  },
  "error_types": {
    "password": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed"
  }
}
```

The mirror case names `ssh_rsa` ("This hosting accepts a password only; an SSH key cannot be set."). Nothing is
written.

Accepted without a refusal:

- the credential the installation accepts;
- the not-allowed field sent as `null` or `""`;
- on update, the not-allowed field re-sent with exactly the stored value (as spec 016 and 033 accept unchanged
  values).

Administrator keys are never refused: the not-allowed credential is cleared server-side, which is what the ISPConfig
form intends. The `ShellUser` schema's *Authentication* section and the endpoint descriptions state that the setting
is read from the `[sites]` section — legacy's save path reads `[misc]`, where the key does not exist, so its clearing
never runs (spec 037 research R2).

## Consumer mapping (WHMCS module spec 006)

| Panel element | Source |
|---|---|
| Which credential input the SSH form shows | `sites.shell.authentication` |
| Error next to the field when a stale form posts the wrong credential | `errors.<field>` with `error_types.<field>` = `feature-not-allowed` |
