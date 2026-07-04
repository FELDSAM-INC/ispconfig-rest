<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/access-rules writes (contract:
 * api/modules/mail/access-rules.yaml; legacy:
 * source_code/interface/web/mail/form/mail_blacklist.tform.php +
 * mail_whitelist.tform.php).
 */
abstract class MailAccessRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active']);

        if ($this->has('source') && is_string($this->input('source')) && $this->input('source') !== '') {
            $input['source'] = $this->idnLowerEmail($this->input('source'));
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
    protected function currentAccessRule(): ?MailAccess
    {
        $record = $this->route('mailAccess');

        return $record instanceof MailAccess ? $record : null;
    }
}
