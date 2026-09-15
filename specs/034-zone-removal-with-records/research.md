# Research: Zone Removal With Records

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only), 2026-09-16.

## R1 — The legacy cascade

`dns/dns_soa_del.php` 41–51:

```php
function onBeforeDelete() {
    if($app->tform->checkPerm($this->id, 'd') == false) $app->error($app->lng('error_no_delete_permission'));
    $app->db->datalogUpdate('dns_soa', array("active" => 'N'), 'id', $this->id);
    $records = $app->db->queryAllRecords("SELECT id FROM dns_rr WHERE zone = ?", $this->id);
    foreach($records as $rec) {
        $app->db->datalogDelete('dns_rr', 'id', $rec['id']);
    }
}
```

`tform_actions::onDelete()` then deletes the `dns_soa` row itself with `datalogDelete` (after the same `checkPerm`).
The record query is not scoped by `getAuthSQL` — every `dns_rr` row of the zone goes, whatever its `sys_groupid`.

**Decision**: same order in the API — deactivate, delete records, delete the zone, inside one transaction so a
failure leaves nothing behind.

## R2 — Journal shape

Legacy writes: one `dns_soa` update (`active` → `N`), one `dns_rr` delete per record, one `dns_soa` delete. The
per-record serial bump of `dns_rr_del.php` 53–60 does not run (the cascade calls `datalogDelete` directly, not the
delete page), and the zone is going away anyway.

**API**: `BaseModel::save()`/`delete()` produce the same entries; `App\Http\Middleware\…` (spec 015) stamps one
`X-Change-Set-Id` on every entry of the request. The zone deactivation matters for the server side: the bind plugin
removes the zone file when the zone goes inactive, before the rows disappear.

**Decision**: keep the deactivation even though the zone row is deleted immediately after — it is what the panel
writes and what the DNS server reacts to.

## R3 — Scoping, limits and locks

The route already resolves the zone through the read scope (spec 011/024): a zone the key cannot see is a 404. The
delete permission check (`sys_perm_*` triplet with `d`) happens in `BaseModel::delete()`. Records carry the zone's
group, so no separate check is required — and legacy deletes them unscoped anyway.

Client limits are not touched by deletions, and the spec 019 lock guard only governs enabling/adding services.

**Decision**: no scoping changes; the tests cover client, reseller, foreign-client and admin keys to prove it.
