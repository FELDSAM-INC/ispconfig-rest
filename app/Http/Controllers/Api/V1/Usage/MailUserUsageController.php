<?php

namespace App\Http\Controllers\Api\V1\Usage;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Api\V1\Usage\Concerns\UsageListQuery;
use App\Http\Controllers\Controller;
use App\Models\MailUser;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Mailbox usage (contract: api/modules/usage/mail-users.yaml, spec 017 US2).
 */
class MailUserUsageController extends Controller
{
    use HandlesListQuery;
    use UsageListQuery;

    public function __construct(private readonly UsageService $usage) {}

    /**
     * GET /usage/mail-users — `mail_domain` narrows to mailboxes of one domain.
     */
    public function index(Request $request): JsonResponse
    {
        $this->rejectClientIdForClientKeys($request);

        $query = MailUser::query();
        $mailDomain = $request->query('mail_domain');

        if ($mailDomain !== null) {
            if (! is_string($mailDomain) || $mailDomain === '') {
                throw new BadRequestHttpException("Invalid value for filter 'mail_domain'.");
            }

            $query->where('email', 'like', '%@'.str_replace(['%', '_'], ['\\%', '\\_'], $mailDomain));
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['email'],
            defaultSort: 'email',
            filters: [
                'email' => 'wildcard',
                'client_id' => 'owning_client',
            ],
            extra: ['mail_domain'],
        );

        $result['data'] = $this->usage->mailUserRows($result['data']);

        return response()->json($result);
    }

    /**
     * GET /usage/mail-users/{id}
     */
    public function show(int $id): JsonResponse
    {
        $mailUser = MailUser::query()->readable()->whereKey($id)->firstOrFail();

        return response()->json($this->usage->mailUserRows([$mailUser])[0]);
    }
}
