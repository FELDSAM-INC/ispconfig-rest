<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /mail/domains/{id}/dkim (api/modules/mail/domain-dkim.yaml, spec 027).
 *
 * The only option is the selector, validated like the mail domain's
 * dkim_selector (legacy mail_domain.tform.php REGEX validator, DB varchar(63)).
 */
class GenerateMailDomainDkimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'selector' => ['sometimes', 'string', 'max:63', 'regex:/^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/'],
        ];
    }

    /**
     * The requested selector, or null to keep the stored one.
     */
    public function selector(): ?string
    {
        $data = $this->validated();

        return array_key_exists('selector', $data) ? (string) $data['selector'] : null;
    }
}
