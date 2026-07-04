<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebDomain;
use App\Services\WebDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * SSL certificate subresource of a web domain
 * (contract: api/modules/sites/web-domains.yaml, /sites/web-domains/{id}/ssl
 * + /ssl/renew) — maps to the web_domain ssl_* columns; writes datalog
 * `u` entries with ssl_action save/del, the mechanism the legacy SSL tab
 * uses to drive the server plugin.
 */
class WebDomainSslController extends Controller
{
    public function __construct(protected WebDomainService $service) {}

    /**
     * GET /sites/web-domains/{id}/ssl — 200 with the stored PEM material,
     * 204 when no certificate is configured.
     */
    public function show(WebDomain $webDomain): JsonResponse|Response
    {
        $attributes = $webDomain->getAttributes();

        if (($attributes['ssl_cert'] ?? '') === '' || $attributes['ssl_cert'] === null) {
            return response()->noContent();
        }

        return response()->json([
            'ssl_cert' => (string) $attributes['ssl_cert'],
            'ssl_key' => (string) ($attributes['ssl_key'] ?? ''),
            'ssl_bundle' => (string) ($attributes['ssl_bundle'] ?? ''),
            'ssl_letsencrypt' => ($attributes['ssl_letsencrypt'] ?? 'n') === 'y',
        ]);
    }

    /**
     * POST /sites/web-domains/{id}/ssl — validate the PEM pair, store it
     * with ssl_action='save' (datalog `u`), 200.
     */
    public function store(Request $request, WebDomain $webDomain): JsonResponse
    {
        $data = $request->validate([
            'ssl_cert' => ['required', 'string'],
            'ssl_key' => ['required', 'string'],
            'ssl_bundle' => ['sometimes', 'nullable', 'string'],
        ]);

        // The openssl_* calls emit PHP warnings on malformed input —
        // suppressed so they surface as 422s, not 500s.
        $cert = @openssl_x509_read($data['ssl_cert']);
        if ($cert === false) {
            throw ValidationException::withMessages([
                'ssl_cert' => 'The certificate is not valid PEM.',
            ]);
        }

        $key = @openssl_pkey_get_private($data['ssl_key']);
        if ($key === false) {
            throw ValidationException::withMessages([
                'ssl_key' => 'The private key is not valid PEM.',
            ]);
        }

        if (! @openssl_x509_check_private_key($cert, $key)) {
            throw ValidationException::withMessages([
                'ssl_key' => 'The private key does not match the certificate.',
            ]);
        }

        $result = DB::transaction(fn () => $this->service->saveSsl(
            $webDomain,
            $data['ssl_cert'],
            $data['ssl_key'],
            $data['ssl_bundle'] ?? null
        ));

        return response()->json($result);
    }

    /**
     * DELETE /sites/web-domains/{id}/ssl — 204; datalog `u` with
     * ssl_action='del'.
     */
    public function destroy(WebDomain $webDomain): Response
    {
        DB::transaction(function () use ($webDomain): void {
            $this->service->deleteSsl($webDomain);
        });

        return response()->noContent();
    }

    /**
     * POST /sites/web-domains/{id}/ssl/renew — forced no-change datalog
     * `u` (resync mechanism) so the Let's Encrypt plugin re-evaluates the
     * domain; 400 unless the domain is LE-managed and SSL-enabled.
     */
    public function renew(WebDomain $webDomain): JsonResponse
    {
        $attributes = $webDomain->getAttributes();

        if (($attributes['ssl_letsencrypt'] ?? 'n') !== 'y') {
            throw new BadRequestHttpException("Let's Encrypt is not enabled for this domain.");
        }

        if (($attributes['ssl'] ?? 'n') !== 'y') {
            throw new BadRequestHttpException('SSL is not enabled for this domain.');
        }

        DB::transaction(function () use ($webDomain): void {
            $this->service->renewLetsEncrypt($webDomain);
        });

        return response()->json([
            'ssl_letsencrypt' => 'y',
            'queued' => true,
        ]);
    }
}
