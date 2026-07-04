<?php

namespace App\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Canonical implementation of ISPConfig's SOA serial arithmetic
 * (source_code/interface/lib/classes/validate_dns.inc.php::increase_serial)
 * and of the zone-serial propagation performed by the legacy record pipeline
 * (dns_edit_base.php::onAfterInsert/onAfterUpdate, dns_rr_del.php::
 * onAfterDelete).
 *
 * Serials use the YYYYMMDDnn convention: eight date digits plus a two-digit
 * per-day counter. The first serial of a day is YYYYMMDD01 (legacy parity —
 * NOT ...00); when the counter passes 99 the date part is incremented
 * numerically and the counter restarts at 00, exactly like legacy.
 *
 * NOTE: MailDomainService carries a private copy of this algorithm
 * (increaseSerial) so the mail module does not depend on the DNS module.
 * Behavioral changes must be applied to both.
 */
class DnsSerialService
{
    public function __construct(protected DatalogService $datalog) {}

    /**
     * Exact port of legacy validate_dns::increase_serial().
     *
     * @param  int|string|null  $serial  the current serial (null/0 for new records)
     * @return string the next serial in YYYYMMDDnn form
     */
    public function increaseSerial(int|string|null $serial): string
    {
        $serial = (string) $serial;
        $serialDate = (int) substr($serial, 0, 8);
        $count = (int) substr($serial, 8, 2);
        $currentDate = (int) Date::now()->format('Ymd');

        if ($serialDate >= $currentDate) {
            $count += 1;

            if ($count > 99) {
                $serialDate += 1;
                $count = 0;
            }

            return $serialDate.str_pad((string) $count, 2, '0', STR_PAD_LEFT);
        }

        return $currentDate.'01';
    }

    /**
     * The `stamp` value legacy writes on every RR submit
     * (dns_edit_base.php: date('Y-m-d H:i:s')).
     */
    public function timestamp(): string
    {
        return Date::now()->format('Y-m-d H:i:s');
    }

    /**
     * Bump a zone's SOA serial through the datalog, mirroring the legacy
     * record pipeline's post-write hook: SELECT the zone's current serial,
     * datalogUpdate dns_soa with the increased value. A missing zone is a
     * no-op (legacy datalogUpdate on a nonexistent id writes nothing).
     */
    public function bumpZoneSerial(int $zoneId): void
    {
        $zone = DB::table('dns_soa')->where('id', $zoneId)->first(['id', 'serial']);

        if ($zone === null) {
            return;
        }

        $this->datalog->updateRecord('dns_soa', 'id', $zone->id, [
            'serial' => $this->increaseSerial($zone->serial),
        ]);
    }
}
