<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailRelayDomain;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/relay-domains writes (contract:
 * api/modules/mail/relay-domains.yaml; legacy:
 * source_code/interface/web/mail/form/mail_relay_domain.tform.php).
 */
abstract class MailRelayDomainRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active']);

        if ($this->has('domain') && is_string($this->input('domain')) && $this->input('domain') !== '') {
            $input['domain'] = $this->idnLower($this->input('domain'));
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
    protected function currentRelayDomain(): ?MailRelayDomain
    {
        $record = $this->route('mailRelayDomain');

        return $record instanceof MailRelayDomain ? $record : null;
    }
}
