<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailTransport;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Shared behavior for /mail/transports writes (contract:
 * api/modules/mail/transports.yaml; legacy:
 * source_code/interface/web/mail/form/mail_transport.tform.php +
 * mail_transport_edit.php):
 *
 *  - domain IDN + lowercase, unique per server (DB unique key -> 409 in the
 *    controller), not an existing local mail domain
 *    (validate_isnot_maildomain);
 *  - transport is the raw Postfix transport string;
 *  - sort_order 0-10, default 5 (C-5);
 *  - server_id immutable on update (legacy reverts changes).
 */
abstract class MailTransportRequest extends FormRequest
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
    protected function currentTransport(): ?MailTransport
    {
        $record = $this->route('mailTransport');

        return $record instanceof MailTransport ? $record : null;
    }

    /**
     * Legacy validate_isnot_maildomain: the transport domain must not be a
     * local mail domain.
     */
    protected function notMailDomainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $exists = DB::table('mail_domain')
                ->where('domain', (string) $value)
                ->exists();

            if ($exists) {
                $fail('The domain is a local mail domain; a transport for it is not allowed.');
            }
        };
    }
}
