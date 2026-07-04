<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailRelayRecipient;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/relay-recipients writes (contract:
 * api/modules/mail/relay-recipients.yaml; legacy:
 * source_code/interface/web/mail/form/mail_relay_recipient.tform.php).
 */
abstract class MailRelayRecipientRequest extends FormRequest
{
    use NormalizesMailInput;

    /** Contract pattern: email, @domain.tld or bare pattern. */
    public const SOURCE_REGEX = '/^[^@]+@[^@]+\.[^@]+$|^@[^@]+\.[^@]+$|^[^@]+$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active']);

        if ($this->has('source') && is_string($this->input('source')) && $this->input('source') !== '') {
            $source = trim($this->input('source'));

            $input['source'] = str_starts_with($source, '@')
                ? '@'.$this->idnLower(substr($source, 1))
                : $this->idnLowerEmail($source);
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
    protected function currentRelayRecipient(): ?MailRelayRecipient
    {
        $record = $this->route('mailRelayRecipient');

        return $record instanceof MailRelayRecipient ? $record : null;
    }
}
