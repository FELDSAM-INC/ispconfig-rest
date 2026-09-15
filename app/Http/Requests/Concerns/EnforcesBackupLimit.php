<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\ProblemAuthorizationException;
use App\Services\WebBackupService;
use App\Support\IspContext;
use App\Support\ProblemType;

/**
 * limit_backup on the existing website and database writes (spec 018 FR-009,
 * research R12): client and reseller keys whose client does not have backups
 * enabled get 403 when the request carries any backup_* field — legacy hides
 * those fields together with the Backup tab. Checked before validation.
 * Admin keys are unaffected.
 */
trait EnforcesBackupLimit
{
    public function authorize(): bool
    {
        $sendsBackupFields = false;

        foreach (array_keys($this->all()) as $key) {
            if (str_starts_with((string) $key, 'backup_')) {
                $sendsBackupFields = true;
                break;
            }
        }

        return ! $sendsBackupFields
            || app(WebBackupService::class)->backupAllowed(app(IspContext::class)->authScope());
    }

    protected function failedAuthorization(): void
    {
        throw new ProblemAuthorizationException(
            'Backups are not enabled for this account.',
            ProblemType::FEATURE_NOT_ALLOWED,
            ['feature' => 'limit_backup']
        );
    }
}
