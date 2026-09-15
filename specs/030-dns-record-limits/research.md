# Research: DNS Record Limit Parity

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only), 2026-09-16.

## R1 — Where legacy caps DNS records

**Legacy**: `grep -rn limit_dns_record interface/` lists the call sites:

- `dns/dns_edit_base.php` 105–118 (`onSubmit`, shared by A, AAAA, ALIAS, CNAME, DNAME, DS, HINFO, LOC, MX, NAPTR, NS,
  PTR, RP, SRV, SSHFP, TXT):

  ```php
  if($_SESSION["s"]["user"]["typ"] != 'admin') {
      $client_group_id = intval($_SESSION["s"]["user"]["default_group"]);
      $client = $app->db->queryOneRecord("SELECT limit_dns_record FROM sys_group, client WHERE sys_group.client_id = client.client_id and sys_group.groupid = ?", $client_group_id);
      if($this->id == 0 && $client["limit_dns_record"] >= 0) {
          $tmp = $app->db->queryOneRecord("SELECT count(id) as number FROM dns_rr WHERE sys_groupid = ?", $client_group_id);
          if($tmp["number"] >= $client["limit_dns_record"]) {
              $app->error($app->tform->wordbook["limit_dns_record_txt"]);
  ```

- 78–91 `onShowNew`: the same count for `typ == 'user'` before the form opens.
- Copies of both blocks in `dns_caa_edit.php` 58–64 / 119–127, `dns_dkim_edit.php` 61–67 / 104–111,
  `dns_dmarc_edit.php` 58–64 / 216–224, `dns_spf_edit.php` 60–66 / 148–156, `dns_tlsa_edit.php` 61–67 / 88–96.
- `dashboard/dashlets/limits.php` 107: the dashboard shows `dns_rr` counted by the client group.
- `dns/lib/lang/en_dns_a.lng` 9: `The max. number of DNS records for your account is reached.`

Spec 012 looked only for `checkClientLimit('limit_dns_record')` and concluded the limit was not enforced.

**Decision**: enforce on create for every non-admin key; updates and deletes are not limited (`$this->id == 0`).

## R2 — Count predicate and reseller behaviour

**Legacy**: the count is `sys_groupid = default_group` of the acting user and the limit is the acting user's own
client row. A reseller user is not `admin`, so the reseller's own `limit_dns_record` applies to the records carrying
the reseller's group. A record gets the zone's `sys_groupid` (`dns_edit_base.php` onAfterInsert), so a reseller adding
a record to a client's zone adds to the client's count while being checked against its own. There is no
`checkResellerLimit('limit_dns_record')`.

**API**: `LimitSpec` predicate `grp` counts `sys_groupid = AuthScope::sysGroupId` (the key user's default group) and
`resellerCap = false` skips the reseller cap — exactly the shape already used for `limit_client`
(`client_edit.php` 68) and `limit_database_postgresql`. `checkCreate()` reads the limit from the acting client row.

**Decision**: `new LimitSpec('limit_dns_record', 'dns_rr', 'id', null, 'grp', false, 'DNS records')`. Detail text
`You have reached the maximum number of DNS records allowed for your account.` (API wording, spec 012/023).

## R3 — Paths without the cap

**Legacy**: `lib/classes/dns_wizard.inc.php` 152–158 checks only `limit_dns_zone`; `dns_import.php` and the wizard
insert records with `datalogInsert` and do not reference `limit_dns_record` (R1 grep).

**API**: every `dns_rr` create goes through `DnsRecordController::store()` → `BaseModel::save()`. No wizard, import or
DKIM record writer exists (checked with `grep -rn dns_rr app`). `ResyncService` only updates existing rows.

**Decision**: no exception needed now. A future zone wizard (spec 029) must decide per its legacy counterpart (the
wizard does not check the record cap) — recorded as an open point.

## R4 — Usage summary and capabilities

**API**: `UsageService::summary()` iterates `ClientLimitService::USAGE_COUNT_COLUMNS` and counts with
`countUsage()`, which resolves the spec through `countSpecForColumn()` and applies the same predicate. The summary
scope for an admin or reseller naming a client is the client's own scope, so `grp` uses the client's group.

**Decision**: `dns_records => limit_dns_record` in `USAGE_COUNT_COLUMNS` and in `countSpecForColumn()`;
`UsageSummary.yaml` lists it as required. `/me/capabilities` (spec 021/025) describes feature switches, not counts, and
is not extended (owner-delegated decision 2026-09-16).
