# Quickstart: Usage Collector Freshness

How to exercise the `freshness` block locally and on isp-test.

## 1. Local (tests)

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli \
  php artisan test --filter=UsageFreshnessApiTest
```

The class runs on the frozen clock of `UsageApiTestCase`, so the timestamp arithmetic is exact: fresh data,
mail's different cadence, a stale blob, a never-collected metric, a corrupt blob, two servers (oldest wins),
a client with no resources, and an assertion that the summary's query count is unchanged.

## 2. Shape

```json
"freshness": {
  "web_disk":      { "interval_seconds": 300, "stale_after_seconds": 1800, "measured_at": "2026-09-16T04:10:00+02:00", "next_expected_at": "2026-09-16T04:15:00+02:00" },
  "mail_storage":  { "interval_seconds": 900, "stale_after_seconds": 3600, "measured_at": "2026-09-16T04:00:00+02:00", "next_expected_at": "2026-09-16T04:15:00+02:00" },
  "database_size": { "interval_seconds": 300, "stale_after_seconds": 1800, "measured_at": null,                        "next_expected_at": null }
}
```

Read it as: disk was measured 4 minutes ago and updates about every 5; mail storage is 10 minutes old against
a 15-minute cadence; databases have never been measured for this account, so nothing may be shown as a
number — the consumer prints "not measured yet", never `0`.

## 3. Live check on isp-test

This feature is read-only, so the check needs no write and no waiting for a collector.

1. **Capture the summary before deploying** for an existing client with real data (admin key,
   `client_id=19`) and keep the JSON.
2. **Deploy**, then read the same summary again.
3. **Compare**: every field of the old response must be present and identical in the new one; the only
   difference is the added `freshness` object. This is the strongest available check of FR-009/SC-004 against
   real data.
4. **Check the values against the collectors**:
   - `freshness.web_disk.interval_seconds` = 300, `stale_after_seconds` = 1800;
   - `freshness.mail_storage.interval_seconds` = 900, `stale_after_seconds` = 3600;
   - `next_expected_at − measured_at` equals `interval_seconds` exactly, for every entry that has a
     timestamp;
   - cross-check one `measured_at` against the database:
     `SELECT MAX(created) FROM monitor_data WHERE type='harddisk_quota' AND server_id=1` — the API's value
     must correspond to a row that exists (the oldest contributing server, so on a single-server
     installation the two match).
5. **The never-measured case**: create a **temporary** client with no websites, mailboxes or databases, mint
   a client-scoped key, and read its summary — every freshness entry must report `measured_at: null` and
   `next_expected_at: null` with both interval fields still present.
6. **Nothing was written**: confirm `sys_datalog` and `sys_remoteaction` are unchanged across the whole check.

## 4. Cleanup (mandatory)

1. Delete the temporary client through the API and wait until `server.updated` reaches the last
   `sys_datalog` id with no pending remote action.
2. Delete **only** the `qa043*` keys minted here; keys #1, #2, #20, #27, #50 and anything another session owns
   stay.
3. Verify the baseline: no `qa043` client, `sys_user` or client directory, and the key list back to its
   pre-run state.
