<?php

namespace App\Support;

/**
 * Stable problem type names and URIs (spec 023, docs/problems.md). Integrations
 * compare the `type` URI; titles and details may be reworded. Problems without
 * a name here keep `about:blank`.
 */
final class ProblemType
{
    public const BASE_URI = 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#';

    public const ACCOUNT_LOCKED = 'account-locked';

    public const LIMIT_REACHED = 'limit-reached';

    public const QUOTA_EXCEEDED = 'quota-exceeded';

    public const FEATURE_NOT_ALLOWED = 'feature-not-allowed';

    public const SERVER_NOT_ASSIGNED = 'server-not-assigned';

    public const VALIDATION_FAILED = 'validation-failed';

    public const RESOURCE_IN_USE = 'resource-in-use';

    public const DOWNLOAD_NOT_PREPARED = 'download-not-prepared';

    public const DOWNLOAD_NOT_READABLE = 'download-not-readable';

    /** Every documented name, in docs/problems.md order. */
    public const NAMES = [
        self::ACCOUNT_LOCKED,
        self::LIMIT_REACHED,
        self::QUOTA_EXCEEDED,
        self::FEATURE_NOT_ALLOWED,
        self::SERVER_NOT_ASSIGNED,
        self::VALIDATION_FAILED,
        self::RESOURCE_IN_USE,
        self::DOWNLOAD_NOT_PREPARED,
        self::DOWNLOAD_NOT_READABLE,
    ];

    public static function uri(string $name): string
    {
        return self::BASE_URI.$name;
    }
}
