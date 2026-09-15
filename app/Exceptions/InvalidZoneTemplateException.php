<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A DNS zone template cannot be expanded (spec 029): unknown section header,
 * a `[ZONE]` line without `=`, a record row the parser cannot read, a record
 * type outside `dns_rr.type`, or a missing required zone key.
 *
 * Surfaces as a 422 on `template_id` — legacy ends the request with
 * `die('Unknown section type')` instead (dns_wizard.inc.php).
 */
class InvalidZoneTemplateException extends RuntimeException {}
