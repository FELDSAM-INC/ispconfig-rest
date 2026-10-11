<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DnsAcmeChallengeRequest;
use App\Models\DnsRecord;
use App\Models\DnsSoa;
use App\Services\AcmeChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ACME DNS-01 challenges (api/modules/dns/acme-challenges.yaml, spec 057), for full keys and keys with `access: acme`.
 */
class DnsAcmeChallengeController extends Controller
{
    public function __construct(private readonly AcmeChallengeService $challenges) {}

    /**
     * POST /dns/acme-challenges — 201 with the new record, 200 when it already exists.
     */
    public function store(DnsAcmeChallengeRequest $request): JsonResponse
    {
        $zone = $this->zone($request, $request->challengeName());
        [$record, $created] = $this->challenges->publish($zone, $request->challengeName(), $request->challengeValue());

        return response()->json($this->challenges->present($record, $zone), $created ? 201 : 200);
    }

    /**
     * GET /dns/acme-challenges/{id} — the record with its current status.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->challenges->present($this->record($request, $id)));
    }

    /**
     * DELETE /dns/acme-challenges/{id} — 204.
     */
    public function destroy(Request $request, int $id): Response
    {
        $this->challenges->delete($this->record($request, $id));

        return response()->noContent();
    }

    /**
     * DELETE /dns/acme-challenges?name=&value= — 204, also when no such record exists.
     */
    public function destroyByValue(DnsAcmeChallengeRequest $request): Response
    {
        $this->challenges->remove($this->zone($request, $request->challengeName()), $request->challengeName(), $request->challengeValue());

        return response()->noContent();
    }

    private function zone(Request $request, string $name): DnsSoa
    {
        return $this->challenges->zoneFor($name, $this->zones($request))
            ?? throw new NotFoundHttpException('No DNS zone available to this key hosts '.$name.'.');
    }

    private function record(Request $request, int $id): DnsRecord
    {
        return $this->challenges->find($id, $this->zones($request))
            ?? throw new NotFoundHttpException('The requested ACME challenge does not exist.');
    }

    /**
     * @return array<int, string>
     */
    private function zones(Request $request): array
    {
        $zones = $request->attributes->get('api_key_zones', []);

        return is_array($zones) ? $zones : [];
    }
}
