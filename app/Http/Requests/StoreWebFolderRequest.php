<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesReferences;
use Illuminate\Validation\Rule;

/**
 * POST /sites/web-folders (api/modules/sites/web-folders.yaml; legacy
 * form/web_folder.tform.php + web_folder_edit.php). The duplicate
 * (parent_domain_id, path) check and derived fields are handled in the
 * controller.
 */
class StoreWebFolderRequest extends SitesRequest
{
    use ScopesReferences;

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
                $this->readable(Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias'])),
            ],
            'path' => ['required', 'string', 'max:255', 'regex:/^[\w\.\-\/]{1,255}$/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && preg_match('#(^|/)\.{1,2}(/|$)#', $value)) {
                    $fail('The directory path must stay within the website document root.');
                }
            }],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
