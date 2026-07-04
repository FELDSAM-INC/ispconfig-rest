<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * POST /mail/alias-domains (api/modules/mail/alias-domains.yaml).
 */
class StoreMailAliasDomainRequest extends MailAliasDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:255', 'regex:'.self::DOMAIN_REGEX],
            'destination' => ['required', 'string', 'max:255', 'regex:'.self::DOMAIN_REGEX],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * source != destination (legacy source_destination_identical_txt) and
     * source unique among alias domains (FR-019).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->input('source') === $this->input('destination')) {
                    $validator->errors()->add('destination', 'Source and destination domains must be different.');

                    return;
                }

                $duplicate = DB::table('mail_forwarding')
                    ->where('type', 'aliasdomain')
                    ->where('source', (string) $this->input('source'))
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('source', 'An alias domain with this source already exists.');
                }
            },
        ];
    }
}
