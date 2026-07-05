<?php

namespace App\Http\Requests;

use App\Models\MailDomain;
use Closure;
use Illuminate\Validation\Rule;

/**
 * PUT /mail/domains/{id} (api/modules/mail/domains.yaml).
 *
 * Partial updates: every field is optional. Two immutability rules apply:
 *
 *  - domain cannot be changed after creation (contract note; legacy allows
 *    only admin renames with cascading updates — the API disallows renames
 *    instead, the agreed deviation recorded in the YAML);
 *  - server_id cannot be changed (legacy mail_domain_edit.php::
 *    onBeforeUpdate(): "The Server can not be changed.").
 *
 * Sending the current value is accepted (idempotent full-body PUTs).
 */
class UpdateMailDomainRequest extends MailDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['sometimes', 'integer', $this->immutableRule(fn (MailDomain $domain) => (int) $domain->server_id, 'server')],
            'domain' => [
                'sometimes',
                'string',
                'max:255',
                $this->immutableRule(fn (MailDomain $domain) => $domain->domain, 'domain name'),
            ],
            'dkim' => ['sometimes', 'boolean'],
            'dkim_private' => [
                Rule::requiredIf(function (): bool {
                    // Enabling DKIM requires a key unless the record already
                    // has one (legacy posts the stored key back with the form).
                    if (! $this->has('dkim') || ! $this->boolean('dkim')) {
                        return false;
                    }

                    $current = $this->currentDomain();

                    return $current === null || blank($current->getAttributes()['dkim_private'] ?? null);
                }),
                'nullable',
                'string',
                $this->dkimPrivateKeyRule(),
            ],
            'dkim_selector' => [
                'sometimes',
                'nullable',
                'string',
                'max:63',
                'regex:/^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/',
            ],
            // #6877 (spec 013 FR-021): relay fields are independently
            // optional (legacy mail_domain.tform.php:144-167 has no
            // validators). Omission preserves the stored value, an explicit
            // "" clears it — documented deviation from legacy's
            // restore-if-empty (mail_domain_edit.php:315-317).
            'relay_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'relay_user' => ['sometimes', 'nullable', 'string', 'max:255'],
            'relay_pass' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'local_delivery' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The route-bound record being updated.
     */
    protected function currentDomain(): ?MailDomain
    {
        $domain = $this->route('mailDomain');

        return $domain instanceof MailDomain ? $domain : null;
    }

    /**
     * Reject a value that differs from the record's current one.
     */
    protected function immutableRule(Closure $currentValue, string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($currentValue, $label): void {
            $current = $this->currentDomain();

            if ($current === null) {
                return;
            }

            $expected = $currentValue($current);
            $given = is_int($expected) ? (int) $value : $value;

            if ($given !== $expected) {
                $fail("The {$label} cannot be changed after creation.");
            }
        };
    }
}
