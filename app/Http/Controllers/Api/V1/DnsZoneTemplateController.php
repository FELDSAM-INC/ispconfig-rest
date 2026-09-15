<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Models\DnsTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zone templates offered by the DNS wizard (contract:
 * api/modules/dns/zone-templates.yaml; spec 029).
 *
 * Legacy dns_wizard.php:73 lists `dns_template WHERE visible = 'Y' ORDER BY
 * name ASC` for every user, without getAuthSQL — a customer picks from the
 * provider's templates although it owns none of them. This read-only
 * projection mirrors that (`scoped: false`) and returns only the id, the name
 * and the placeholder tokens the wizard must ask for. The template text, its
 * owner and its permissions stay in the administrator resource
 * /dns/templates, which keeps its spec 011 row scoping.
 */
class DnsZoneTemplateController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /dns/zone-templates — visible templates, readable by every key.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            DnsTemplate::query()->where('visible', 'Y'),
            $request,
            sortable: ['name', 'id'],
            defaultSort: 'name',
            sortAliases: ['id' => 'template_id'],
            scoped: false,
        );

        $result['data'] = collect($result['data'])
            ->map(static fn (DnsTemplate $template): array => [
                'id' => (int) $template->getKey(),
                'name' => (string) $template->name,
                'fields' => self::fields($template),
            ])
            ->all();

        return response()->json($result);
    }

    /**
     * The placeholder tokens a template declares, in the order it lists them,
     * limited to the tokens the wizard knows (legacy dns_template.tform.php
     * $field_values, mirrored in DnsTemplate::ALLOWED_FIELDS).
     *
     * @return array<int, string>
     */
    protected static function fields(DnsTemplate $template): array
    {
        $tokens = array_map(
            static fn (string $token): string => strtoupper(trim($token)),
            explode(',', (string) $template->fields)
        );

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => in_array($token, DnsTemplate::ALLOWED_FIELDS, true)
        )));
    }
}
