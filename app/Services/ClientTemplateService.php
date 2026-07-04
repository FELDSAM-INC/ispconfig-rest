<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\ClientTemplateAssigned;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Template assignment and limit recomputation, porting legacy
 * source_code/interface/lib/classes/client_templates.inc.php:
 *
 *  - master templates live in client.template_master, additional templates
 *    as client_template_assigned pivot rows (old-style template_additional
 *    bookkeeping is converted to pivot rows on the fly, exactly like
 *    legacy apply_client_templates);
 *  - after every assignment change the client's effective limits are
 *    recomputed: numeric limits summed with -1 (unlimited) winning,
 *    limit_cron_frequency taking the minimum (floor 1), default_* servers
 *    only filled when unset, CHECKBOX y/n picking the less-limited value
 *    (force_suexec inverted), CHECKBOXARRAY/MULTIPLE lists union-merged,
 *    SELECT picking the lower option index, reseller limit_client
 *    adjustments;
 *  - merged limits are written back through Client::save(), so the change
 *    is datalogged (legacy issues a plain UPDATE here — the datalog row is
 *    a deliberate constitution Principle II improvement).
 *
 * The previous implementation's operator-precedence bug in the skip
 * condition and the duplicated force_suexec field type (spec 001 gap G17)
 * are gone: this is a fresh port of the legacy merge rules.
 */
class ClientTemplateService
{
    /**
     * y/n CHECKBOX limit fields (legacy client/reseller tform limits tab).
     * Merging picks 'y' when either side is 'y' — except force_suexec,
     * where 'n' is the less-limited value.
     *
     * @var array<int, string>
     */
    protected const CHECKBOX_FIELDS = [
        'limit_mail_backup',
        'limit_relayhost',
        'limit_xmpp_muc',
        'limit_xmpp_anon',
        'limit_xmpp_vjud',
        'limit_xmpp_proxy',
        'limit_xmpp_status',
        'limit_xmpp_pastebin',
        'limit_xmpp_httparchive',
        'limit_cgi',
        'limit_ssi',
        'limit_perl',
        'limit_ruby',
        'limit_python',
        'force_suexec',
        'limit_hterror',
        'limit_wildcard',
        'limit_ssl',
        'limit_ssl_letsencrypt',
        'limit_backup',
        'limit_directive_snippets',
    ];

    /**
     * CHECKBOXARRAY fields: canonical value order from the legacy tform —
     * the union is filtered and ordered by this list, exactly like legacy.
     *
     * @var array<string, array<int, string>>
     */
    protected const CHECKBOXARRAY_FIELDS = [
        'web_php_options' => ['no', 'fast-cgi', 'cgi', 'mod', 'suphp', 'php-fpm', 'hhvm'],
        'ssh_chroot' => ['no', 'jailkit'],
    ];

    /**
     * MULTIPLE server-list fields, union-merged. Legacy merges
     * mail/web/dns/db_servers with array_unique(array_merge(...)) and
     * filters xmpp_servers against the live server list; the API
     * union-merges xmpp_servers too (documented deviation — no DB-dependent
     * form values here).
     *
     * @var array<int, string>
     */
    protected const MULTIPLE_FIELDS = [
        'mail_servers',
        'web_servers',
        'dns_servers',
        'db_servers',
        'xmpp_servers',
    ];

    /**
     * SELECT fields: option order from the legacy tform ('lower index wins'
     * on merge — for limit_cron_type, 'full' is the least limited).
     *
     * @var array<string, array<int, string>>
     */
    protected const SELECT_FIELDS = [
        'limit_cron_type' => ['full', 'chrooted', 'url'],
    ];

    public function __construct(protected DatalogService $datalog)
    {
    }

    /**
     * A client's template assignments in the contract shape
     * (ClientTemplateAssigned.yaml): the master template (id null,
     * is_master true) followed by the pivot rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function assignmentsForClient(Client $client): Collection
    {
        $rows = collect();

        $masterId = (int) ($client->getAttributes()['template_master'] ?? 0);

        if ($masterId > 0) {
            $rows->push([
                'id' => null,
                'client_id' => (int) $client->getKey(),
                'client_template_id' => $masterId,
                'is_master' => true,
                'template' => ClientTemplate::find($masterId),
            ]);
        }

        $pivots = ClientTemplateAssigned::query()
            ->with('template')
            ->where('client_id', $client->getKey())
            ->orderBy('assigned_template_id')
            ->get();

        foreach ($pivots as $pivot) {
            $rows->push([
                'id' => (int) $pivot->getKey(),
                'client_id' => (int) $pivot->client_id,
                'client_template_id' => (int) $pivot->client_template_id,
                'is_master' => false,
                'template' => $pivot->template,
            ]);
        }

        return $rows;
    }

    /**
     * A single assignment by template id (contract:
     * GET /clients/{client_id}/templates/{template_id}); the master
     * assignment is checked first. 404 when the template is not assigned.
     *
     * @return array<string, mixed>
     */
    public function assignmentForClient(Client $client, int $templateId): array
    {
        $assignment = $this->assignmentsForClient($client)
            ->first(fn (array $row) => $row['client_template_id'] === $templateId);

        if ($assignment === null) {
            throw new NotFoundHttpException('The template is not assigned to this client.');
        }

        return $assignment;
    }

    /**
     * Assign a template to a client (contract: POST
     * /clients/{client_id}/templates). Master templates set
     * client.template_master (datalogged via Client::save()); additional
     * templates insert a pivot row. Duplicate assignments are rejected
     * with 409; limits are recomputed afterwards.
     *
     * @return array<string, mixed> the created assignment (contract shape)
     */
    public function assignTemplate(Client $client, ClientTemplate $template): array
    {
        $templateId = (int) $template->getKey();

        if ($template->getAttributes()['template_type'] === 'm') {
            if ((int) ($client->getAttributes()['template_master'] ?? 0) === $templateId) {
                throw new ConflictHttpException('The master template is already assigned to this client.');
            }

            $client->setAttribute('template_master', $templateId);
            $client->save();

            $this->applyClientTemplates((int) $client->getKey());

            return [
                'id' => null,
                'client_id' => (int) $client->getKey(),
                'client_template_id' => $templateId,
                'is_master' => true,
                'template' => $template->refresh(),
            ];
        }

        $duplicate = ClientTemplateAssigned::query()
            ->where('client_id', $client->getKey())
            ->where('client_template_id', $templateId)
            ->exists();

        if ($duplicate) {
            throw new ConflictHttpException('The template is already assigned to this client.');
        }

        $assignment = new ClientTemplateAssigned([
            'client_id' => (int) $client->getKey(),
            'client_template_id' => $templateId,
        ]);
        $assignment->save();

        $this->applyClientTemplates((int) $client->getKey());

        return [
            'id' => (int) $assignment->getKey(),
            'client_id' => (int) $client->getKey(),
            'client_template_id' => $templateId,
            'is_master' => false,
            'template' => $template->refresh(),
        ];
    }

    /**
     * Unassign a template from a client (contract: DELETE
     * /clients/{client_id}/templates/{template_id}); 404 when the template
     * is not assigned. Limits are recomputed afterwards.
     */
    public function unassignTemplate(Client $client, int $templateId): void
    {
        if ((int) ($client->getAttributes()['template_master'] ?? 0) === $templateId) {
            $client->setAttribute('template_master', 0);
            $client->save();

            $this->applyClientTemplates((int) $client->getKey());

            return;
        }

        $assignment = ClientTemplateAssigned::query()
            ->where('client_id', $client->getKey())
            ->where('client_template_id', $templateId)
            ->orderBy('assigned_template_id')
            ->first();

        if ($assignment === null) {
            throw new NotFoundHttpException('The template is not assigned to this client.');
        }

        $assignment->delete();

        $this->applyClientTemplates((int) $client->getKey());
    }

    /**
     * Re-apply a changed template to every client using it (legacy
     * client_template_edit.php::onAfterUpdate — spec 001 gap G14):
     * master templates via client.template_master, additional templates via
     * the pivot plus old-style template_additional bookkeeping.
     */
    public function reapplyTemplate(ClientTemplate $template): void
    {
        $templateId = (int) $template->getKey();

        if ($template->getAttributes()['template_type'] === 'm') {
            $clientIds = DB::table('client')->where('template_master', $templateId)->pluck('client_id');
        } else {
            $clientIds = DB::table('client')
                ->where('template_additional', 'like', '%/'.$templateId.'/%')
                ->orWhere('template_additional', 'like', $templateId.'/%')
                ->orWhere('template_additional', 'like', '%/'.$templateId)
                ->pluck('client_id')
                ->merge(
                    DB::table('client_template_assigned')
                        ->where('client_template_id', $templateId)
                        ->pluck('client_id')
                )
                ->unique();
        }

        foreach ($clientIds as $clientId) {
            $this->applyClientTemplates((int) $clientId);
        }
    }

    /**
     * Recompute a client's effective limits from its master + additional
     * templates — faithful port of legacy
     * client_templates.inc.php::apply_client_templates().
     *
     * No-op when the client has no master template (legacy: "if there is no
     * master template it makes NO SENSE adding sub templates"). The write-
     * back goes through Client::save() (datalogged; suppressed when nothing
     * actually changes).
     */
    public function applyClientTemplates(int $clientId): void
    {
        $client = Client::find($clientId);

        if ($client === null) {
            return;
        }

        $record = $client->getRawOriginal();
        $masterTemplateId = (int) ($record['template_master'] ?? 0);
        $isReseller = (int) ($record['limit_client'] ?? 0) !== 0;

        // Convert old-style template_additional bookkeeping ('id/id/...')
        // to pivot rows, then clear it (legacy does the same on the fly).
        $additionalStr = trim((string) ($record['template_additional'] ?? ''));

        if ($additionalStr !== '') {
            $this->convertLegacyAssignments($clientId, $additionalStr);
            $this->datalog->updateRecord('client', 'client_id', $clientId, ['template_additional' => '']);
            $client->refresh();
        }

        if ($masterTemplateId <= 0) {
            return;
        }

        $master = DB::table('client_template')->where('template_id', $masterTemplateId)->first();

        if ($master === null) {
            return;
        }

        $limits = array_map(
            fn ($value) => $value === null ? $value : (is_int($value) || is_float($value) ? $value : (string) $value),
            (array) $master
        );

        // Reseller adjustment on the master limits (legacy lines 126-127).
        if ($isReseller && (int) ($limits['limit_client'] ?? 0) === 0) {
            $limits['limit_client'] = -1;
        } elseif (! $isReseller && (int) ($limits['limit_client'] ?? 0) !== 0) {
            $limits['limit_client'] = 0;
        }

        // Merge each additional template on top.
        $additionalTemplateIds = DB::table('client_template_assigned')
            ->where('client_id', $clientId)
            ->pluck('client_template_id');

        foreach ($additionalTemplateIds as $templateId) {
            $addLimits = DB::table('client_template')->where('template_id', $templateId)->first();

            if ($addLimits === null) {
                continue; // template deleted in the meantime (legacy guard)
            }

            $limits = $this->mergeTemplate($limits, (array) $addLimits, $isReseller);
        }

        // Write back (legacy write filter): limit*/default*/_servers,
        // ssh_chroot, web_php_options, force_suexec; skip unset default
        // servers; only resellers may receive limit_client from templates.
        if (! $isReseller) {
            unset($limits['limit_client']);
        }

        $updates = [];

        foreach ($limits as $key => $value) {
            if (str_contains($key, 'default') && (int) $value === 0) {
                continue; // template doesn't define the default server
            }

            if (str_contains($key, 'limit')
                || str_contains($key, 'default')
                || str_contains($key, '_servers')
                || in_array($key, ['ssh_chroot', 'web_php_options', 'force_suexec'], true)) {
                $updates[$key] = $value;
            }
        }

        if ($updates !== []) {
            // forceFill routes values through the model casts and save()
            // datalogs the update (no-change suppression applies).
            $client->forceFill($updates)->save();
        }
    }

    /**
     * Merge one additional template into the accumulated limits — the
     * foreach body of legacy apply_client_templates().
     *
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $addLimits
     * @return array<string, mixed>
     */
    protected function mergeTemplate(array $limits, array $addLimits, bool $isReseller): array
    {
        foreach ($addLimits as $key => $value) {
            if ($key === 'limit_client') {
                if ($isReseller && (int) $value === 0) {
                    continue;
                }

                if (! $isReseller && (int) $value !== 0) {
                    continue;
                }
            }

            // Only limit/default/server-ish keys take part in the merge.
            if (! (str_contains($key, 'limit')
                || str_contains($key, 'default')
                || str_contains($key, 'servers')
                || in_array($key, ['ssh_chroot', 'web_php_options', 'force_suexec'], true))) {
                continue;
            }

            $isServerList = in_array($key, self::MULTIPLE_FIELDS, true);

            if ($value !== null && is_numeric($value) && ! $isServerList) {
                $current = (int) ($limits[$key] ?? 0);
                $value = (int) $value;

                if ($key === 'limit_cron_frequency') {
                    if ($value < $current) {
                        $limits[$key] = $value;
                    }

                    if ((int) $limits[$key] < 1) {
                        $limits[$key] = 1; // silent minimum of 1 minute
                    }
                } elseif (str_starts_with($key, 'default_')) {
                    // Additional templates don't override the master's
                    // default server; they only fill an unset (0) one.
                    if ($current === 0) {
                        $limits[$key] = $value;
                    }
                } else {
                    if ($current > -1) {
                        $limits[$key] = $value === -1 ? -1 : $current + $value;
                    }
                }

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            if (isset(self::CHECKBOXARRAY_FIELDS[$key])) {
                $limits[$key] = $this->unionByCanonicalOrder(
                    (string) ($limits[$key] ?? ''),
                    $value,
                    self::CHECKBOXARRAY_FIELDS[$key]
                );
            } elseif ($isServerList) {
                $current = ($limits[$key] ?? '') === null ? [] : explode(',', (string) $limits[$key]);
                $additional = explode(',', $value);
                $merged = array_values(array_unique(array_filter(
                    array_merge($current, $additional),
                    fn ($item) => trim((string) $item) !== ''
                )));
                $limits[$key] = implode(',', $merged);
            } elseif (in_array($key, self::CHECKBOX_FIELDS, true)) {
                if ($key === 'force_suexec') {
                    // 'n' is less limited than 'y'.
                    $current = (string) ($limits[$key] ?? 'y');
                    $limits[$key] = ($current === 'n' || $value === 'n') ? 'n' : 'y';
                } else {
                    // 'y' is less limited than 'n'.
                    $current = (string) ($limits[$key] ?? 'n');
                    $limits[$key] = ($current === 'y' || $value === 'y') ? 'y' : 'n';
                }
            } elseif (isset(self::SELECT_FIELDS[$key])) {
                $options = self::SELECT_FIELDS[$key];
                $currentIndex = array_search((string) ($limits[$key] ?? ''), $options, true);
                $newIndex = array_search($value, $options, true);

                if ($currentIndex !== false && $newIndex !== false) {
                    $limits[$key] = $options[min($currentIndex, $newIndex)];
                } elseif ($newIndex !== false) {
                    $limits[$key] = $value;
                }
            }
        }

        return $limits;
    }

    /**
     * CHECKBOXARRAY union, filtered and ordered by the legacy form's
     * canonical value list (legacy iterates the form values and keeps
     * entries present on either side).
     *
     * @param  array<int, string>  $canonical
     */
    protected function unionByCanonicalOrder(string $current, string $additional, array $canonical): string
    {
        $currentValues = explode(',', $current);
        $additionalValues = explode(',', $additional);

        $union = [];

        foreach ($canonical as $option) {
            if (in_array($option, $currentValues, true) || in_array($option, $additionalValues, true)) {
                $union[] = $option;
            }
        }

        return implode(',', $union);
    }

    /**
     * Convert old-style client.template_additional bookkeeping
     * ('/'-separated template ids, optionally 'assigned_id:template_id')
     * into client_template_assigned pivot rows — legacy
     * update_client_templates() as invoked from apply_client_templates().
     */
    protected function convertLegacyAssignments(int $clientId, string $additionalStr): void
    {
        $items = array_filter(array_map('trim', explode('/', $additionalStr)), fn ($item) => $item !== '');

        // Count how many rows of each template id are needed…
        $needed = [];

        foreach ($items as $item) {
            $templateId = str_contains($item, ':')
                ? (int) explode(':', $item, 2)[1]
                : (int) $item;

            if ($templateId > 0) {
                $needed[$templateId] = ($needed[$templateId] ?? 0) + 1;
            }
        }

        // …minus what the pivot already has (legacy old-style branch).
        $existing = DB::table('client_template_assigned')
            ->where('client_id', $clientId)
            ->pluck('client_template_id');

        foreach ($existing as $templateId) {
            $needed[(int) $templateId] = ($needed[(int) $templateId] ?? 0) - 1;
        }

        foreach ($needed as $templateId => $count) {
            for ($i = 0; $i < $count; $i++) {
                (new ClientTemplateAssigned([
                    'client_id' => $clientId,
                    'client_template_id' => $templateId,
                ]))->save();
            }

            // Legacy removes surplus pivot rows one by one (DELETE ... LIMIT 1).
            for ($i = $count; $i < 0; $i++) {
                ClientTemplateAssigned::query()
                    ->where('client_id', $clientId)
                    ->where('client_template_id', $templateId)
                    ->orderBy('assigned_template_id')
                    ->first()
                    ?->delete();
            }
        }
    }
}
