<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/web-folders (api/modules/sites/web-folders.yaml; legacy
 * form/web_folder.tform.php + web_folder_edit.php). The duplicate
 * (parent_domain_id, path) check and derived fields are handled in the
 * controller.
 */
class StoreWebFolderRequest extends SitesRequest
{
    protected function booleanFields(): array
    {
        return ['active'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_domain_id' => [
                'required',
                'integer',
                Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias']),
            ],
            'path' => ['required', 'string', 'max:255', 'regex:/^[\w\.\-\/]{1,255}$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
