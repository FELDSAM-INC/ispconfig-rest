<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /system/resync (api/components/schemas/ResyncRequest.yaml).
 *
 * Action DTO — flags are integers 0/1, server IDs non-negative integers
 * (0 = all active servers of the type). Type failures are 422; a non-zero
 * server ID that does not reference an existing server is a 400 raised by
 * ResyncService at resolution time (contract).
 */
class ResyncRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $flags = [
            'resync_all', 'resync_sites', 'resync_ftp', 'resync_webdav',
            'resync_shell', 'resync_cron', 'resync_db', 'resync_mail',
            'resync_mailget', 'resync_mailbox', 'resync_mailfilter',
            'resync_mailinglist', 'resync_mailtransport', 'resync_mailrelay',
            'resync_dns', 'resync_client', 'resync_vserver',
        ];

        $serverIds = [
            'all_server_id', 'web_server_id', 'ftp_server_id',
            'webdav_server_id', 'shell_server_id', 'cron_server_id',
            'db_server_id', 'mail_server_id', 'mailbox_server_id',
            'dns_server_id', 'vserver_server_id',
        ];

        $rules = [];

        foreach ($flags as $flag) {
            $rules[$flag] = ['sometimes', 'integer', 'in:0,1'];
        }

        foreach ($serverIds as $field) {
            $rules[$field] = ['sometimes', 'integer', 'min:0'];
        }

        return $rules;
    }
}
