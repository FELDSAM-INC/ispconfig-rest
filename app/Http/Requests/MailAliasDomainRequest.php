<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailAliasDomain;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/alias-domains writes (contract:
 * api/modules/mail/alias-domains.yaml; legacy:
 * source_code/interface/web/mail/mail_aliasdomain_edit.php):
 *
 *  - source and destination are '@'-prefixed lowercase punycode domains
 *    (the prefix is added automatically when missing, per the contract);
 *  - both must exist in mail_domain (400 — checked in the controller) and
 *    differ from each other;
 *  - server_id and sys_groupid come from the DESTINATION domain.
 */
abstract class MailAliasDomainRequest extends FormRequest
{
    use NormalizesMailInput;

    public const DOMAIN_REGEX = '/^@[\w\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active']);

        foreach (['source', 'destination'] as $field) {
            if ($this->has($field) && is_string($this->input($field)) && $this->input($field) !== '') {
                $value = ltrim(trim($this->input($field)), '@');
                $input[$field] = '@'.$this->idnLower($value);
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * The route-bound record being updated (null on store).
     */
    protected function currentAliasDomain(): ?MailAliasDomain
    {
        $record = $this->route('mailAliasDomain');

        return $record instanceof MailAliasDomain ? $record : null;
    }
}
