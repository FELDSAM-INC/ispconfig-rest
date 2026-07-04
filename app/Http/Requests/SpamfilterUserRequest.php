<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\SpamfilterUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/spamfilter/users writes (contract:
 * api/modules/mail/spamfilter-users.yaml; legacy:
 * source_code/interface/web/mail/form/spamfilter_users.tform.php).
 *
 * `local` is an UPPERCASE Y/N string stored with exact casing (FR-005);
 * a non-zero policy_id must reference an existing policy (404 per the YAML,
 * checked in the controller).
 */
abstract class SpamfilterUserRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email')) && $this->input('email') !== '') {
            $this->merge(['email' => $this->idnLowerEmail($this->input('email'))]);
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
    protected function currentSpamfilterUser(): ?SpamfilterUser
    {
        $record = $this->route('spamfilterUser');

        return $record instanceof SpamfilterUser ? $record : null;
    }
}
