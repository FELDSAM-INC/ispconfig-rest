<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailGet;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Shared behavior for /mail/fetchmail writes (contract:
 * api/modules/mail/fetchmail.yaml; legacy:
 * source_code/interface/web/mail/form/mail_get.tform.php):
 *
 *  - source_server against the legacy host/IPv4 regex, IDN + lowercase;
 *  - destination must be the email of an existing mail_user (C-3);
 *  - source_password write-only, only re-set when non-empty on update.
 */
abstract class MailGetRequest extends FormRequest
{
    use NormalizesMailInput;

    public const SOURCE_SERVER_REGEX = '/^([\w\.\-]{2,64}\.[a-zA-Z\-]{2,10}|(?:[0-9]{1,3}\.){3}[0-9]{1,3})$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['source_delete', 'source_read_all', 'active']);

        if ($this->has('source_server') && is_string($this->input('source_server')) && $this->input('source_server') !== '') {
            $input['source_server'] = $this->idnLower($this->input('source_server'));
        }

        if ($this->has('destination') && is_string($this->input('destination')) && $this->input('destination') !== '') {
            $input['destination'] = $this->idnLowerEmail($this->input('destination'));
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
    protected function currentMailGet(): ?MailGet
    {
        $record = $this->route('mailGet');

        return $record instanceof MailGet ? $record : null;
    }

    /**
     * Legacy datasource constraint: the delivery destination is picked from
     * existing mail_user emails (C-3).
     */
    protected function existingMailboxRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! DB::table('mail_user')->where('email', (string) $value)->exists()) {
                $fail('The destination must be the email address of an existing mailbox.');
            }
        };
    }
}
