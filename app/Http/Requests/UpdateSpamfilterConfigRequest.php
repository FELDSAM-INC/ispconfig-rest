<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /mail/spamfilter/config/{server_id} (api/modules/mail/spamfilter-config.yaml).
 *
 * All fields are required (legacy NOTEMPTY validators,
 * spamfilter_config.tform.php); module is restricted to postfix_mysql.
 * The write path is a read-merge-write of the server.config INI blob
 * (ServerIniConfigService, C-8).
 */
class UpdateSpamfilterConfigRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('hostname') && is_string($this->input('hostname')) && $this->input('hostname') !== '') {
            $this->merge(['hostname' => $this->idnLower($this->input('hostname'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // [server] INI section
            'ip_address' => ['required', 'string', 'ipv4'],
            'netmask' => ['required', 'string', 'ipv4'],
            'gateway' => ['required', 'string', 'ipv4'],
            'hostname' => ['required', 'string', 'max:255'],
            'nameservers' => ['required', 'string', 'max:255'],
            // [mail] INI section
            'module' => ['required', 'string', Rule::in(['postfix_mysql'])],
            'maildir_path' => ['required', 'string', 'max:255'],
            'homedir_path' => ['required', 'string', 'max:255'],
            'mailuser_uid' => ['required', 'integer'],
            'mailuser_gid' => ['required', 'integer'],
            'mailuser_name' => ['required', 'string', 'max:64'],
            'mailuser_group' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * The validated fields grouped per INI section, ready for
     * ServerIniConfigService::mergeSections().
     *
     * @return array<string, array<string, string>>
     */
    public function sections(): array
    {
        $data = $this->validated();

        return [
            'server' => [
                'ip_address' => (string) $data['ip_address'],
                'netmask' => (string) $data['netmask'],
                'gateway' => (string) $data['gateway'],
                'hostname' => (string) $data['hostname'],
                'nameservers' => (string) $data['nameservers'],
            ],
            'mail' => [
                'module' => (string) $data['module'],
                'maildir_path' => (string) $data['maildir_path'],
                'homedir_path' => (string) $data['homedir_path'],
                'mailuser_uid' => (string) $data['mailuser_uid'],
                'mailuser_gid' => (string) $data['mailuser_gid'],
                'mailuser_name' => (string) $data['mailuser_name'],
                'mailuser_group' => (string) $data['mailuser_group'],
            ],
        ];
    }
}
