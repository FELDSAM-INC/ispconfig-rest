<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateMailDomainDkimRequest;
use App\Models\MailDomain;
use App\Services\MailDomainDkimService;
use Illuminate\Http\JsonResponse;

/**
 * Mail domain DKIM (contract: api/modules/mail/domain-dkim.yaml, spec 027):
 * status and server-side key generation for any key that can read (GET) or
 * update (POST) the domain. Responses never contain the private key.
 */
class MailDomainDkimController extends Controller
{
    public function __construct(protected MailDomainDkimService $dkim) {}

    /**
     * GET /mail/domains/{id}/dkim
     */
    public function show(MailDomain $mailDomain): JsonResponse
    {
        return response()->json($this->dkim->view($mailDomain));
    }

    /**
     * POST /mail/domains/{id}/dkim — 200; datalog 'u' on mail_domain plus
     * the DKIM DNS record changes of a hosted zone.
     */
    public function store(GenerateMailDomainDkimRequest $request, MailDomain $mailDomain): JsonResponse
    {
        $this->dkim->generate($mailDomain, $request->selector());

        return response()->json($this->dkim->view($mailDomain->refresh()));
    }
}
