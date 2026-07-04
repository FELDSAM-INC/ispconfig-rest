<?php

namespace App\Http\Requests;

/**
 * POST /servers (api/modules/server/servers.yaml).
 *
 * Mirrors legacy server.tform.php: server_name required with STRIPTAGS/
 * STRIPNL save filters; the role flags and `active` are INTEGER 0/1
 * (tinyint columns — not y/n enums); mirror_server_id integer >= 0.
 */
class StoreServerRequest extends ServerModuleRequest
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
            'server_name' => ['required', 'string', 'filled', 'max:255'],
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
