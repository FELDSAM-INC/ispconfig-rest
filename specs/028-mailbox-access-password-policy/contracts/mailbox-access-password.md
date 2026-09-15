# Contract: mailbox access switches and password policy

Source of truth after implementation: `api/components/schemas/MailUser.yaml`,
`api/components/schemas/MailUserPassword.yaml`, `api/modules/mail/users.yaml`, `api/modules/mail/user-password.yaml`.

## Mailbox fields

`GET /api/v1/mail/users`, `GET /api/v1/mail/users/{id}` and the `POST`/`PUT` responses gain:

```json
{
  "disableimap": false,
  "disablepop3": true,
  "disablesmtp": false,
  "disabledeliver": false
}
```

`POST /api/v1/mail/users` and `PUT /api/v1/mail/users/{id}` accept them (booleans or `"y"`/`"n"`). IMAP off also turns
off Sieve (`disablesieve`, `disablesieve-filter`); delivery off also sets `disablelda`, `disablelmtp`.

Client and reseller keys of a locked account cannot switch `disablesmtp` from `true` to `false`:

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#account-locked",
  "title": "Forbidden",
  "status": 403,
  "detail": "The account is locked; its services cannot be enabled or added."
}
```

## Password policy

`password` on `POST /api/v1/mail/users`, `PUT /api/v1/mail/users/{id}` (non-empty) and
`PUT /api/v1/mail/users/{id}/password` must satisfy the installation policy (`GET /api/v1/me/capabilities`
`mail.password_policy`), for every key type:

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "errors": {
    "password": [
      "The chosen password does not match the security guidelines. It has to be at least 8 chars in length and have a strength of \"Good\"."
    ]
  }
}
```

No fixed minimum length remains in the schemas; `maxLength` stays 255.
