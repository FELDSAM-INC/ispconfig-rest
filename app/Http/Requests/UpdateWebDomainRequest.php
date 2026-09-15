<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\EnforcesWebPermissions;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PUT /sites/web-domains/{id} (api/modules/sites/web-domains.yaml).
 *
 * Partial updates; `server_id` is immutable (legacy onBeforeUpdate:
 * "The Server can not be changed."), `system_user`/`system_group`/
 * `web_folder` are preserved server-side (WebDomainService drops
 * web_folder from the payload).
 */
class UpdateWebDomainRequest extends WebDomainRequest
{
    use EnforcesWebPermissions;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = fn (): string => (string) $this->input(
            'type',
            (string) ($this->currentDomain()?->getAttributes()['type'] ?? 'vhost')
        );

        return array_merge($this->commonRules(), [
            'server_id' => [
                'sometimes',
                'integer',
                $this->immutableRule(fn () => $this->currentDomain() ? (int) $this->currentDomain()->getAttributes()['server_id'] : null, 'server'),
            ],
            'domain' => [
                'sometimes',
                'string',
                'max:255',
                $this->webDomainFormatRule(),
            ],
            'type' => ['sometimes', Rule::in(['vhost', 'vhostsubdomain', 'vhostalias'])],
            'parent_domain_id' => [
                'sometimes',
                'integer',
                Rule::when(
                    in_array($type(), ['vhostsubdomain', 'vhostalias'], true),
                    [Rule::exists('web_domain', 'domain_id')->where('type', 'vhost')]
                ),
            ],
            'hd_quota' => [
                'sometimes',
                ...$this->quotaRules(),
                $this->vhostQuotaNotZeroRule($type),
            ],
        ]);
    }

    protected function currentDomain(): ?WebDomain
    {
        $domain = $this->route('webDomain');

        return $domain instanceof WebDomain ? $domain : null;
    }

    /**
     * Spec 020 context: the stored website (raw attributes), its server and
     * owning client.
     *
     * @return array{is_create: bool, type: string, server_id: int, owner_client_id: int, current: array<string, mixed>}|null
     */
    protected function webPermissionContext(): ?array
    {
        $domain = $this->currentDomain();

        if ($domain === null) {
            return null;
        }

        $current = $domain->getAttributes();

        return [
            'is_create' => false,
            'type' => (string) ($current['type'] ?? 'vhost'),
            'server_id' => (int) ($current['server_id'] ?? 0),
            'owner_client_id' => (int) DB::table('sys_group')->where('groupid', (int) ($current['sys_groupid'] ?? 0))->value('client_id'),
            'current' => $current,
        ];
    }
}
