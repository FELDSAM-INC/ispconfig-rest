<?php

namespace App\Services;

use App\Models\DirectiveSnippet;
use Illuminate\Support\Facades\DB;

/**
 * Directive-snippet domain logic (legacy:
 * source_code/interface/web/admin/directive_snippets_edit.php /
 * directive_snippets_del.php / validate_server_directive_snippets.inc.php).
 *
 *  - (name, type) uniqueness — application-level, mirrored from the legacy
 *    validator (no DB UNIQUE constraint); the controller answers 409.
 *  - "in use" resolution: apache/nginx snippets via
 *    web_domain.directive_snippets_id; php snippets via the
 *    required_php_snippets CSV of snippets that are themselves referenced by
 *    a web_domain. (Legacy resolves the CSV with a REGEXP `id|,id|id,` whose
 *    first alternative also matches substrings — e.g. snippet 5 would match
 *    "15"; this port checks exact CSV membership, the documented intent.)
 *  - update_sites=y && active=y re-emits every affected web_domain as a
 *    forced full-record datalog update through ResyncService::forceReEmit
 *    (shared primitive — legacy onAfterUpdate's datalogUpdate(..., true)).
 */
class DirectiveSnippetService
{
    public function __construct(protected ResyncService $resync) {}

    /**
     * Legacy validate_snippet: (name, type) must be unique across snippets,
     * excluding the record being edited.
     */
    public function nameTypeTaken(string $name, string $type, ?int $ignoreId = null): bool
    {
        return DirectiveSnippet::query()
            ->where('name', $name)
            ->where('type', $type)
            ->when($ignoreId !== null, fn ($query) => $query->where('directive_snippets_id', '!=', $ignoreId))
            ->exists();
    }

    /**
     * Legacy getAffectedSites(): web_domain IDs that would have to be
     * rewritten for this snippet. Proxy snippets never affect sites.
     *
     * @return array<int, int>
     */
    public function affectedSiteIds(DirectiveSnippet $snippet): array
    {
        $type = (string) $snippet->type;
        $id = (int) $snippet->getKey();

        if ($type === 'php') {
            $requiringIds = $this->snippetIdsRequiringPhpSnippet($id);

            if ($requiringIds === []) {
                return [];
            }

            return DB::table('web_domain')
                ->whereIn('directive_snippets_id', $requiringIds)
                ->pluck('domain_id')
                ->map(fn ($domainId) => (int) $domainId)
                ->all();
        }

        if ($type === 'apache' || $type === 'nginx') {
            return DB::table('web_domain')
                ->where('directive_snippets_id', $id)
                ->pluck('domain_id')
                ->map(fn ($domainId) => (int) $domainId)
                ->all();
        }

        return [];
    }

    public function isInUse(DirectiveSnippet $snippet): bool
    {
        return $this->affectedSiteIds($snippet) !== [];
    }

    /**
     * Legacy onAfterUpdate: force-emit a full-record web_domain datalog
     * update for every affected site so the web servers rewrite the vhosts.
     *
     * @return int number of datalog entries written
     */
    public function emitSiteUpdates(DirectiveSnippet $snippet): int
    {
        $siteIds = $this->affectedSiteIds($snippet);

        if ($siteIds === []) {
            return 0;
        }

        $rows = DB::table('web_domain')
            ->whereIn('domain_id', $siteIds)
            ->orderBy('domain_id')
            ->get()
            ->all();

        return $this->resync->forceReEmit('web_domain', 'domain_id', $rows);
    }

    /**
     * IDs of snippets whose required_php_snippets CSV lists the given php
     * snippet (exact membership, see class doc).
     *
     * @return array<int, int>
     */
    protected function snippetIdsRequiringPhpSnippet(int $id): array
    {
        return DirectiveSnippet::query()
            ->where('required_php_snippets', '!=', '')
            ->get()
            ->filter(fn (DirectiveSnippet $snippet) => in_array($id, $snippet->requiredPhpSnippetIds(), true))
            ->map(fn (DirectiveSnippet $snippet) => (int) $snippet->getKey())
            ->values()
            ->all();
    }
}
