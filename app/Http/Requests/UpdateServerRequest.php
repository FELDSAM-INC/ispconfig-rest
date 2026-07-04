<?php

namespace App\Http\Requests;

/**
 * PUT /servers/{id} (api/modules/server/servers.yaml).
 *
 * Partial updates: every field optional. The mirror rules of legacy
 * server_edit.php::onSubmit (mirror_server_id forced to 0 when it equals
 * the record's own id or when editing server 1) are applied silently in
 * ServerController::update — they are corrections, not validation errors.
 */
class UpdateServerRequest extends ServerModuleRequest
{
    protected function prepareForValidation(): void
    {
        $this->stripTagsAndNewlines(['server_name']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_name' => ['sometimes', 'string', 'filled', 'max:255'],
            'mail_server' => ['sometimes', 'integer', 'in:0,1'],
            'web_server' => ['sometimes', 'integer', 'in:0,1'],
            'dns_server' => ['sometimes', 'integer', 'in:0,1'],
            'file_server' => ['sometimes', 'integer', 'in:0,1'],
            'db_server' => ['sometimes', 'integer', 'in:0,1'],
            'vserver_server' => ['sometimes', 'integer', 'in:0,1'],
            'proxy_server' => ['sometimes', 'integer', 'in:0,1'],
            'firewall_server' => ['sometimes', 'integer', 'in:0,1'],
            'xmpp_server' => ['sometimes', 'integer', 'in:0,1'],
            'mirror_server_id' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'integer', 'in:0,1'],
        ];
    }
}
