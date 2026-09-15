<?php

namespace App\Http\Requests\Concerns;

use App\Services\WebPermissionService;
use App\Support\IspContext;
use App\Support\ProblemTypeCollector;
use Illuminate\Validation\Validator;

/**
 * Website permissions of client and reseller keys (spec 020 FR-001…FR-011):
 * the validator after-hook adds one error per restricted field the request
 * may not set, so a refused request returns 422 before anything is written.
 * Admin keys are unaffected.
 */
trait EnforcesWebPermissions
{
    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $scope = app(IspContext::class)->authScope();

                if ($scope->isAdmin) {
                    return;
                }

                $context = $this->webPermissionContext();

                if ($context === null) {
                    return;
                }

                $violations = app(WebPermissionService::class)->typedViolations($scope, $this->all(), $context);
                $types = app(ProblemTypeCollector::class);

                foreach ($violations as $field => $violation) {
                    $validator->errors()->add($field, $violation['message']);

                    if ($violation['type'] !== null) {
                        $types->tag($field, $violation['type']);
                    }
                }
            },
        ];
    }

    /**
     * Create/update context of the website write, or null when the base rules
     * already fail for the fields the context needs (e.g. unknown parent).
     *
     * @return array{is_create: bool, type: string, server_id: int, owner_client_id: int, current: array<string, mixed>}|null
     */
    abstract protected function webPermissionContext(): ?array;
}
