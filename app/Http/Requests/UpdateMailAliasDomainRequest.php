<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * PUT /mail/alias-domains/{id} (api/modules/mail/alias-domains.yaml).
 *
 * source is immutable after creation; only destination and active can be
 * updated.
 */
class UpdateMailAliasDomainRequest extends MailAliasDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentAliasDomain()?->getRawOriginal();

        return [
            'source' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'source', 'source domain')],
            'destination' => ['sometimes', 'string', 'max:255', 'regex:'.self::DOMAIN_REGEX],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * source != destination also holds on update.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->has('destination')) {
                    return;
                }

                $source = $this->currentAliasDomain()?->getRawOriginal()['source'] ?? null;

                if ($source !== null && $this->input('destination') === $source) {
                    $validator->errors()->add('destination', 'Source and destination domains must be different.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        unset($data['source']); // immutable

        return $data;
    }
}
